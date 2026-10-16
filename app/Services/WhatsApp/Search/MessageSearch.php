<?php

namespace App\Services\WhatsApp\Search;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * البحث في نص الرسائل عبر المحادثات (E30.3). wa_messages هو المصدر الوحيد: Cloud API لا يقرأ الرسائل ولا يبحث فيها.
 *
 * السياج قبل الحد والإزاحة: الرسائل من WaConversation::visibleTo وحدها (محادثات الإدارة لمن يملك
 * whatsapp.admin_chats، ثم الاختصاص والتوكيل لمن لا يرى الكل)، بلا المدموجة، فلا يكشف total ولا الصفحات ما خارج النطاق.
 * المحرّك: FTS5 (trigram) إن توفّر الفهرس، وإلا LIKE على body_norm (أبطأ، engine=like). لا هاتف في النتائج.
 */
final class MessageSearch
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const MAX_OFFSET = 500;

    /** total حتى 1000 ثم «+1000» */
    public const TOTAL_CAP = 1000;

    /**
     * العلم message_search للمدراء (من يرى كل المحادثات) أولاً، ثم message_search_all لكل من يملك whatsapp.view.
     * السياج يحصر النتائج في الحالتين.
     */
    public static function enabledFor(User $user): bool
    {
        return $user->can('whatsapp.view') && FeatureFlags::on('message_search')
            && ($user->can('whatsapp.view_all') || FeatureFlags::on('message_search_all'));
    }

    /**
     * @param  array<string, mixed>  $f  q و conversation_id وفلاتر MessageFilters و sort و limit و offset (بعد التحقق)
     * @return array{hits: list<array>, total: int, total_capped: bool, took_ms: int, engine: string, sort: string, q_fp: string, q_len: int}
     */
    public function search(User $user, array $f): array
    {
        $started = hrtime(true);
        $conversationId = isset($f['conversation_id']) ? (int) $f['conversation_id'] : null;
        $query = SearchQuery::parse((string) $f['q'], $conversationId !== null);

        $visible = fn () => WaConversation::query()->visibleTo($user)->where('wa_conversations.status', '!=', 'merged');
        if ($conversationId !== null) {
            abort_unless($visible()->whereKey($conversationId)->exists(), 404);
        }

        $engine = $query->match() !== null && FtsSchema::available() ? 'fts' : 'like';
        // QueryException تحمل SQL بقيمه، أي نص البحث: تُستبدل قبل أن تصل السجل
        $attempt = function (string $engine) use ($f, $query, $visible, $conversationId) {
            try {
                return $this->run($f, $query, $visible, $conversationId, $engine);
            } catch (QueryException $e) {
                throw new SearchFailed("message search failed ({$engine}, sqlstate {$e->getCode()})");
            }
        };
        try {
            $result = $attempt($engine);
        } catch (SearchFailed $e) {
            if ($engine !== 'fts') {
                throw $e;
            }
            // فهرس تالف أو ناقص: هذا البحث بـ LIKE، وwhatsapp:fts-check يصلحه
            WaLog::warning('wa.search.fts_failed', ['reason' => 'QueryException']);
            $result = $attempt($engine = 'like');
        }

        return $result + [
            'took_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'engine' => $engine,
            'q_fp' => $query->fingerprint(),
            'q_len' => $query->length(),
        ];
    }

    private function run(array $f, SearchQuery $query, \Closure $visible, ?int $conversationId, string $engine): array
    {
        $limit = min(max((int) ($f['limit'] ?? self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $offset = min(max((int) ($f['offset'] ?? 0), 0), self::MAX_OFFSET);

        $base = DB::table('wa_messages')
            ->whereIn('wa_messages.conversation_id', $visible()->select('wa_conversations.id'))
            // التفاعل (👍) ليس نصاً يُبحث فيه
            ->where('wa_messages.msg_type', '!=', 'reaction')
            ->when($conversationId !== null, fn ($q) => $q->where('wa_messages.conversation_id', $conversationId));
        if ($engine === 'fts') {
            $base->join(FtsSchema::TABLE, FtsSchema::TABLE.'.rowid', '=', 'wa_messages.id')
                ->whereRaw(FtsSchema::TABLE.' MATCH ?', [$query->match()]);
        }
        foreach ($query->likeTerms($engine === 'fts') as $term) {
            $base->whereRaw("wa_messages.body_norm LIKE ? ESCAPE '\\'", ['%'.addcslashes($term, '%_\\').'%']);
        }
        MessageFilters::apply($base, $f, 'wa_messages');

        $total = DB::query()->fromSub((clone $base)->select('wa_messages.id')->limit(self::TOTAL_CAP + 1), 'hits')->count();
        $sort = ($f['sort'] ?? 'recent') === 'relevance' && $engine === 'fts' ? 'relevance' : 'recent';
        $rows = (clone $base)
            ->select(['wa_messages.id', 'wa_messages.conversation_id', 'wa_messages.direction', 'wa_messages.source',
                'wa_messages.msg_type', 'wa_messages.body', 'wa_messages.created_at'])
            ->when($sort === 'relevance', fn ($q) => $q->orderByRaw('bm25('.FtsSchema::TABLE.')'))
            ->orderByDesc('wa_messages.id')
            ->offset($offset)->limit($limit)->get();

        // الاسم بلا الهاتف (noticeLabel)، نص غير موثوق تهرّبه الواجهة
        $names = WaConversation::with('client:id,name')->whereIn('id', $rows->pluck('conversation_id')->unique()->values())->get()
            ->mapWithKeys(fn (WaConversation $c) => [$c->id => $c->noticeLabel()]);

        return [
            'hits' => $rows->map(fn ($r) => [
                'message_id' => (int) $r->id,
                'conversation_id' => (int) $r->conversation_id,
                'conversation_name' => $names[$r->conversation_id] ?? 'محادثة #'.$r->conversation_id,
                // صيغة الخيط نفسها، فيرى الموظف الوقت ذاته بعد القفز
                'at' => $r->created_at ? Carbon::parse($r->created_at)->format('Y-m-d H:i') : null,
                'direction' => $r->direction,
                'source' => $r->source,
                'msg_type' => $r->msg_type,
                'segments' => Snippet::segments((string) $r->body, $query->highlight),
            ])->values()->all(),
            'total' => min($total, self::TOTAL_CAP),
            'total_capped' => $total > self::TOTAL_CAP,
            'sort' => $sort,
        ];
    }
}

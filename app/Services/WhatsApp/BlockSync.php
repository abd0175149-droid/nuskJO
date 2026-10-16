<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Support\Retention;
use Illuminate\Support\Facades\Cache;

/**
 * المزامنة القرائية مع قائمة الحظر عند ميتا (E31): GET block_users بالصفحات، ثم مقارنة بالحظر القائم عندنا.
 * لا تغيّر أي حظر عند ميتا: لا POST ولا DELETE هنا أبداً. تكتب meta_state وmeta_checked_at عندنا وحدها،
 * وكل إصلاح (إعادة الحظر، الفك، تسجيل سبب) بزر من مدير.
 *
 * النتيجة: not_found (محظور عندنا وغير موجود عند ميتا)، وuntracked (محظور عند ميتا بلا سجل عندنا: يُعرض صفاً
 * مؤقتاً بآخر 4 أرقام ومرجع، والرقم نفسه يبقى في الخادم)، وmismatch (وصلت رسالة من محظور قبل المزامنة).
 */
final class BlockSync
{
    /** القائمة 64 ألفاً حداً أقصى عند ميتا؛ بعد السقف تُعلَّم النتيجة جزئية ولا يُحكم بـ not_found */
    public const MAX_PAGES = 70;

    public const SUMMARY_KEY = 'wa:blocks:sync:last';

    public const UNTRACKED_KEY = 'wa:blocks:sync:untracked';

    /**
     * @return array{ok: bool, code?: ?string, error?: ?string, at?: string, meta?: int, local?: int, truncated?: bool,
     *               not_found?: list<array>, untracked?: list<array>, mismatch?: list<array>}
     */
    public static function run(string $trigger = 'manual'): array
    {
        if (!BlockService::available()) {
            return ['ok' => false, 'code' => 'BLOCK_UNAVAILABLE', 'error' => 'الحظر غير متاح قبل تشغيل الهجرة.'];
        }

        // ① قائمة ميتا كاملة (أو حتى السقف)
        $entries = [];
        $after = null;
        $pages = 0;
        do {
            $r = WhatsAppChannel::listBlocked($after);
            if (!$r['ok']) {
                return ['ok' => false, 'code' => $r['code'], 'error' => $r['error']];
            }
            foreach ($r['data'] as $e) {
                $entries[] = $e;
            }
            $after = $r['next_after'];
            $pages++;
        } while ($after !== null && $pages < self::MAX_PAGES);
        $truncated = $after !== null;

        // ② فهرس المفاتيح: الرقم الموحّد، والمعرّف، وبصمتاهما (لصفوف جُرّدت بعد «حذف بياناتي»)
        $index = [];
        foreach ($entries as $i => $e) {
            if (!empty($e['phone'])) {
                $canonical = PhoneNormalizer::canonical($e['phone']) ?: $e['phone'];
                $index['p:'.$canonical] = $i;
                $index['h:'.Retention::subjectHmac($canonical)] = $i;
            }
            if (!empty($e['user_id'])) {
                $index['u:'.$e['user_id']] = $i;
                $index['h:'.Retention::userIdHmac($e['user_id'])] = $i;
            }
        }

        // ③ الحظر القائم عندنا
        $seen = [];
        $notFound = [];
        $mismatch = [];
        $local = WaBlock::active()->with('conversation')->orderBy('id')->get();
        foreach ($local as $b) {
            $i = match (true) {
                filled($b->phone) && isset($index['p:'.(PhoneNormalizer::canonical($b->phone) ?: $b->phone)]) => $index['p:'.(PhoneNormalizer::canonical($b->phone) ?: $b->phone)],
                filled($b->wa_user_id) && isset($index['u:'.$b->wa_user_id]) => $index['u:'.$b->wa_user_id],
                filled($b->subject_hash) && isset($index['h:'.$b->subject_hash]) => $index['h:'.$b->subject_hash],
                default => null,
            };
            $before = $b->meta_state;
            if ($i !== null) {
                $seen[$i] = true;
                $state = 'blocked';
            } else {
                // قائمة جزئية: لا يُحكم بغياب ما ربما في الصفحات التي لم تُقرأ
                $state = $truncated ? $before : 'not_found';
            }
            $b->forceFill(['meta_state' => $state, 'meta_checked_at' => now()])->save();
            if ($state === 'not_found') {
                $notFound[] = self::localRow($b);
            }
            if ($before === 'mismatch') {
                $mismatch[] = self::localRow($b) + ['now' => $state];
            }
        }

        // ④ عند ميتا بلا سجل عندنا: صفوف مؤقتة بمرجع، والرقم يبقى في الخادم
        $untracked = [];
        $refs = [];
        foreach ($entries as $i => $e) {
            if (isset($seen[$i])) {
                continue;
            }
            $ref = substr(hash_hmac('sha256', json_encode($e), (string) config('app.key')), 0, 24);
            $refs[$ref] = $e;
            $untracked[] = self::untrackedRow($ref, $e);
        }
        Cache::put(self::UNTRACKED_KEY, $refs, now()->addHours(2));

        $summary = [
            'at' => now()->toIso8601String(), 'trigger' => $trigger, 'meta' => count($entries), 'local' => $local->count(),
            'not_found' => count($notFound), 'untracked' => count($untracked), 'mismatch' => count($mismatch), 'truncated' => $truncated,
        ];
        Cache::forever(self::SUMMARY_KEY, $summary);
        AuditLog::safe(AuditAction::WaBlockSync, 'system', null, null, array_diff_key($summary, ['at' => 1]));
        WaLog::info('wa.block.sync', ['count' => count($entries), 'status' => $truncated ? 'truncated' : 'complete']);

        // القوائم تحل محل أعدادها في الملخص المحفوظ (+ يُبقي القيمة اليسرى)
        return ['ok' => true, 'not_found' => $notFound, 'untracked' => $untracked, 'mismatch' => $mismatch] + $summary;
    }

    /** آخر مزامنة (الأعداد وحدها) لبطاقة الصفحة */
    public static function lastSummary(): ?array
    {
        $s = Cache::get(self::SUMMARY_KEY);

        return is_array($s) ? $s : null;
    }

    /**
     * صف مؤقت من آخر مزامنة بمرجعه (للتسجيل أو الفك). لا يُقبل رقم من المتصفح.
     *
     * @return array{phone?: string, user_id?: string}|null
     */
    public static function untracked(string $ref): ?array
    {
        $refs = Cache::get(self::UNTRACKED_KEY, []);

        return is_array($refs) && isset($refs[$ref]) && is_array($refs[$ref]) ? $refs[$ref] : null;
    }

    /** يُزال من القائمة المؤقتة بعد تسجيله أو فكّه */
    public static function forgetUntracked(string $ref): void
    {
        $refs = Cache::get(self::UNTRACKED_KEY, []);
        if (is_array($refs) && isset($refs[$ref])) {
            unset($refs[$ref]);
            Cache::put(self::UNTRACKED_KEY, $refs, now()->addHours(2));
        }
    }

    private static function localRow(WaBlock $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->conversation && !$b->conversation->erased_at ? $b->conversation->noticeLabel() : null,
            'last4' => $b->last4(),
            'conversation_id' => $b->conversation && !$b->conversation->erased_at ? $b->conversation_id : null,
        ];
    }

    private static function untrackedRow(string $ref, array $e): array
    {
        $phone = isset($e['phone']) ? (PhoneNormalizer::canonical($e['phone']) ?: $e['phone']) : null;
        $conv = WaConversation::query()->whereNull('erased_at')->where('status', '!=', 'merged')
            ->where(fn ($q) => $q->when($phone, fn ($w) => $w->orWhere('phone', $phone))->when($e['user_id'] ?? null, fn ($w, $uid) => $w->orWhere('wa_user_id', $uid)))
            ->orderByDesc('id')->first();

        return [
            'ref' => $ref,
            'last4' => $phone && strlen($phone) >= 4 ? substr($phone, -4) : null,
            'kind' => $phone ? 'phone' : 'bsuid',
            'name' => $conv?->noticeLabel(),
            'conversation_id' => $conv?->id,
        ];
    }
}

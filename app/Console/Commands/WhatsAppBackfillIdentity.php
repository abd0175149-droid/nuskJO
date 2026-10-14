<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\WaLog;
use App\Support\AuditActor;
use App\Support\Csv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * تعبئة أعمدة E11 للبيانات القديمة من الحمولة المحفوظة (E11.2)، على دفعات وبأمان عند إعادة التشغيل (يملأ الفارغ وحده):
 * المعرّف حين جاء الرقم والمعرّف في حمولة واحدة، ولقب واتساب، وحالة الربط، والاقتباس والإحالة والوسائط ونص البحث.
 * phone_number_id للرسائل القديمة يبقى فارغاً عمداً: فلتر رقمنا أُضيف متأخراً. لا يفك أي ربط: الروابط القديمة
 * التي يخالفها المطابق الصارم تُكتب في تقرير (رقم المحادثة وكود العميل والسبب، بلا أرقام) للمراجعة اليدوية.
 */
class WhatsAppBackfillIdentity extends Command
{
    protected $signature = 'whatsapp:backfill-identity {--dry-run : يعدّ ما سيُكتب ولا يكتب شيئاً}';

    protected $description = 'تعبئة هوية المحادثات القديمة وأعمدة الرسائل الجديدة من الحمولة المحفوظة';

    private const CHUNK = 500;

    private const MEDIA = ['image', 'video', 'document', 'audio', 'sticker'];

    private const COUNTS = [
        'user_ids' => 0, 'user_id_conflicts' => 0, 'profile_names' => 0, 'client_links' => 0,
        'conv_referrals' => 0, 'messages' => 0, 'link_review' => 0,
    ];

    private array $counts = self::COUNTS;

    /** معرّفات أُسندت في هذا التشغيل (التجربة لا تكتب فلا تراها القاعدة) */
    private array $assigned = [];

    private bool $dry = false;

    public function handle(): int
    {
        // نسخة الأمر نفسها تُعاد في العملية الواحدة (Artisan::call)
        [$this->dry, $this->counts, $this->assigned] = [(bool) $this->option('dry-run'), self::COUNTS, []];

        $this->conversations();
        $this->messages();
        [$rows, $path] = $this->linkReview();

        $this->table(['البند', 'العدد'], collect($this->counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        if ($this->dry) {
            $this->warn('تجربة: لم يُكتب شيء. تقرير المراجعة سيضم '.count($rows).' محادثة.');

            return self::SUCCESS;
        }

        AuditActor::as('system', 'artisan:whatsapp:backfill-identity', fn () => AuditLog::log(
            AuditAction::WaBackfillIdentity, 'wa_conversation', null, 'backfill', $this->counts,
        ));
        WaLog::info('identity.backfill', ['status' => 'done', 'count' => $this->counts['user_ids']]);
        $this->info('✅ اكتملت التعبئة. تقرير المراجعة: '.$path);

        return self::SUCCESS;
    }

    private function conversations(): void
    {
        DB::table('wa_conversations')
            ->select(['id', 'phone', 'wa_user_id', 'display_name', 'wa_profile_name', 'client_id', 'client_link', 'referral', 'status'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) {
                foreach ($chunk as $conv) {
                    if ($conv->status === 'merged') {
                        continue;
                    }
                    $update = [];
                    if ($conv->wa_user_id === null && $conv->phone !== null && ($userId = $this->userIdFor($conv)) !== null) {
                        $update['wa_user_id'] = $userId;
                    }
                    if ($conv->client_id === null && $conv->wa_profile_name === null && filled($conv->display_name)) {
                        $update['wa_profile_name'] = TextNormalizer::untrustedName($conv->display_name, 120);
                        $this->counts['profile_names']++;
                    }
                    if ($conv->client_id !== null && $conv->client_link === null) {
                        $update['client_link'] = 'auto';
                        $this->counts['client_links']++;
                    }
                    if ($conv->referral === null && ($referral = $this->firstReferral($conv->id)) !== null) {
                        $update['referral'] = $referral;
                        $this->counts['conv_referrals']++;
                    }
                    if ($update !== [] && !$this->dry) {
                        DB::table('wa_conversations')->where('id', $conv->id)->update($update);
                    }
                }
            });
    }

    /** آخر معرّف وصل مع رقم المحادثة نفسه في حمولة واحدة؛ محجوز لمحادثة أخرى = تعارض لا يُطبَّق */
    private function userIdFor(object $conv): ?string
    {
        $canonical = PhoneNormalizer::canonical($conv->phone);
        $rows = DB::table('wa_messages')->where('conversation_id', $conv->id)->where('direction', 'in')
            ->where('payload', 'like', '%from_user_id%')->orderByDesc('id')->limit(50)->pluck('payload');

        foreach ($rows as $raw) {
            $payload = json_decode((string) $raw, true);
            $userId = data_get($payload, 'from_user_id');
            $from = data_get($payload, 'from');
            if (!is_string($userId) || !preg_match(InboundMessage::BSUID, $userId) || str_contains($userId, '.ENT.')
                || !is_string($from) || $canonical === '' || PhoneNormalizer::canonical($from) !== $canonical) {
                continue;
            }
            $taken = isset($this->assigned[$userId])
                || DB::table('wa_conversations')->where('wa_user_id', $userId)->where('id', '!=', $conv->id)->exists();
            if ($taken) {
                $this->counts['user_id_conflicts']++;
                WaLog::warning('identity.backfill.conflict', ['conv_id' => $conv->id]);

                return null;
            }
            $this->assigned[$userId] = true;
            $this->counts['user_ids']++;

            return $userId;
        }

        return null;
    }

    private function firstReferral(int $conversationId): ?string
    {
        $raw = DB::table('wa_messages')->where('conversation_id', $conversationId)->where('direction', 'in')
            ->where('payload', 'like', '%"referral"%')->orderBy('id')->value('payload');
        $referral = data_get(json_decode((string) $raw, true), 'referral');

        return is_array($referral) ? json_encode($referral, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function messages(): void
    {
        DB::table('wa_messages')
            ->select(['id', 'direction', 'msg_type', 'body', 'payload', 'reply_to_wamid', 'referral', 'media_id', 'body_norm'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) {
                foreach ($chunk as $m) {
                    $payload = $m->direction === 'in' ? (json_decode((string) $m->payload, true) ?: []) : [];
                    $update = [];
                    if ($m->body_norm === null && filled($m->body)) {
                        $update['body_norm'] = TextNormalizer::forSearch($m->body);
                    }
                    if ($m->reply_to_wamid === null && is_string($context = data_get($payload, 'context.id')) && $context !== '') {
                        $update['reply_to_wamid'] = mb_substr($context, 0, 128);
                    }
                    if ($m->referral === null && is_array($referral = data_get($payload, 'referral'))) {
                        $update['referral'] = json_encode($referral, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    if ($m->media_id === null && in_array($m->msg_type, self::MEDIA, true) && is_array($node = data_get($payload, $m->msg_type))) {
                        $update += array_filter([
                            'media_id' => self::str($node['id'] ?? null, 128),
                            'media_mime' => self::str($node['mime_type'] ?? null, 100),
                            'media_sha256' => self::str($node['sha256'] ?? null, 100),
                            'media_filename' => TextNormalizer::untrustedName($node['filename'] ?? null, 255),
                        ], fn ($v) => $v !== null);
                    }
                    if ($update !== []) {
                        $this->counts['messages']++;
                        $this->dry || DB::table('wa_messages')->where('id', $m->id)->update($update);
                    }
                }
            });
    }

    /**
     * كل ربط آلي قديم يخالف المطابق الصارم (الرقم الكامل، ولا ربط عند الالتباس). في التجربة تُحسب المحادثات
     * المربوطة بلا حالة أيضاً (ستصير auto). أسباب: no_phone · no_strict_match · ambiguous · different_client · client_deleted
     *
     * @return array{0: list<array{0:int,1:?string,2:string}>, 1: ?string}
     */
    private function linkReview(): array
    {
        $byCanonical = [];
        foreach (DB::table('clients')->whereNull('deleted_at')->whereNotNull('phone')->where('phone', '!=', '')->get(['id', 'phone']) as $c) {
            if (PhoneNormalizer::autoLinkable($c->phone) && ($key = PhoneNormalizer::canonical($c->phone)) !== '') {
                $byCanonical[$key][] = (int) $c->id;
            }
        }

        $rows = [];
        DB::table('wa_conversations')->whereNotNull('client_id')->where('status', '!=', 'merged')
            ->where(fn ($q) => $this->dry ? $q->whereNull('client_link')->orWhere('client_link', 'auto') : $q->where('client_link', 'auto'))
            ->select(['id', 'phone', 'client_id'])->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($byCanonical, &$rows) {
                $clients = DB::table('clients')->whereIn('id', $chunk->pluck('client_id'))->get(['id', 'code', 'deleted_at'])->keyBy('id');
                foreach ($chunk as $conv) {
                    $client = $clients->get($conv->client_id);
                    $matches = $byCanonical[PhoneNormalizer::canonical($conv->phone)] ?? [];
                    $reason = match (true) {
                        $client === null || $client->deleted_at !== null => 'client_deleted',
                        $conv->phone === null => 'no_phone',
                        count($matches) > 1 => 'ambiguous',
                        $matches === [] => 'no_strict_match',
                        $matches[0] !== (int) $conv->client_id => 'different_client',
                        default => null,
                    };
                    if ($reason !== null) {
                        $rows[] = [(int) $conv->id, $client?->code, $reason];
                    }
                }
            });
        $this->counts['link_review'] = count($rows);

        if ($this->dry) {
            return [$rows, null];
        }
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/wa-link-review-'.now()->toDateString().'.csv';
        $h = fopen($path, 'w');
        Csv::bom($h);
        Csv::put($h, ['conv_id', 'client_code', 'reason']);
        foreach ($rows as $row) {
            Csv::put($h, $row);
        }
        fclose($h);

        return [$rows, $path];
    }

    private static function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}

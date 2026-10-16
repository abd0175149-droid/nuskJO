<?php

namespace App\Services\Ops;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\OutboundCounter;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Support\FeatureFlags;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مركز «الإخفاقات والطابور» (E24): قراءة فقط من مصادر قائمة، بلا خط تسجيل جديد. كل قسم آخر 7 أيام بحد 200 صف:
 *
 * (أ) الصادر الفاشل وغير المؤكد (E18/E19) ضمن نطاق المستخدم (visibleTo، E04): رقم مقنَّع، ومقتطف 60 حرفاً للنص وحده،
 *     وسبب ميتا العربي وإجراؤه من الجدول الوحيد، وهل تصح الإعادة. «قد تكون وصلت» بلا زر.
 * (ب) failed_jobs لآخر 24 ساعة: الصنف والطابور ورقم المحادثة (إن كانت في النطاق) وأول سطر من الاستثناء منقّحاً. لا payload.
 * (ج) أحداث الويبهوك failed وdead وmalformed (E20) بالوقت والخطأ المختصر والحقول والبصمة، والمرفوضة بالأعداد وحدها.
 * (د) آخر لقطة من whatsapp:ops-check.
 */
final class FailuresCenter
{
    public const DAYS = 7;

    public const LIMIT = 200;

    public const SNIPPET = 60;

    public const TZ = 'Asia/Amman';

    public static function payload(User $user): array
    {
        return [
            'messages' => self::messages($user),
            'jobs' => self::failedJobs($user),
            'webhooks' => self::webhooks(),
            'queue' => self::queue(),
            'redrive' => FeatureFlags::on('failures_redrive'),
            'generated_at' => now(self::TZ)->format('Y-m-d H:i'),
        ];
    }

    /** @return list<array> */
    public static function messages(User $user): array
    {
        $rows = WaMessage::query()
            ->where('direction', 'out')
            ->whereIn('status', [WaMessage::STATUS_FAILED, WaMessage::STATUS_UNKNOWN, 'unconfirmed'])
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->whereHas('conversation', fn ($q) => $q->visibleTo($user))
            ->with('conversation:id,phone,display_name,wa_profile_name,wa_username,client_id,last_inbound_at')
            ->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'conversation_id', 'source', 'msg_type', 'body', 'payload', 'status', 'meta_code', 'error_code', 'error_category', 'attempts', 'created_at']);

        return $rows->map(fn (WaMessage $m) => self::presentMessage($m))->all();
    }

    public static function presentMessage(WaMessage $m): array
    {
        $payload = (array) ($m->payload ?? []);
        $entry = $m->meta_code !== null ? MetaErrorCatalog::classify((int) $m->meta_code) : MetaErrorCatalog::byCode($m->error_code);
        $category = $m->error_category ?: ($entry['category'] ?? 'unknown');
        $maybeDelivered = in_array($m->status, [WaMessage::STATUS_UNKNOWN, 'unconfirmed'], true) || !empty($payload['_reaped']);
        $windowOpen = (bool) $m->conversation?->isWindowOpen();
        $redrivable = $m->status === WaMessage::STATUS_FAILED && !$maybeDelivered && !empty($payload['_released_key'])
            && is_string($payload['type'] ?? null) && isset($payload[$payload['type']])
            && MetaErrorCatalog::redrivable($m->meta_code !== null ? (int) $m->meta_code : null, $m->error_code, $m->error_category);

        return [
            'id' => $m->id,
            'at' => $m->created_at?->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'),
            'conversation_id' => $m->conversation_id,
            'conversation' => self::conversationLabel($m->conversation),
            'source' => $m->source,
            'status' => $m->status,
            'msg_type' => $m->msg_type,
            // النص وحده، أول 60 حرفاً لتمييز الرسالة؛ غير النصي بنوعه فقط
            'snippet' => $m->msg_type === 'text' ? mb_substr((string) $m->body, 0, self::SNIPPET) : null,
            'meta_code' => $m->meta_code !== null ? (int) $m->meta_code : null,
            'error_code' => $m->error_code,
            'category' => $category,
            'staff_message' => $maybeDelivered ? 'قد تكون وصلت — تحقق من المحادثة قبل أي إعادة.' : ($entry['message'] ?? null),
            'action' => $entry['action'] ?? null,
            'attempts' => (int) $m->attempts,
            'redrives' => (int) data_get($payload, '_redrive.n', 0),
            'window_open' => $windowOpen,
            'maybe_delivered' => $maybeDelivered,
            'can_redrive' => $redrivable && $windowOpen && FeatureFlags::on('failures_redrive'),
        ];
    }

    /** الاسم إن وُجد، وإلا الرقم مقنَّعاً (9627•••4567)، وإلا رقم المحادثة */
    public static function conversationLabel(?WaConversation $c): string
    {
        if ($c === null) {
            return '—';
        }
        $name = $c->display_name ?: $c->wa_profile_name ?: ($c->wa_username ? '@'.$c->wa_username : null);
        if ($name) {
            return mb_substr((string) $name, 0, 60);
        }

        return self::maskPhone($c->phone) ?? 'محادثة #'.$c->id;
    }

    public static function maskPhone(?string $phone): ?string
    {
        $d = PhoneNormalizer::digits((string) $phone);
        if ($d === '') {
            return null;
        }

        return strlen($d) >= 9 ? substr($d, 0, 4).'•••'.substr($d, -4) : '•••'.substr($d, -3);
    }

    /** @return list<array> */
    public static function failedJobs(User $user): array
    {
        $retryable = (array) config('ops.retryable_jobs', []);
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

        $parsed = $rows->map(function ($r) {
            $payload = json_decode((string) $r->payload, true) ?: [];
            $command = (string) data_get($payload, 'data.command', '');

            return [
                'row' => $r,
                'class' => (string) ($payload['displayName'] ?? ''),
                // من النص المسلسل مباشرة بلا unserialize (كما OpsAlerts::jobFailed)
                'conversation_id' => preg_match('/"conversationId";i:(\d+);/', $command, $m) ? (int) $m[1] : null,
            ];
        });
        $ids = $parsed->pluck('conversation_id')->filter()->unique()->values();
        $visible = $ids->isEmpty() ? [] : WaConversation::query()->visibleTo($user)->whereIn('wa_conversations.id', $ids)->pluck('wa_conversations.id')->flip()->all();

        return $parsed->map(fn ($p) => [
            'uuid' => $p['row']->uuid,
            'at' => Carbon::parse($p['row']->failed_at, 'UTC')->setTimezone(self::TZ)->format('Y-m-d H:i'),
            'job' => class_basename($p['class']) ?: '—',
            'queue' => $p['row']->queue,
            'conversation_id' => $p['conversation_id'] !== null && isset($visible[$p['conversation_id']]) ? $p['conversation_id'] : null,
            'error' => self::cleanException((string) $p['row']->exception),
            'retryable' => in_array($p['class'], $retryable, true),
            // ردود البوت لا تُعاد بـ queue:retry (رمز الدمج انتهى، والرد قديم): «افتح المحادثة» وزر E17
            'reply_job' => $p['class'] === ProcessWhatsAppReply::class,
        ])->values()->all();
    }

    /**
     * أول سطر من الاستثناء: الأسرار (SecretRedactor) ثم أي مفتاح=قيمة سري، ثم كل تسلسل أرقام من 7 فأكثر بـ •••،
     * مقصوصاً إلى 200 حرف. لا trim بقائمة محارف (يتلف العربية).
     */
    public static function cleanException(string $exception): string
    {
        $first = strtok(str_replace("\r", '', $exception), "\n") ?: '';
        $clean = SecretRedactor::clean($first);
        $clean = preg_replace('/\b((?:api[_-]?)?key|token|secret|password|access_token|appsecret_proof|signature)\s*[=:]\s*[^\s&"\',;]+/i', '$1=***', $clean) ?? $clean;
        $clean = preg_replace('/\+?\d[\d\s\-]{5,}\d/u', '•••', $clean) ?? $clean;

        return mb_substr($clean, 0, 200);
    }

    /** @return array{rows: list<array>, rejected: array<string, int>} */
    public static function webhooks(): array
    {
        if (!Schema::hasTable('wa_webhook_events')) {
            return ['rows' => [], 'rejected' => []];
        }
        $since = now()->subDays(self::DAYS);
        $max = (int) config('whatsapp.webhook.max_attempts', 3);
        $rows = WaWebhookEvent::query()->whereIn('status', [WaWebhookEvent::FAILED, WaWebhookEvent::DEAD, WaWebhookEvent::MALFORMED])
            ->where('received_at', '>=', $since)->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'received_at', 'status', 'attempts', 'next_attempt_at', 'field_summary', 'error', 'body_sha256',
                DB::raw('raw_body IS NOT NULL as has_body')]);

        return [
            'rows' => $rows->map(fn (WaWebhookEvent $e) => [
                'id' => $e->id,
                'at' => $e->received_at?->copy()->setTimezone(self::TZ)->format('Y-m-d H:i'),
                'status' => $e->status,
                'attempts' => (int) $e->attempts,
                'next_attempt_at' => $e->next_attempt_at?->copy()->setTimezone(self::TZ)->format('H:i'),
                'fields' => $e->field_summary,
                'error' => $e->error !== null ? mb_substr(self::cleanException((string) $e->error), 0, 120) : null,
                'sha' => substr((string) $e->body_sha256, 0, 12),
                'can_replay' => (bool) $e->has_body && in_array($e->status, [WaWebhookEvent::FAILED, WaWebhookEvent::DEAD], true),
                // متوقف أو استنفد محاولاته: --force وتأكيد ثانٍ
                'needs_force' => $e->status === WaWebhookEvent::DEAD || (int) $e->attempts >= $max,
            ])->all(),
            'rejected' => WaWebhookEvent::query()->where('status', WaWebhookEvent::REJECTED)->where('received_at', '>=', $since)
                ->groupBy('reject_reason')->selectRaw("coalesce(reject_reason, 'unknown') as reason, COUNT(*) as n")
                ->pluck('n', 'reason')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** آخر لقطة من whatsapp:ops-check، والعمر بالدقائق، والصادر الآلي بميزانياته */
    public static function queue(): array
    {
        $snap = OpsMetrics::last();

        return [
            'snapshot' => $snap,
            'age_minutes' => $snap ? (int) floor(max(0, now()->getTimestamp() - (int) ($snap['at'] ?? 0)) / 60) : null,
            'automated' => $snap ? OutboundCounter::automatedWithBudgets((array) ($snap['automated_today'] ?? [])) : [],
            'free_tier' => (int) config('ops.service_free_tier', 1000),
        ];
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaWebhookEvent;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Crypt;

/**
 * «احفظ الجسم مشفّراً، ثم عالج، ثم امحُ» (E20). المتحكم وأمر الإعادة يمرّان من هنا وحده.
 * لا يُطبع الجسم ولا جزء منه في أي سجل، ولا يُعرض في أي مسار.
 */
final class WebhookIntake
{
    /** حالات يعني فيها تكرار الجسم نفسه أنه حُسم: الرد 200 بلا معالجة ثانية */
    private const SETTLED = [WaWebhookEvent::PROCESSED, WaWebhookEvent::REJECTED, WaWebhookEvent::MALFORMED, WaWebhookEvent::DEAD];

    public static function storesAll(): bool
    {
        return FeatureFlags::on('inbound_event_log') && config('whatsapp.webhook.log_mode') === 'all';
    }

    public static function storesFailures(): bool
    {
        return FeatureFlags::on('inbound_event_log') && config('whatsapp.webhook.log_mode') === 'failures';
    }

    /**
     * الحفظ قبل الإقرار: عبارة قصيرة خارج أي معاملة. يرمي QueryException/PDOException إن تعذّر (503 عند المتحكم).
     *
     * @return array{0: WaWebhookEvent, 1: bool} الصف، وهل حُسم سابقاً (إعادة من ميتا)
     */
    public static function store(string $raw, string $status = WaWebhookEvent::PENDING): array
    {
        $sha = hash('sha256', $raw);
        $inserted = WaWebhookEvent::query()->insertOrIgnore([
            'body_sha256' => $sha,
            'raw_body' => Crypt::encryptString(self::redactForStorage($raw)),
            'status' => $status,
            'attempts' => 0,
            'received_at' => now(),
            'purge_after' => now()->addHours((int) config('whatsapp.webhook.body_hours', 72)),
        ]);
        $event = WaWebhookEvent::where('body_sha256', $sha)->firstOrFail();

        return [$event, $inserted === 0 && in_array($event->status, self::SETTLED, true)];
    }

    /**
     * ما لا نحتاجه في أي معالجة لا يُحفظ ولو مؤقتاً (E39): value.calls[*].session، أي عرض SDP بعناوين IP.
     * التوقيع والبصمة على الجسم الخام قبل التنقية، فإعادة ميتا تطابق صفها. جسم بلا هذا الحقل يُحفظ كما هو بايتاً ببايت،
     * والمعطوب (ليس JSON) كما هو للتشخيص ولا يُعاد أبداً.
     */
    public static function redactForStorage(string $raw): string
    {
        if (!str_contains($raw, '"calls"')) {
            return $raw;
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
        if (!is_array($payload)) {
            return $raw;
        }

        $changed = false;
        foreach ((array) ($payload['entry'] ?? []) as $e => $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $c => $change) {
                if (data_get($change, 'field') !== 'calls') {
                    continue;
                }
                foreach ((array) data_get($change, 'value.calls', []) as $i => $call) {
                    if (is_array($call) && array_key_exists('session', $call)) {
                        unset($payload['entry'][$e]['changes'][$c]['value']['calls'][$i]['session']);
                        $changed = true;
                    }
                }
            }
        }

        return $changed ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw;
    }

    /**
     * فك JSON ثم السياج ثم المعالجة المعزولة ثم الجدولة، وحسم الصف إن وُجد.
     *
     * @return ?WebhookResult null للحمولة المعطوبة (لا تُعاد: 200 لميتا)
     */
    public static function process(?WaWebhookEvent $event, string $raw): ?WebhookResult
    {
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \JsonException('not an object');
            }
        } catch (\JsonException) {
            WaLog::warning('webhook.malformed', ['bytes' => strlen($raw)]);
            // الجسم يبقى مشفّراً حتى purge_after للتشخيص، ولا يُعاد أبداً
            $event?->forceFill(['status' => WaWebhookEvent::MALFORMED, 'last_attempt_at' => now(), 'attempts' => $event->attempts + 1, 'error' => 'json_invalid'])->save();

            return null;
        }

        $fenced = WebhookFence::filter($payload);
        $result = WhatsAppInbox::handleWebhook($fenced['payload']);
        self::schedule($result);

        if ($event !== null) {
            self::finish($event, $result, $fenced);
        }

        return $result;
    }

    /** وضع failures: الجسم يُحفظ عند خطأ عنصر وحده، بالحالة failed للإعادة */
    public static function storeFailed(string $raw, WebhookResult $result): void
    {
        [$event, $settled] = self::store($raw, WaWebhookEvent::FAILED);
        if (!$settled) {
            self::finish($event, $result, ['emptied' => false, 'rejected' => []]);
        }
    }

    /** كل محادثة مستقلة: فشل جدولتها لا يُفشل الحمولة (الرسائل خُزّنت، والمُصالِح يلتقطها) */
    private static function schedule(WebhookResult $result): void
    {
        foreach ($result->touched() as $id) {
            try {
                ProcessWhatsAppReply::schedule($id);
            } catch (\Throwable $e) {
                $result->fail('schedule', $id, $e);
                WaLog::error('webhook.schedule_failed', ['conv_id' => $id, 'exception' => $e]);
            }
        }
    }

    /** فشل خارج العناصر المعزولة (قراءة الإعدادات، السياج…): محاولة فاشلة كاملة بميزانيتها */
    public static function failWhole(WaWebhookEvent $event, \Throwable $e): void
    {
        $result = new WebhookResult;
        $result->fail('payload', $event->id, $e);
        self::finish($event, $result, ['emptied' => false, 'rejected' => []]);
    }

    private static function finish(WaWebhookEvent $event, WebhookResult $result, array $fenced): void
    {
        $attempts = $event->attempts + 1;
        $reasons = array_values(array_unique(array_column($fenced['rejected'] ?? [], 'reason')));
        $base = [
            'attempts' => min($attempts, 255),
            'last_attempt_at' => now(),
            'field_summary' => $result->summary() ?: null,
            'reject_reason' => $reasons[0] ?? null,
        ];

        if ($fenced['emptied'] ?? false) {
            // بيانات جهة أخرى: لا يبقى منها إلا الهاش والسبب والأعداد
            $event->forceFill(['status' => WaWebhookEvent::REJECTED, 'raw_body' => null, 'processed_at' => now(),
                'item_errors' => null, 'next_attempt_at' => null, 'field_summary' => self::rejectedSummary($fenced['rejected'])] + $base)->save();

            return;
        }

        if (!$result->failed()) {
            $event->forceFill($base + ['status' => WaWebhookEvent::PROCESSED, 'raw_body' => null, 'processed_at' => now(),
                'item_errors' => $result->errors ?: null, 'next_attempt_at' => null, 'error' => null])->save();

            return;
        }

        $dead = $attempts >= (int) config('whatsapp.webhook.max_attempts', 3);
        $delays = (array) config('whatsapp.webhook.retry_minutes', [2, 10]);
        $first = collect($result->errors)->firstWhere(fn ($e) => $e['kind'] !== 'schedule');
        $event->forceFill($base + [
            'status' => $dead ? WaWebhookEvent::DEAD : WaWebhookEvent::FAILED,
            'item_errors' => array_slice($result->errors, 0, 50),
            'next_attempt_at' => $dead ? null : now()->addMinutes((int) ($delays[$attempts - 1] ?? end($delays))),
            'error' => $first ? mb_substr($first['kind'].': '.$first['error'], 0, 300) : null,
        ])->save();

        if ($dead) {
            self::dead();
        }
    }

    /** صف dead جديد: عدّاد الساعة، وإشعار مجمّع مرة في الساعة */
    public static function dead(): void
    {
        $store = OpsHealth::store();
        $key = 'wa:webhook:dead:'.now()->format('YmdH');
        $store->add($key, 0, now()->addHours(3));
        $n = (int) $store->increment($key);
        WaLog::error('webhook.dead', ['count' => $n]);
        app(OpsAlerts::class)->notifyAdmins('wa:webhook:dead', '⚠️ تعذّرت معالجة إشعارات واتساب',
            "{$n} حمولة لم تُعالج بعد ".(int) config('whatsapp.webhook.max_attempts', 3).' محاولات. راجع الصندوق يدوياً قبل مرور '
            .(int) config('whatsapp.webhook.body_hours', 72).' ساعة.');
    }

    private static function rejectedSummary(array $rejected): string
    {
        $out = [];
        foreach ($rejected as $r) {
            $out[$r['field']] = ($out[$r['field']] ?? 0) + 1;
        }

        return mb_substr(implode(',', array_map(fn ($f, $n) => "{$f}:{$n}", array_keys($out), $out)), 0, 160);
    }

    /**
     * توقيع مرفوض: يُعدّ وحده (اليوم والساعة)، وتنبيه مرة في الساعة إن بلغ عدّاد الساعة الحد.
     * الطلب الموقّع الصحيح لا يمس أي عدّاد.
     */
    public static function badSignature(): int
    {
        $store = OpsHealth::store();
        $day = 'wa:sig_fail:'.now()->format('Ymd');
        $hour = 'wa:sig_fail:h:'.now()->format('YmdH');
        $store->add($day, 0, now()->addDays(3));
        $store->increment($day);
        $store->add($hour, 0, now()->addHours(3));
        $n = (int) $store->increment($hour);

        if ($n >= (int) config('whatsapp.webhook.bad_signature_alert', 20)) {
            app(OpsAlerts::class)->notifyAdmins('wa:sig_fail', '⚠️ توقيعات ويبهوك مرفوضة كثيرة',
                'قد يكون App Secret في الإعدادات لا يطابق تطبيق ميتا بعد تدوير، أو محاولة تزوير. الرسائل الحقيقية ستعيدها ميتا حين يُصحَّح السر.');
        }

        return $n;
    }

    /** @return array{hour: int, day: int} للإعدادات وdoctor (آخر ساعة، واليوم مع أمس) */
    public static function badSignatureCounts(): array
    {
        $store = OpsHealth::store();

        return [
            'hour' => (int) $store->get('wa:sig_fail:h:'.now()->format('YmdH'), 0),
            'day' => (int) $store->get('wa:sig_fail:'.now()->format('Ymd'), 0) + (int) $store->get('wa:sig_fail:'.now()->subDay()->format('Ymd'), 0),
        ];
    }
}

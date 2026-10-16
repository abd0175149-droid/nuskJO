<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Services\Integrations\GuardRejection;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Integrations\OutboundUrlGuard;
use App\Services\Integrations\WebhookSigner;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\AuditActor;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * تسليم صف واحد من الصندوق الصادر (E40) على طابور integrations بعامل مستقل، فتسليم بطيء لا يؤخر رد البوت.
 * tries=1: الإعادة يديرها الصندوق (backoff وdead) لا الطابور. استيلاء ذري أولاً، فوظيفتان للصف نفسه ترسلان طلباً واحداً.
 * الحارس يُعاد عند كل تسليم ويُثبَّت العنوان المحلول، بلا تحويلات، ومهلة 10 ثوانٍ، و64KB حداً أقصى من الرد.
 * السر لا يظهر في last_error ولا في أي سطر سجل.
 */
class DeliverIntegrationWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $rowId)
    {
        $this->onQueue((string) config('integrations.queue', 'integrations'));
    }

    /** بعد الالتزام دائماً: حدث معاملة تراجعت لا يُرسل. $at للإعادة المؤجلة */
    public static function dispatchFor(int $rowId, ?Carbon $at = null): void
    {
        $pending = self::dispatch($rowId)->afterCommit();
        if ($at && $at->isFuture()) {
            $pending->delay($at);
        }
    }

    public function handle(OutboundUrlGuard $guard): void
    {
        if (!IntegrationOutbox::enabled()) {
            return;   // العلم مطفأ: الصف يبقى معلّقاً، والمُصالِح يجدوله بعد التفعيل
        }

        // (1) استيلاء ذري: صف معلّق حلّ موعده وحده
        $claimed = IntegrationOutboxRow::query()->whereKey($this->rowId)
            ->where('status', IntegrationOutboxRow::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->update(['status' => IntegrationOutboxRow::DELIVERING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $row = IntegrationOutboxRow::query()->findOrFail($this->rowId);
        $endpoint = IntegrationEndpoint::query()->find($row->endpoint_id);

        if (!$endpoint) {
            $this->finish($row, IntegrationOutboxRow::DEAD, null, 'endpoint_missing');

            return;
        }
        // (2) وجهة موقوفة: لا محاولة، ويعود الصف معلّقاً (التشغيل يعيد جدولة معلّقها)
        if (!$endpoint->is_active && $row->event !== 'ping') {
            $row->forceFill([
                'status' => IntegrationOutboxRow::PENDING, 'attempts' => max(0, $row->attempts - 1),
                'next_attempt_at' => now()->addHour(), 'dispatched_at' => null,
            ])->save();

            return;
        }

        // (3) الحارس عند كل تسليم: الـ DNS قد يتغير بعد الحفظ
        $target = $guard->check((string) $endpoint->url);
        if ($target instanceof GuardRejection) {
            $this->finish($row, IntegrationOutboxRow::DEAD, null, 'ssrf_rejected:'.$target->code);
            $this->pause($endpoint, 'الرابط رُفض عند الإرسال: '.$target->message());

            return;
        }

        // (4) البايتات المرسلة هي الموقّعة نفسها
        $envelope = (array) $row->payload;
        $envelope['data'] = (object) ($envelope['data'] ?? []);
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $deliveryId = (string) Str::uuid7();
        $row->forceFill(['last_delivery_id' => $deliveryId])->save();

        try {
            $headers = WebhookSigner::headers($row->idempotency_key, now()->getTimestamp(), $body, $endpoint->signingSecrets(), $row->event, $deliveryId);
        } catch (\Throwable $e) {
            $this->finish($row, IntegrationOutboxRow::DEAD, null, 'secret_unreadable');
            $this->pause($endpoint, 'تعذّر قراءة سر الوجهة (دوّره من الصفحة)');

            return;
        }

        // (5) الإرسال إلى العنوان المثبّت
        $status = null;
        $retryAfter = null;
        $error = null;
        try {
            $response = Http::withOptions([
                'curl' => [CURLOPT_RESOLVE => [$target->curlResolve()]],
                'allow_redirects' => false,
                'stream' => true,
            ])->withHeaders($headers)
                ->connectTimeout((int) config('integrations.connect_timeout', 5))
                ->timeout((int) config('integrations.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($target->url);
            $status = $response->status();
            $retryAfter = self::retryAfter($response);
            self::drain($response);
        } catch (\Throwable $e) {
            $error = 'connection_failed: '.class_basename($e).' '.self::clean($e->getMessage(), $endpoint);
        }

        $this->settle($row->fresh(), $endpoint, $status, $retryAfter, $error);
    }

    /** النتيجة: 2xx مسلَّم، 410 وأخطاء الإعداد dead فوراً، والعابر معلّق بالانتظار التالي، وبعد 6 محاولات dead */
    private function settle(IntegrationOutboxRow $row, IntegrationEndpoint $endpoint, ?int $status, ?int $retryAfter, ?string $error): void
    {
        $ok = $status !== null && $status >= 200 && $status < 300;
        $error ??= $ok ? null : ($status !== null && $status >= 300 && $status < 400 ? "redirect_{$status}" : "http_{$status}");

        // ping من الصفحة: محاولة واحدة تُعرض نتيجتها فوراً، بلا إعادة ولا عدّ فشل ولا إيقاف
        if ($row->event === 'ping') {
            $this->finish($row, $ok ? IntegrationOutboxRow::DELIVERED : IntegrationOutboxRow::DEAD, $status, $error);
            if ($ok) {
                $endpoint->forceFill(['last_ping_ok_at' => now()])->save();
            }
            Log::info('integrations.ping', $this->context($row, $endpoint, $status));

            return;
        }

        if ($ok) {
            $this->finish($row, IntegrationOutboxRow::DELIVERED, $status, null);
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();
            Log::info('integrations.delivered', $this->context($row, $endpoint, $status));

            return;
        }

        $transient = $status === null || in_array($status, [408, 409, 425, 429], true) || $status >= 500;

        if ($status === 410) {
            $this->finish($row, IntegrationOutboxRow::DEAD, $status, $error);
            $this->pause($endpoint, 'المستقبل ألغى الاشتراك (410)');
        } elseif (!$transient) {
            // 3xx و4xx: خطأ إعداد عند المستقبل، لا تفيده الإعادة
            $this->finish($row, IntegrationOutboxRow::DEAD, $status, $error);
            $this->alertDead($endpoint, $status);
        } elseif ($row->attempts >= (int) config('integrations.max_attempts', 6)) {
            $this->finish($row, IntegrationOutboxRow::DEAD, $status, $error);
            $this->alertDead($endpoint, $status);
        } else {
            $backoff = (array) config('integrations.backoff', [30, 120, 600, 3600, 21600]);
            $wait = $retryAfter ?? (int) ($backoff[min($row->attempts - 1, count($backoff) - 1)] ?? 3600);
            $next = now()->addSeconds(max(1, $wait));
            $row->forceFill([
                'status' => IntegrationOutboxRow::PENDING, 'next_attempt_at' => $next, 'dispatched_at' => $next,
                'last_status_code' => $status, 'last_error' => self::cut($error),
            ])->save();
            self::dispatchFor($row->id, $next);
        }

        $this->countFailure($endpoint);
        Log::warning('integrations.delivery_failed', $this->context($row, $endpoint, $status) + ['error' => self::cut($error)]);
    }

    private function finish(IntegrationOutboxRow $row, string $status, ?int $code, ?string $error): void
    {
        $row->forceFill([
            'status' => $status,
            'last_status_code' => $code,
            'last_error' => self::cut($error),
            'delivered_at' => $status === IntegrationOutboxRow::DELIVERED ? now() : null,
            'dispatched_at' => null,
        ])->save();
    }

    /** فشل متتالٍ؛ عند 20 تُوقف الوجهة آلياً وتُدقَّق، والتنبيه مرة في اليوم */
    private function countFailure(IntegrationEndpoint $endpoint): void
    {
        IntegrationEndpoint::query()->whereKey($endpoint->id)->update([
            'consecutive_failures' => DB::raw('consecutive_failures + 1'), 'last_failure_at' => now(), 'updated_at' => now(),
        ]);
        $endpoint->refresh();
        if ($endpoint->is_active && $endpoint->consecutive_failures >= (int) config('integrations.auto_pause_after', 20)) {
            $this->pause($endpoint, "{$endpoint->consecutive_failures} فشلاً متتالياً");
        }
    }

    private function pause(IntegrationEndpoint $endpoint, string $reason): void
    {
        $paused = IntegrationEndpoint::query()->whereKey($endpoint->id)->where('is_active', true)
            ->update(['is_active' => false, 'paused_reason' => mb_substr($reason, 0, 120), 'updated_at' => now()]);
        if ($paused !== 1) {
            return;
        }
        AuditActor::as('system', 'job:integrations', fn () => AuditLog::safe(AuditAction::IntegrationEndpointAutoPaused, 'integration_endpoint', $endpoint->id, $endpoint->name, [
            'reason' => mb_substr($reason, 0, 120), 'host' => $endpoint->host(), 'failures' => (int) $endpoint->fresh()?->consecutive_failures,
        ]));
        self::alertDaily("paused:{$endpoint->id}", '⛔ وجهة تكامل أُوقفت آلياً', "«{$endpoint->name}»: {$reason}. راجعها من صفحة التكاملات ثم شغّلها بعد ping ناجح.");
    }

    private function alertDead(IntegrationEndpoint $endpoint, ?int $status): void
    {
        self::alertDaily("dead:{$endpoint->id}", '⚠️ تسليم تكامل فشل نهائياً', "«{$endpoint->name}» ردّ ".($status ?? 'بلا رد').'. راجع التسليمات الفاشلة في صفحة التكاملات.');
    }

    /** تنبيه للمدراء مرة في اليوم لكل مفتاح (OpsAlerts يكبح الساعة وحدها) */
    public static function alertDaily(string $key, string $title, string $body): void
    {
        if (OpsHealth::store()->add('integrations:alert:'.$key.':'.now()->toDateString(), 1, 86400)) {
            app(OpsAlerts::class)->notifyAdmins('integrations:'.$key, $title, $body, '/settings/integrations');
        }
    }

    private function context(IntegrationOutboxRow $row, IntegrationEndpoint $endpoint, ?int $status): array
    {
        return ['row' => $row->id, 'endpoint' => $endpoint->id, 'event' => $row->event, 'attempt' => $row->attempts, 'http' => $status, 'host' => $endpoint->host()];
    }

    /** Retry-After بالثواني أو بتاريخ HTTP، بسقف ساعة */
    private static function retryAfter(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        $seconds = ctype_digit($value) ? (int) $value : (($ts = strtotime($value)) ? $ts - time() : null);

        return $seconds === null ? null : min(max(1, $seconds), (int) config('integrations.retry_after_cap', 3600));
    }

    /** لا نحتاج جسم الرد: 64KB على الأكثر ثم إغلاق الاتصال */
    private static function drain(Response $response): void
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
            $limit = (int) config('integrations.max_response_bytes', 65536);
            $read = 0;
            while (!$stream->eof() && $read < $limit) {
                $read += strlen($stream->read(min(8192, $limit - $read)));
            }
            $stream->close();
        } catch (\Throwable) {
            // الرمز وحده يكفي
        }
    }

    /** بلا رابط كامل (قد يحمل معاملات سرية) ولا سر ولا رقم طويل */
    private static function clean(string $message, IntegrationEndpoint $endpoint): string
    {
        $message = preg_replace('~https?://[^\s"\'<>]+~i', '['.$endpoint->host().']', $message) ?? '';

        return OpsAlerts::sanitize(SecretRedactor::clean($message, rescue(fn () => $endpoint->signingSecrets(), [], false)));
    }

    private static function cut(?string $error): ?string
    {
        return $error === null ? null : mb_substr(SecretRedactor::clean($error), 0, 300);
    }
}

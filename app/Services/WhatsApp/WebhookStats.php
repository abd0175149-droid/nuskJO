<?php

namespace App\Services\WhatsApp;

use App\Models\WaWebhookEvent;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * ملخّص استقبال webhook البوابة (E20، OpenWA 2026-11-06) لبطاقة الإعدادات وwhatsapp:doctor: أعداد وأعمار فقط،
 * بلا جسم ولا رقم ولا نص في أي مكان. (سياج حساب ميتا وحقولها أُزيلا مع ميتا)
 */
final class WebhookStats
{
    public static function summary(bool $fresh = false): array
    {
        if ($fresh) {
            return self::build();
        }

        return OpsHealth::store()->remember('wa:webhook:stats', 60, fn () => self::build());
    }

    private static function build(): array
    {
        $table = Schema::hasTable('wa_webhook_events');

        $last24 = array_fill_keys([WaWebhookEvent::PROCESSED, WaWebhookEvent::FAILED, WaWebhookEvent::DEAD, WaWebhookEvent::MALFORMED, WaWebhookEvent::REJECTED, WaWebhookEvent::PENDING], 0);
        $deadOpen = 0;
        $oldestHours = null;
        if ($table) {
            foreach (WaWebhookEvent::where('received_at', '>=', now()->subDay())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
                $last24[$status] = (int) $n;
            }
            $deadOpen = WaWebhookEvent::where('status', WaWebhookEvent::DEAD)->whereNotNull('raw_body')->count();
            $oldest = WaWebhookEvent::whereNotNull('raw_body')->min('received_at');
            $oldestHours = $oldest ? (int) floor(now()->diffInMinutes(Carbon::parse($oldest), true) / 60) : null;
        }

        $signatures = WebhookIntake::badSignatureCounts();

        $warnings = [];
        if ($deadOpen > 0) {
            $warnings[] = 'dead';
        }
        if ($last24[WaWebhookEvent::REJECTED] > 0) {
            $warnings[] = 'rejected';
        }
        if ($oldestHours !== null && $oldestHours >= (int) config('whatsapp.webhook.body_hours', 72)) {
            $warnings[] = 'retention';
        }
        if ($signatures['hour'] >= (int) config('whatsapp.webhook.bad_signature_alert', 20)) {
            $warnings[] = 'signatures';
        }

        return [
            'log' => ['on' => FeatureFlags::on('inbound_event_log'), 'mode' => config('whatsapp.webhook.log_mode'), 'table' => $table],
            'last24' => $last24,
            'dead_open' => $deadOpen,
            'oldest_body_hours' => $oldestHours,
            'signatures' => $signatures,
            'limits' => [
                'max_bytes' => (int) config('whatsapp.webhook.max_bytes'),
                'body_hours' => (int) config('whatsapp.webhook.body_hours'),
                'meta_days' => (int) config('whatsapp.webhook.meta_days'),
                'max_attempts' => (int) config('whatsapp.webhook.max_attempts'),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * فحوص whatsapp:doctor: المسار خارج الجلسة، وحد nginx المقروء من الملف المنشور، وما ينتظر يدوياً، والاحتفاظ.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    public static function checks(?array $summary = null): array
    {
        $summary ??= self::summary(true);
        $out = [];

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/openwa/webhook/{channel}' && in_array('POST', $r->methods(), true));
        $middleware = $route ? app('router')->gatherRouteMiddleware($route) : [];
        if (!$route) {
            $out[] = ['level' => 'error', 'code' => 'webhook_route_missing', 'message' => 'مسار POST api/openwa/webhook/{channel} غير مسجّل'];
        } elseif (collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'StartSession') || str_contains((string) $m, 'ValidateCsrfToken'))) {
            $out[] = ['level' => 'error', 'code' => 'webhook_in_session', 'message' => 'مسار الويبهوك يمر بالجلسة أو CSRF — يجب أن يبقى في routes/webhooks.php'];
        }

        $nginx = self::nginx();
        if ($nginx['webhook_max'] === null) {
            $out[] = ['level' => 'warning', 'code' => 'nginx_webhook_block', 'message' => 'لم تُعثر كتلة nginx الخاصة بـ webhook البوابة في '.$nginx['file']];
        }
        if ($nginx['keepalive'] !== null && $nginx['keepalive'] < 100) {
            $out[] = ['level' => 'warning', 'code' => 'nginx_keepalive', 'message' => "keepalive_timeout {$nginx['keepalive']}s أقل من 100s — 502 متقطع محتمل من النفق"];
        }

        if (($summary['dead_open'] ?? 0) > 0) {
            $out[] = ['level' => 'error', 'code' => 'webhook_dead', 'message' => "{$summary['dead_open']} إشعاراً لم يُعالج بعد كل المحاولات — راجع يدوياً ثم whatsapp:replay-webhooks --id=… --force"];
        }
        if (in_array('retention', $summary['warnings'] ?? [], true)) {
            $out[] = ['level' => 'error', 'code' => 'webhook_retention', 'message' => "جسم إشعار محفوظ منذ {$summary['oldest_body_hours']} ساعة (الحد {$summary['limits']['body_hours']}) — شغّل whatsapp:webhook-events-prune"];
        }
        if (in_array('signatures', $summary['warnings'] ?? [], true)) {
            $out[] = ['level' => 'warning', 'code' => 'webhook_signatures', 'message' => "توقيعات مرفوضة كثيرة آخر ساعة ({$summary['signatures']['hour']}) — «إصلاح» الرقم من صفحة الأرقام يعيد سر التوقيع"];
        }

        return $out;
    }

    /** @return array{file: string, webhook_max: ?string, keepalive: ?int} من الملف المنشور داخل الحاوية، وإلا نسخة المستودع */
    public static function nginx(): array
    {
        $file = collect(['/etc/nginx/http.d/default.conf', base_path('docker/nginx.conf')])->first(fn ($f) => is_readable($f)) ?? base_path('docker/nginx.conf');
        $conf = is_readable($file) ? (string) file_get_contents($file) : '';
        $block = preg_match('/location\s*~\s*\^\/api\/openwa\/webhook\S*\s*\{([^}]*)\}/', $conf, $m) ? $m[1] : null;

        return [
            'file' => $file,
            'webhook_max' => $block !== null && preg_match('/client_max_body_size\s+([0-9]+[kKmM]?)\s*;/', $block, $b) ? $b[1] : null,
            'keepalive' => preg_match('/^\s*keepalive_timeout\s+([0-9]+)s?\s*;/m', $conf, $k) ? (int) $k[1] : null,
        ];
    }
}

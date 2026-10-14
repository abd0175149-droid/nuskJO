<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaLlmIncident;
use App\Services\WhatsApp\Llm\LlmException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * قاطع دائرة لكل مزوّد ذكاء (E12): إخفاقات متتالية (threshold خلال window دقيقة) تفتحه open دقيقة، والمفتاح
 * المرفوض أو الحصة المنتهية تفتحه من أول مرة. أثناء الفتح لا يُستدعى النموذج ويُحوَّل العميل لموظف برسالة
 * يبنيها الكود؛ بعد المدة محاولة half-open واحدة (أو whatsapp:llm-probe). الحالة في الكاش الافتراضي (database
 * في الإنتاج: يشترك فيها العامل وphp-fpm)، والحوادث في wa_llm_incidents. لا بيانات عملاء في أي منهما.
 *
 * الوضع (breaker_mode): off لا يحسب شيئاً · shadow يحسب ويسجّل ولا يحجب ولا يُشعر · on السلوك الكامل.
 */
final class LlmCircuitBreaker
{
    public const ALLOW = 'allow';

    public const PROBE = 'probe';

    public const BLOCK = 'block';

    private const PROBE_TTL = 120;

    /** أطول مدة فتح يفرضها retryAfter من المزوّد */
    private const MAX_RETRY_MINUTES = 60;

    public static function mode(?WaBotSetting $s = null): string
    {
        $mode = ($s ?? WaBotSetting::current())->breaker_mode;

        return in_array($mode, ['off', 'shadow', 'on'], true) ? $mode : 'off';
    }

    public static function allow(string $provider): string
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return self::ALLOW;
        }
        $state = self::state($provider);
        if ($state['state'] === 'closed') {
            return self::ALLOW;
        }
        if (now()->timestamp < (int) $state['open_until']) {
            return $mode === 'shadow' ? self::ALLOW : self::BLOCK;
        }
        // انتهت المدة: محاولة واحدة لمن يفوز بالمفتاح، والبقية محجوبون حتى تُحسم
        if (Cache::add(self::probeKey($provider), 1, self::PROBE_TTL)) {
            self::locked(function () use ($provider) {
                $st = self::state($provider);
                if ($st['state'] !== 'closed') {
                    self::put($provider, ['state' => 'half_open'] + $st);
                }
            });

            return self::PROBE;
        }

        return $mode === 'shadow' ? self::ALLOW : self::BLOCK;
    }

    public static function recordSuccess(string $provider): void
    {
        if (self::mode() === 'off') {
            return;
        }
        self::locked(function () use ($provider) {
            $st = self::state($provider);
            if ($st['state'] === 'closed') {
                if ($st['failures'] !== []) {
                    self::put($provider, ['failures' => []] + $st);
                }

                return;
            }
            self::close($provider, $st, 'probe', null);
        });
    }

    public static function recordFailure(string $provider, LlmException $e, ?string $model = null): void
    {
        if (self::mode() === 'off' || !$e->counts()) {
            return;
        }
        $s = WaBotSetting::current();
        self::locked(function () use ($provider, $e, $model, $s) {
            $st = self::state($provider);
            $now = now()->timestamp;

            // محاولة half-open فشلت (أو إخفاق أثناء الفتح): فتح جديد بلا إشعار
            if ($st['state'] !== 'closed') {
                $until = $now + self::openSeconds($s, $e);
                self::put($provider, ['state' => 'open', 'open_until' => $until] + $st);
                Cache::forget(self::probeKey($provider));
                if ($st['incident_id'] && ($incident = WaLlmIncident::find($st['incident_id']))) {
                    $incident->forceFill(['failed_probes' => $incident->failed_probes + 1, 'open_until' => Carbon::createFromTimestamp($until)])->save();
                }

                return;
            }

            $window = max(1, (int) ($s->breaker_window_minutes ?: 5)) * 60;
            $failures = array_values(array_filter($st['failures'], fn ($t) => $t > $now - $window));
            $failures[] = $now;
            if (!$e->tripsImmediately() && count($failures) < max(2, (int) ($s->breaker_threshold ?: 3))) {
                self::put($provider, ['failures' => $failures] + $st);

                return;
            }

            self::open($provider, $s, $e->kind(), $e->httpStatus, $e->getMessage(), self::openSeconds($s, $e), $model ?? $s->model, null);
        });
    }

    /** وضع تحويل يدوي (للتمرين أو لعطل يعرفه المدير قبل النظام) */
    public static function forceOpen(string $provider, int $minutes, ?int $userId): void
    {
        $s = WaBotSetting::current();
        self::locked(fn () => self::open($provider, $s, 'manual', null, 'فتح يدوي من الإعدادات', max(1, $minutes) * 60, null, $userId));
    }

    /** إغلاق يدوي، أو لأن المفتاح أو النموذج تغيّر (settings_changed) */
    public static function forceClose(string $provider, ?int $userId, string $reason = 'manual'): void
    {
        self::locked(function () use ($provider, $userId, $reason) {
            $st = self::state($provider);
            if ($st['state'] === 'closed') {
                if ($st['failures'] !== []) {
                    self::put($provider, ['failures' => []] + $st);
                }

                return;
            }
            self::close($provider, $st, $reason, $userId);
        });
    }

    /** للإعدادات: الحالة والنوع والسبب المنقّى والأوقات */
    public static function status(string $provider): array
    {
        $st = self::state($provider);
        $incident = $st['incident_id'] ? WaLlmIncident::find($st['incident_id']) : null;

        return [
            'provider' => $provider,
            'mode' => self::mode(),
            'state' => $st['state'],
            'failures' => count($st['failures']),
            'opened_at' => $st['opened_at'] ? Carbon::createFromTimestamp($st['opened_at'])->toIso8601String() : null,
            'open_until' => $st['open_until'] ? Carbon::createFromTimestamp($st['open_until'])->toIso8601String() : null,
            'kind' => $incident?->kind,
            'reason' => $incident?->reason,
            'shadow' => (bool) $incident?->shadow,
            'incident_id' => $st['incident_id'],
        ];
    }

    /** للصندوق: الحالة والأوقات فقط، وفي الوضع on وحده (shadow لا يغيّر ما يراه الموظف) */
    public static function publicStatus(): array
    {
        $s = WaBotSetting::current();
        if (self::mode($s) !== 'on') {
            return ['state' => 'closed', 'opened_at' => null, 'open_until' => null];
        }
        $full = self::status((string) $s->provider);

        return ['state' => $full['state'], 'opened_at' => $full['opened_at'], 'open_until' => $full['open_until']];
    }

    public static function incidentId(string $provider): ?int
    {
        return self::state($provider)['incident_id'];
    }

    public static function openUntil(string $provider): ?Carbon
    {
        $until = self::state($provider)['open_until'];

        return $until ? Carbon::createFromTimestamp($until) : null;
    }

    public static function countHandoff(?int $incidentId): void
    {
        if ($incidentId && self::available()) {
            WaLlmIncident::whereKey($incidentId)->increment('handoffs');
        }
    }

    // ==================== داخلي ====================

    private static function open(string $provider, WaBotSetting $s, string $kind, ?int $http, ?string $reason, int $seconds, ?string $model, ?int $userId): void
    {
        $now = now()->timestamp;
        $shadow = self::mode($s) === 'shadow';
        $incident = self::available() ? WaLlmIncident::create([
            'provider' => $provider, 'model' => $model ? mb_substr($model, 0, 64) : null, 'kind' => mb_substr($kind, 0, 20),
            'http_status' => $http, 'reason' => $reason ? LlmException::clean($reason) : null, 'shadow' => $shadow,
            'opened_at' => now(), 'open_until' => Carbon::createFromTimestamp($now + $seconds), 'opened_by' => $userId,
        ]) : null;
        self::put($provider, ['state' => 'open', 'failures' => [], 'opened_at' => $now, 'open_until' => $now + $seconds,
            'kind' => $kind, 'incident_id' => $incident?->id]);
        Cache::forget(self::probeKey($provider));
        WaLog::warning('llm.breaker.open', ['source' => $provider, 'type' => $kind, 'status' => $shadow ? 'shadow' : 'on']);

        if (!$shadow) {
            WhatsAppInbox::notifyAdmins('🔌 البوت في وضع التحويل',
                self::kindLabel($kind).' منذ '.now()->format('H:i').'، والمحاولة التالية '.Carbon::createFromTimestamp($now + $seconds)->format('H:i')
                .'. يُحوَّل كل عميل لموظف برسالة ثابتة حتى يعود المزوّد.', '/whatsapp/settings#breaker');
        }
    }

    private static function close(string $provider, array $st, string $reason, ?int $userId): void
    {
        $incident = $st['incident_id'] ? WaLlmIncident::find($st['incident_id']) : null;
        $incident?->forceFill(['closed_at' => now(), 'close_reason' => $reason, 'closed_by' => $userId])->save();
        self::put($provider, self::closedState());
        Cache::forget(self::probeKey($provider));
        WaLog::warning('llm.breaker.close', ['source' => $provider, 'reason' => $reason]);

        if ($incident && !$incident->shadow) {
            $minutes = (int) max(1, round($incident->opened_at->diffInMinutes(now())));
            WhatsAppInbox::notifyAdmins('✅ عاد البوت للعمل',
                "انتهى وضع التحويل بعد {$minutes} دقيقة. حُوِّلت خلاله {$incident->handoffs} محادثة لموظف.", '/whatsapp/inbox?filter=attention');
        }
    }

    private static function openSeconds(WaBotSetting $s, LlmException $e): int
    {
        $minutes = max(1, (int) ($s->breaker_open_minutes ?: 10));
        $retry = $e->retryAfter !== null ? min((int) ceil($e->retryAfter / 60), self::MAX_RETRY_MINUTES) : 0;

        return max($minutes, $retry) * 60;
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'quota_exhausted' => 'انتهت حصة مزوّد الذكاء أو فوترته',
            'auth_failed' => 'المزوّد رفض المفتاح أو اسم النموذج',
            'rate_limited' => 'المزوّد يخنق الطلبات',
            'unavailable' => 'خدمة مزوّد الذكاء غير متاحة',
            'manual' => 'فُتح وضع التحويل يدوياً',
            default => 'تعطّل مزوّد الذكاء',
        };
    }

    /** @return array{state: string, failures: list<int>, opened_at: ?int, open_until: ?int, kind: ?string, incident_id: ?int} */
    private static function state(string $provider): array
    {
        $st = Cache::get(self::key($provider));

        return is_array($st) ? $st + self::closedState() : self::closedState();
    }

    private static function closedState(): array
    {
        return ['state' => 'closed', 'failures' => [], 'opened_at' => null, 'open_until' => null, 'kind' => null, 'incident_id' => null];
    }

    private static function put(string $provider, array $st): void
    {
        Cache::forever(self::key($provider), $st);
    }

    private static function locked(callable $fn): void
    {
        try {
            Cache::lock('wa:llm:health-lock', 5)->block(3, $fn);
        } catch (LockTimeoutException) {
            WaLog::warning('llm.breaker.lock_timeout', []);
        }
    }

    private static function key(string $provider): string
    {
        return "wa:llm:health:{$provider}";
    }

    private static function probeKey(string $provider): string
    {
        return "wa:llm:probe:{$provider}";
    }

    private static ?bool $available = null;

    private static function available(): bool
    {
        return self::$available ??= Schema::hasTable('wa_llm_incidents');
    }
}

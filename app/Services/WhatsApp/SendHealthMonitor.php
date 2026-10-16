<?php

namespace App\Services\WhatsApp;

use App\Enums\WaHealth;
use App\Jobs\RefreshChannelHealth;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Cache;

/**
 * جهة الإرسال في صحة القناة (E22، مع العلم channel_health). المعنى كله من MetaErrorCatalog (الجدول الوحيد):
 *
 * - recordFailure/recordSuccess من WhatsAppChannel بعد النتيجة النهائية لكل إرسال (بعد إعادات E19): عدّاد فشل متتالٍ
 *   للعابر والخنق والاعتماد والنزاهة وفشل الاتصال، لا لأخطاء المستلم والنافذة والطلب. تنبيه عند الثالث ثم كل خامس.
 * - observe من MetaErrorReactor لكل خطأ ميتا (إرسال وحالة ويبهوك ووسائط): 190 (أو 0 من OAuth) TOKEN_INVALID من أول مرة،
 *   و368 BLOCKED من أول مرة (قرار E18، A10)، وقاطع التكرار لـ 131031 و131048 ينبّه ويفحص (ويحجب بالعلم
 *   integrity_breaker_lock وحده)، و130497 تنبيه. لا رفع آلياً في أي حالة.
 */
final class SendHealthMonitor
{
    public const CONSECUTIVE_KEY = 'wa:send:consecutive_fail';

    /** الفئات التي تدل على حال القناة لا حال مستلم واحد */
    private const COUNTED = ['transient', 'throttle', 'auth', 'integrity'];

    private const BLOCK_CODE = 368;

    /** 131031 قد يكون PIN التحقق بخطوتين، و131048 حد السبام: تنبيه وفحص، والقفل بقرار المالك */
    private const BREAKER_CODES = [131031, 131048];

    private const COUNTRY_CODE = 130497;

    /** رموز تملك ChannelHealth أثرها وتنبيهها: المفاعل لا ينبّه لها مرة ثانية */
    public static function owns(?int $metaCode, ?string $type = null): bool
    {
        return in_array($metaCode, [190, self::BLOCK_CODE, ...self::BREAKER_CODES], true)
            || ($metaCode === 0 && in_array($type, [null, 'OAuthException'], true));
    }

    public static function recordFailure(MetaError $err): void
    {
        try {
            if (!ChannelHealth::enabled() || !self::counted($err)) {
                return;
            }
            Cache::add(self::CONSECUTIVE_KEY, 0, now()->addDay());
            $n = (int) Cache::increment(self::CONSECUTIVE_KEY);
            $at = (int) config('whatsapp.health.consecutive_alert_at', 3);
            $every = max(1, (int) config('whatsapp.health.consecutive_alert_every', 5));
            WaLog::warning('health.send_failure', ['count' => $n, 'meta_code' => $err->metaCode, 'error_code' => $err->code]);
            if ($n === $at || ($n > $at && ($n - $at) % $every === 0)) {
                $code = $err->metaCode ?? $err->code;
                app(OpsAlerts::class)->notifyAdmins('health:send_fail:'.$n, "⚠️ فشل الإرسال إلى ميتا {$n} مرات متتالية",
                    "آخر رمز {$code}: {$err->staffMessage}", ChannelHealth::URL);
            }
        } catch (\Throwable $e) {
            WaLog::error('health.monitor_failed', ['type' => class_basename($e)]);
        }
    }

    /** أول نجاح: يصفّر العدّادين، ويمسح آخر خطأ مصدره إرسال (خطأ الفحص يمسحه الفحص التالي وحده) */
    public static function recordSuccess(WaBotSetting $s): void
    {
        try {
            if (!ChannelHealth::enabled()) {
                return;
            }
            Cache::forget(self::CONSECUTIVE_KEY);
            Cache::forget(self::windowKey());
            $raw = $s->getAttributes()['wa_last_error'] ?? null;
            $last = (array) ($s->wa_last_error ?? []);
            if ($raw !== null && in_array($last['source'] ?? null, ['bot', 'staff', 'system'], true)) {
                SettingsWriter::applyIf(['wa_last_error' => $raw], ['wa_last_error' => null], bumpVersion: false);
            }
        } catch (\Throwable $e) {
            WaLog::error('health.monitor_failed', ['type' => class_basename($e)]);
        }
    }

    /** أثر خطأ ميتا على حالة القناة (من MetaErrorReactor، بعد إزالة تكرار ويبهوك الحالة) */
    public static function observe(MetaError $err): void
    {
        $code = $err->metaCode;
        if ($code === null || !ChannelHealth::enabled()) {
            return;
        }
        if ($code === 190 || ($code === 0 && in_array($err->type, [null, 'OAuthException'], true))) {
            ChannelHealth::fromSendError(WaHealth::TokenInvalid, 'token:'.$code, $err->staffMessage);
        } elseif ($code === self::BLOCK_CODE) {
            ChannelHealth::fromSendError(WaHealth::Blocked, 'integrity:'.$code, $err->staffMessage);
        } elseif (in_array($code, self::BREAKER_CODES, true)) {
            self::breaker($err);
        } elseif ($code === self::COUNTRY_CODE) {
            ChannelHealth::remember(['country_restricted' => now()->toIso8601String()]);
            app(OpsAlerts::class)->notifyAdmins('health:country', '⚠️ ميتا تمنع المراسلة إلى بلد أحد العملاء', $err->staffMessage, ChannelHealth::URL);
        }
    }

    public static function consecutive(): int
    {
        return (int) Cache::get(self::CONSECUTIVE_KEY, 0);
    }

    /** قاطع التكرار: العتبة خلال النافذة مرة واحدة (العدد يساوي العتبة) */
    private static function breaker(MetaError $err): void
    {
        $key = self::windowKey();
        Cache::add($key, 0, now()->addMinutes(2 * self::windowMinutes()));
        $n = (int) Cache::increment($key);
        if ($n !== max(1, (int) config('whatsapp.health.breaker.integrity_threshold', 3))) {
            return;
        }
        WaLog::warning('health.integrity_breaker', ['count' => $n, 'meta_code' => $err->metaCode]);
        if (FeatureFlags::on('integrity_breaker_lock')) {
            ChannelHealth::fromSendError(WaHealth::Blocked, 'integrity:'.$err->metaCode, $err->staffMessage);

            return;
        }
        app(OpsAlerts::class)->notifyAdmins('health:integrity:'.$err->metaCode, '⛔ ميتا ترفض الإرسال لأسباب النزاهة بشكل متكرر',
            "تكرر الرمز {$err->metaCode} ({$err->staffMessage}) {$n} مرات خلال ".self::windowMinutes().' دقيقة. يُفحص الحساب الآن؛ ولا يُحجب الإرسال إلا إن أكد الفحص.', ChannelHealth::URL);
        RefreshChannelHealth::dispatch();
    }

    private static function counted(MetaError $err): bool
    {
        if ($err->metaCode === null) {
            // فشل الاتصال أو المهلة أو 5xx بلا جسم
            return str_starts_with($err->code, 'HTTP_');
        }

        return in_array($err->category, self::COUNTED, true) && $err->scope !== 'recipient';
    }

    private static function windowMinutes(): int
    {
        return max(1, (int) config('whatsapp.health.breaker.window_minutes', 15));
    }

    private static function windowKey(): string
    {
        return 'wa:integrity:'.intdiv(now()->getTimestamp(), self::windowMinutes() * 60);
    }
}

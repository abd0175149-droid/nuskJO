<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\ChannelState;
use App\Services\WhatsApp\WaLog;
use Illuminate\Support\Facades\Cache;

/**
 * قاطع إخفاقات الرقم: إخفاقات متتالية على مستوى الرقم (البوابة لا ترد، الرقم غير جاهز، خطأ محرك، نتيجة غير مؤكدة)
 * توقف الإرسال منه مدة قصيرة وتنبّه الإدارة. أول نجاح يصفّر العداد. رفض بسبب الرسالة أو المستلم لا يُعدّ.
 * الحالة في الكاش لا في القاعدة: إعادة تشغيل الكاش تفتح القاطع، وهذا مقبول (العداد يعود من الصفر).
 */
final class FailureBreaker
{
    public static function threshold(): int
    {
        return max(2, (int) config('whatsapp.breaker.threshold', 5));
    }

    public static function cooldownMinutes(): int
    {
        return max(1, (int) config('whatsapp.breaker.cooldown_minutes', 15));
    }

    public static function open(WaChannel $channel): bool
    {
        $until = Cache::get(self::untilKey($channel));

        return is_int($until) && $until > now()->getTimestamp();
    }

    public static function until(WaChannel $channel): ?\Illuminate\Support\Carbon
    {
        $until = Cache::get(self::untilKey($channel));

        return is_int($until) && $until > now()->getTimestamp() ? \Illuminate\Support\Carbon::createFromTimestamp($until) : null;
    }

    public static function success(WaChannel $channel): void
    {
        if (Cache::get(self::countKey($channel))) {
            Cache::forget(self::countKey($channel));
        }
    }

    public static function failure(WaChannel $channel, string $code): void
    {
        $key = self::countKey($channel);
        Cache::add($key, 0, now()->addHour());
        $n = (int) Cache::increment($key);
        if ($n < self::threshold() || self::open($channel)) {
            return;
        }
        $until = now()->addMinutes(self::cooldownMinutes());
        Cache::put(self::untilKey($channel), $until->getTimestamp(), $until);
        Cache::forget($key);
        $channel->logEvent('breaker', ['count' => $n, 'code' => mb_substr($code, 0, 32)]);
        WaLog::warning('wa.breaker.open', ['channel_id' => $channel->id, 'count' => $n, 'error_code' => $code]);
        ChannelState::alert($channel, 'breaker', '⚠️ أُوقف رقم واتساب مؤقتاً',
            "{$n} إخفاقات متتالية في الإرسال من «{$channel->name}» (آخرها {$code}). يتوقف الإرسال منه ".self::cooldownMinutes().' دقيقة ثم يُجرَّب من جديد.');
    }

    /** رفع القاطع يدوياً (صفحة الأرقام) */
    public static function reset(WaChannel $channel): void
    {
        Cache::forget(self::untilKey($channel));
        Cache::forget(self::countKey($channel));
    }

    private static function countKey(WaChannel $channel): string
    {
        return 'wa:breaker:n:'.$channel->id;
    }

    private static function untilKey(WaChannel $channel): string
    {
        return 'wa:breaker:until:'.$channel->id;
    }
}

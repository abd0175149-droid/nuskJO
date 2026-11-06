<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaOutbox;
use Carbon\CarbonInterface;

/**
 * عدّاد الإرسال الاستباقي من wa_outbox (المسار الوحيد للاستباقي): المُرسل فعلاً (sent) بوقت sent_at، لكل رقم ونوع في
 * الدقيقة والساعة واليوم، والمحادثات الباردة اليوم على الرقم، وما وصل كل مستلم اليوم عبر كل الأرقام. الردود لا تُعدّ.
 * «اليوم» بتوقيت عمّان.
 */
final class UsageCounter
{
    public static function sent(int $channelId, ?string $typeKey, CarbonInterface $since): int
    {
        return WaOutbox::query()->where('status', WaOutbox::SENT)->where('channel_id', $channelId)
            ->when($typeKey !== null, fn ($q) => $q->where('type_key', $typeKey))
            ->where('sent_at', '>=', $since)->where('is_test', false)->count();
    }

    public static function cold(int $channelId, CarbonInterface $since): int
    {
        return WaOutbox::query()->where('status', WaOutbox::SENT)->where('channel_id', $channelId)->where('cold', true)
            ->where('sent_at', '>=', $since)->where('is_test', false)->count();
    }

    public static function recipient(string $phone, CarbonInterface $since): int
    {
        return $phone === '' ? 0 : WaOutbox::query()->where('status', WaOutbox::SENT)->where('phone', $phone)
            ->where('sent_at', '>=', $since)->where('is_test', false)->count();
    }

    public static function lastSentAt(int $channelId): ?CarbonInterface
    {
        $at = WaOutbox::query()->where('status', WaOutbox::SENT)->where('channel_id', $channelId)->max('sent_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** بداية اليوم بتوقيت عمّان (بتوقيت التطبيق) */
    public static function dayStart(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->setTimezone(self::tz())->startOfDay()->setTimezone(config('app.timezone'));
    }

    public static function tz(): string
    {
        return (string) config('whatsapp.limits.timezone', 'Asia/Amman');
    }
}

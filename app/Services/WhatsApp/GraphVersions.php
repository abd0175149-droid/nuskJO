<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Carbon;

/**
 * تواريخ نهاية دعم إصدارات Graph API. بعدها تُرقّى الطلبات بصمت إلى أقدم إصدار متاح فيتغير شكل الردود.
 * هذا الجدول هو الموضع الوحيد الذي تُكتب فيه أرقام الإصدارات القديمة. يُحدَّث من SOURCE عند صدور إصدار جديد.
 */
final class GraphVersions
{
    public const SOURCE = 'https://developers.facebook.com/docs/graph-api/changelog/versions';

    /** null = لم تُعلن نهايته بعد */
    public const SUNSET = [
        'v21.0' => '2027-01-21',
        'v22.0' => '2027-05-20',
        'v23.0' => '2027-10-08',
        'v24.0' => '2028-02-18',
        'v25.0' => '2028-07-29',
        'v26.0' => null,
    ];

    /** يُنبَّه قبل هذا العدد من الأيام */
    public const WARN_DAYS = 60;

    public static function isValid(?string $version): bool
    {
        return is_string($version) && preg_match('/^v\d{2}\.0$/', $version) === 1;
    }

    public static function sunset(?string $version): ?string
    {
        return self::SUNSET[$version] ?? null;
    }

    public static function daysLeft(?string $version, ?Carbon $today = null): ?int
    {
        $sunset = self::sunset($version);

        return $sunset === null ? null : (int) ($today ?? now())->startOfDay()->diffInDays(Carbon::parse($sunset)->startOfDay(), false);
    }

    /** @return array{version: ?string, sunset: ?string, days_left: ?int, level: string} */
    public static function status(?string $version, ?Carbon $today = null): array
    {
        $known = self::isValid($version) && array_key_exists($version, self::SUNSET);
        $days = $known ? self::daysLeft($version, $today) : null;

        $level = match (true) {
            !$known => 'unknown',
            $days === null => 'ok',
            $days <= 0 => 'expired',
            $days <= self::WARN_DAYS => 'warn',
            default => 'ok',
        };

        return ['version' => $version ?: null, 'sunset' => $known ? self::sunset($version) : null, 'days_left' => $days, 'level' => $level];
    }

    /** لوحة القراءة في إعدادات الواتساب */
    public static function panel(): array
    {
        $webhook = config('services.whatsapp.webhook_version');

        return [
            'requests' => self::status(config('services.whatsapp.graph_version')),
            'webhook' => $webhook ? self::status($webhook) : ['version' => null, 'sunset' => null, 'days_left' => null, 'level' => 'unknown'],
            'appsecret_proof' => (bool) config('services.whatsapp.appsecret_proof'),
            'media_host_mode' => config('services.whatsapp.media_host_mode') === 'enforce' ? 'enforce' : 'log',
            'source' => self::SOURCE,
        ];
    }
}

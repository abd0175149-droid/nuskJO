<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaSendLimit;
use Illuminate\Support\Facades\Schema;

/**
 * الحد الفعّال لرقم ونوع: دمج الصفوف من الأعم إلى الأخص (كل+كل، ثم كل+نوع، ثم رقم+كل، ثم رقم+نوع)، فكل حقل من
 * أخص صف ضبطه، وما لم يضبطه أحد من WaSendLimit::DEFAULTS. القيمة 0 في سقف = لا إرسال، وnull = بلا سقف من هذا الصف.
 */
final class LimitResolver
{
    /** @var array<string, array>|null */
    private static ?array $rows = null;

    /** @return array<string, mixed> */
    public static function for(?int $channelId, ?string $typeKey): array
    {
        $rows = self::rows();
        // صف «الكل × الكل» موجود (الهجرة تبذره): سقف فارغ فيه = بلا سقف. بلا صف أصلاً: القيم المحافظة من الكود.
        // النافذة والأيام والفاصل لا تفرغ أبداً: من الكود إن لم يضبطها صف
        $caps = ['per_minute', 'per_hour', 'per_day', 'cold_per_day', 'per_recipient_day'];
        $out = isset($rows[self::key(null, null)]) ? array_fill_keys($caps, null) + WaSendLimit::DEFAULTS : WaSendLimit::DEFAULTS;
        foreach (array_unique([[null, null], [null, $typeKey], [$channelId, null], [$channelId, $typeKey]], SORT_REGULAR) as [$c, $t]) {
            $row = $rows[self::key($c, $t)] ?? null;
            if ($row === null) {
                continue;
            }
            foreach (WaSendLimit::FIELDS as $f) {
                if (array_key_exists($f, $row) && $row[$f] !== null) {
                    $out[$f] = $row[$f];
                }
            }
        }
        $out['weekdays'] = array_values(array_map('intval', (array) ($out['weekdays'] ?: [1, 2, 3, 4, 5, 6, 7])));

        return $out;
    }

    public static function flush(): void
    {
        self::$rows = null;
    }

    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        if (!Schema::hasTable('wa_send_limits')) {
            return self::$rows = [];
        }
        $out = [];
        foreach (WaSendLimit::query()->get() as $r) {
            $out[self::key($r->channel_id, $r->type_key)] = $r->only(WaSendLimit::FIELDS);
        }

        return self::$rows = $out;
    }

    private static function key(?int $channelId, ?string $typeKey): string
    {
        return ($channelId ?? '*').'|'.($typeKey ?? '*');
    }
}

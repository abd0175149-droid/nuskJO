<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaBotSetting;
use App\Models\WaChannel;
use Illuminate\Support\Facades\Schema;

/**
 * سقف الرقم اليومي للإرسال الاستباقي حسب عمره منذ أول ربط (warmup_started_at): رقم جديد يبدأ صغيراً ويكبر تدريجياً.
 * السلّم في wa_bot_settings.warmup_ladder: [[من اليوم، السقف]، …] تصاعدياً، والقيمة الافتراضية DEFAULT. رقم قديم
 * معروف (عمره الحقيقي أكبر) يُضبط تاريخ تسخينه للماضي من صفحة الحدود.
 */
final class WarmupLadder
{
    public const DEFAULT = [[0, 40], [3, 80], [7, 150], [14, 300], [30, 600]];

    /** @return list<array{0: int, 1: int}> */
    public static function ladder(): array
    {
        $raw = Schema::hasColumn('wa_bot_settings', 'warmup_ladder') ? WaBotSetting::current()->warmup_ladder : null;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $steps = [];
        foreach ((array) ($raw ?: self::DEFAULT) as $step) {
            if (is_array($step) && count($step) === 2 && is_numeric($step[0]) && is_numeric($step[1])) {
                $steps[] = [max(0, (int) $step[0]), max(0, (int) $step[1])];
            }
        }
        usort($steps, fn ($a, $b) => $a[0] <=> $b[0]);

        return $steps ?: self::DEFAULT;
    }

    public static function capFor(WaChannel $channel): int
    {
        $days = $channel->warmup_started_at ? (int) floor($channel->warmup_started_at->diffInDays(now(), true)) : 0;
        $cap = self::ladder()[0][1];
        foreach (self::ladder() as [$from, $value]) {
            if ($days >= $from) {
                $cap = $value;
            }
        }

        return $cap;
    }
}

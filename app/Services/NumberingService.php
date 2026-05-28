<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * NumberingService
 * توليد أرقام تسلسلية تلقائية للعمليات المالية
 * مثال: TRF-20260518-0001
 */
class NumberingService
{
    public static function generate(string $prefix, ?string $date = null): string
    {
        $date = $date ?? now()->format('Ymd');
        $pattern = "{$prefix}-{$date}-%";

        $last = DB::table('numbering_sequences')
            ->where('prefix', $prefix)
            ->where('date', $date)
            ->lockForUpdate()
            ->first();

        if ($last) {
            $next = $last->sequence + 1;
            DB::table('numbering_sequences')
                ->where('id', $last->id)
                ->update(['sequence' => $next]);
        } else {
            $next = 1;
            DB::table('numbering_sequences')->insert([
                'prefix' => $prefix,
                'date' => $date,
                'sequence' => $next,
                'created_at' => now(),
            ]);
        }

        return sprintf('%s-%s-%04d', $prefix, $date, $next);
    }
}

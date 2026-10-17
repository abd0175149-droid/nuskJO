<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * خط الدفاع الأخير ضد الاعتماد المزدوج: قيد واحد فعّال (غير معكوس) لكل فاتورة أو سند أو صرف أو مسير.
 * إن وُجد تكرار سابق لا تُنشأ الحماية ولا تفشل الهجرة (entrypoint يعمل بـ set -e)، بل تحذير في السجل،
 * فالتكرار يُراجعه المحاسب يدوياً ثم يُنشأ الفهرس بإعادة الهجرة.
 */
return new class extends Migration
{
    private const TYPES = "('invoice','receipt','disbursement','payroll')";

    public function up(): void
    {
        if (!Schema::hasTable('journal_entries')) {
            return;
        }

        $duplicates = DB::select('SELECT reference_type, reference_id, COUNT(*) AS n FROM journal_entries
            WHERE is_reversed = 0 AND reference_type IN '.self::TYPES.'
            GROUP BY reference_type, reference_id HAVING COUNT(*) > 1');

        if ($duplicates) {
            Log::warning('je_active_ref_unique not created: active duplicate journal entries exist', [
                'count' => count($duplicates),
                'refs' => array_map(fn ($d) => "{$d->reference_type}#{$d->reference_id}", array_slice($duplicates, 0, 20)),
            ]);

            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS je_active_ref_unique ON journal_entries(reference_type, reference_id)
            WHERE is_reversed = 0 AND reference_type IN '.self::TYPES);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS je_active_ref_unique');
    }
};

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تجميع استهلاك البوت شهرياً (E13.7) قبل تقليم الصفوف الخام: كل شهر مكتمل (أو --month) يُعاد حسابه من الخام
 * ما دام موجوداً، فالأمر متكرر الأمان. شهر قُلّم خامه يبقى تجميعه كما هو.
 */
class WhatsAppUsageRollup extends Command
{
    protected $signature = 'whatsapp:usage-rollup {--month= : شهر واحد بصيغة YYYY-MM}';

    protected $description = 'تجميع استهلاك البوت شهرياً قبل تقليم الخام';

    public function handle(): int
    {
        $current = now()->format('Y-m');
        $rows = DB::table('wa_bot_usage')
            ->selectRaw("strftime('%Y-%m', created_at) as month, source, model, count(*) as replies, sum(calls) as calls,
                sum(prompt_tokens) as prompt_tokens, sum(output_tokens) as output_tokens, sum(cache_read_tokens) as cache_read_tokens,
                sum(cache_write_tokens) as cache_write_tokens, sum(case when leaked then 1 else 0 end) as leaked_count,
                sum(latency_ms) as latency_sum_ms, sum(case when latency_ms > 0 then 1 else 0 end) as latency_n")
            ->when($this->option('month'), fn ($q, $m) => $q->whereRaw("strftime('%Y-%m', created_at) = ?", [$m]),
                fn ($q) => $q->whereRaw("strftime('%Y-%m', created_at) < ?", [$current]))
            ->groupBy('month', 'source', 'model')->get();

        foreach ($rows as $r) {
            DB::table('wa_bot_usage_monthly')->updateOrInsert(
                ['month' => $r->month, 'source' => $r->source, 'model' => (string) $r->model],
                ['replies' => (int) $r->replies, 'calls' => (int) $r->calls, 'prompt_tokens' => (int) $r->prompt_tokens,
                    'output_tokens' => (int) $r->output_tokens, 'cache_read_tokens' => (int) $r->cache_read_tokens,
                    'cache_write_tokens' => (int) $r->cache_write_tokens, 'leaked_count' => (int) $r->leaked_count,
                    'latency_sum_ms' => (int) $r->latency_sum_ms, 'latency_n' => (int) $r->latency_n, 'updated_at' => now(), 'created_at' => now()],
            );
        }
        $this->line('صفوف مجمّعة: '.$rows->count());

        return self::SUCCESS;
    }
}

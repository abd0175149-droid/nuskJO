<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** تجميع شهري لاستهلاك البوت (E13): يبقى بعد تقليم الصفوف الخام، فلا تتغيّر إجماليات الكلفة */
class WaBotUsageMonthly extends Model
{
    protected $table = 'wa_bot_usage_monthly';

    protected $fillable = [
        'month', 'source', 'model', 'replies', 'calls', 'prompt_tokens', 'output_tokens',
        'cache_read_tokens', 'cache_write_tokens', 'leaked_count', 'latency_sum_ms', 'latency_n',
    ];
}

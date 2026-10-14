<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class WaBotUsage extends Model
{
    use MassPrunable;

    protected $table = 'wa_bot_usage';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'source', 'model', 'calls',
        'prompt_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'tools_used', 'leaked', 'latency_ms', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime', 'tools_used' => 'array', 'leaked' => 'boolean'];

    /**
     * الخام أقدم من 12 شهراً (من أول الشهر) يُحذف بعد تجميعه وحده (E13): شهر بلا صف في wa_bot_usage_monthly
     * لا يُمس، فإجماليات الكلفة في التحليلات لا تتغيّر.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths((int) config('retention.bot_usage_raw_months', 12))->startOfMonth())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('wa_bot_usage_monthly')
                ->whereRaw("wa_bot_usage_monthly.month = strftime('%Y-%m', wa_bot_usage.created_at)"));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** حادثة قاطع مزوّد الذكاء (E12): المزوّد والنوع والأوقات والأعداد فقط، بلا بيانات عملاء */
class WaLlmIncident extends Model
{
    protected $fillable = [
        'provider', 'model', 'kind', 'http_status', 'reason', 'shadow', 'opened_at', 'open_until',
        'closed_at', 'close_reason', 'failed_probes', 'handoffs', 'opened_by', 'closed_by',
    ];

    protected $casts = [
        'shadow' => 'boolean',
        'opened_at' => 'datetime',
        'open_until' => 'datetime',
        'closed_at' => 'datetime',
    ];
}

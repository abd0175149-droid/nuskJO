<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'agent_id', 'item_type', 'service_id',
        'description', 'statement', 'quantity',
        'unit_price_sar', 'unit_price_jod',
        'sell_price_jod',
        'total_cost_sar', 'total_cost_jod', 'total_sell_jod',
        'sort_order', 'created_at',
    ];

    protected $casts = [
        'unit_price_sar' => 'decimal:2',
        'unit_price_jod' => 'decimal:3',
        'sell_price_jod' => 'decimal:3',
        'total_cost_sar' => 'decimal:2',
        'total_cost_jod' => 'decimal:3',
        'total_sell_jod' => 'decimal:3',
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}

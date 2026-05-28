<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'item_type', 'service_id', 'violation_id',
        'description', 'quantity', 'unit_price_sar', 'sell_price_jod',
        'total_cost_sar', 'total_sell_jod', 'sort_order', 'created_at',
    ];

    protected $casts = [
        'unit_price_sar' => 'decimal:2',
        'sell_price_jod' => 'decimal:3',
        'total_cost_sar' => 'decimal:2',
        'total_sell_jod' => 'decimal:3',
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function violation(): BelongsTo { return $this->belongsTo(Violation::class); }

    public function isService(): bool { return $this->item_type === 'service'; }
    public function isViolation(): bool { return $this->item_type === 'violation'; }
}

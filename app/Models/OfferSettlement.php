<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تسوية إقفال العرض بعد العودة — تُعتمد بقيد واحد */
class OfferSettlement extends Model
{
    protected $fillable = ['offer_id', 'lines', 'inputs', 'total_jod', 'journal_entry_id', 'approved_by', 'approved_at'];

    protected $casts = [
        'lines' => 'array',
        'inputs' => 'array',
        'total_jod' => 'float',
        'approved_at' => 'datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

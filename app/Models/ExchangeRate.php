<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = ['rate_date', 'sar_to_jod', 'jod_to_sar', 'set_by', 'notes'];

    protected $casts = [
        'rate_date' => 'date',
        'sar_to_jod' => 'decimal:6',
        'jod_to_sar' => 'decimal:6',
    ];

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public static function today(): ?self
    {
        return static::where('rate_date', now()->toDateString())->first()
            ?? static::orderByDesc('rate_date')->first();
    }
}

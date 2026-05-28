<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvanceInstallment extends Model
{
    protected $fillable = [
        'advance_id', 'employee_id', 'month', 'year', 'amount', 'is_paid',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'is_paid' => 'boolean',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeUnpaid($query) { return $query->where('is_paid', false); }

    public function scopeForMonth($query, int $month, int $year)
    {
        return $query->where('month', $month)->where('year', $year);
    }
}

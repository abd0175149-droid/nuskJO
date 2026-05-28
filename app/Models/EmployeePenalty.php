<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePenalty extends Model
{
    protected $fillable = [
        'penalty_number', 'employee_id', 'penalty_type',
        'deduction_days', 'deduction_amount', 'penalty_date',
        'reason', 'is_deducted', 'created_by',
    ];

    protected $casts = [
        'penalty_date' => 'date',
        'deduction_amount' => 'decimal:3',
        'is_deducted' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNotDeducted($query) { return $query->where('is_deducted', false); }
    public function isWarning(): bool { return $this->penalty_type === 'warning'; }
    public function isDeduction(): bool { return $this->penalty_type === 'deduction'; }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_id', 'employee_id',
        'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowance',
        'overtime_amount', 'bonus',
        'late_deduction', 'absence_deduction', 'unpaid_leave_deduction',
        'advance_deduction', 'penalty_deduction',
        'total_earnings', 'total_deductions', 'net_salary',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:3',
        'housing_allowance' => 'decimal:3',
        'transport_allowance' => 'decimal:3',
        'other_allowance' => 'decimal:3',
        'overtime_amount' => 'decimal:3',
        'bonus' => 'decimal:3',
        'late_deduction' => 'decimal:3',
        'absence_deduction' => 'decimal:3',
        'unpaid_leave_deduction' => 'decimal:3',
        'advance_deduction' => 'decimal:3',
        'penalty_deduction' => 'decimal:3',
        'total_earnings' => 'decimal:3',
        'total_deductions' => 'decimal:3',
        'net_salary' => 'decimal:3',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

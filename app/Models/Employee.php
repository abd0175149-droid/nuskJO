<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'employee_number', 'user_id', 'department_id', 'shift_id',
        'job_title', 'hire_date', 'contract_type', 'country', 'currency',
        'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowance',
        'overtime_calc_method', 'overtime_hourly_rate', 'overtime_multiplier',
        'custom_grace_minutes',
        'bank_name', 'bank_account', 'bank_swift',
        'national_id', 'passport_number', 'account_id',
        'notes', 'is_active',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'basic_salary' => 'decimal:3',
        'housing_allowance' => 'decimal:3',
        'transport_allowance' => 'decimal:3',
        'other_allowance' => 'decimal:3',
        'overtime_hourly_rate' => 'decimal:3',
        'overtime_multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ======================== العلاقات ========================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function shiftOverrides(): HasMany
    {
        return $this->hasMany(EmployeeShiftOverride::class);
    }

    // ======================== Helpers ========================

    /**
     * إجمالي الراتب الشامل (أساسي + بدلات)
     */
    public function getTotalSalaryAttribute(): float
    {
        return $this->basic_salary + $this->housing_allowance
             + $this->transport_allowance + $this->other_allowance;
    }

    /**
     * فترة السماح الفعلية (مخصصة أو من الوردية)
     */
    public function getEffectiveGraceMinutesAttribute(): int
    {
        if (!is_null($this->custom_grace_minutes)) {
            return $this->custom_grace_minutes;
        }
        return $this->shift?->grace_minutes ?? 15;
    }

    // ======================== Scopes ========================

    public function scopeActive($query) { return $query->where('is_active', true); }

    public function scopeByCountry($query, string $country)
    {
        return $query->where('country', $country);
    }

    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }
}

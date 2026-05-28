<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'name', 'code', 'start_time', 'end_time', 'grace_minutes',
        'working_days', 'country', 'break_minutes', 'is_flexible',
        'min_hours', 'is_active',
    ];

    protected $casts = [
        'working_days' => 'array',
        'is_flexible' => 'boolean',
        'is_active' => 'boolean',
        'min_hours' => 'decimal:2',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(EmployeeShiftOverride::class);
    }

    public function scopeActive($query) { return $query->where('is_active', true); }
}

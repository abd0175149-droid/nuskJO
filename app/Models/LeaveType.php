<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'name', 'code', 'country', 'default_days', 'is_paid',
        'is_active', 'requires_attachment', 'max_consecutive_days', 'description',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive($query) { return $query->where('is_active', true); }

    /**
     * أنواع الإجازات المتاحة لبلد معين (SA/JO)
     */
    public function scopeForCountry($query, string $country)
    {
        return $query->whereIn('country', [$country, 'ALL']);
    }
}

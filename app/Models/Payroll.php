<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    protected $fillable = [
        'payroll_number', 'month', 'year', 'currency',
        'total_basic', 'total_allowances', 'total_deductions', 'total_net',
        'employees_count', 'status', 'rejection_reason', 'notes',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'total_basic' => 'decimal:3',
        'total_allowances' => 'decimal:3',
        'total_deductions' => 'decimal:3',
        'total_net' => 'decimal:3',
        'approved_at' => 'datetime',
    ];

    // ======================== العلاقات ========================

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ======================== HasApproval (نفس نمط Transfer) ========================

    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function scopeDraft($query) { return $query->where('status', 'draft'); }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isDraft(): bool { return $this->status === 'draft'; }

    public function approve(User $approver): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $approver, string $reason = ''): void
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * تحويل من مسودة إلى معلق (للاعتماد)
     */
    public function submit(): void
    {
        $this->update(['status' => 'pending']);
    }

    /**
     * وصف الفترة (مثل: "مايو 2026")
     */
    public function getPeriodLabelAttribute(): string
    {
        $months = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                   'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return ($months[$this->month] ?? '') . ' ' . $this->year;
    }
}

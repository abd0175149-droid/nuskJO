<?php

namespace App\Traits;

use App\Models\User;

/**
 * HasApproval Trait
 * يُضاف للموديلات التي تحتاج نظام اعتماد (Maker-Checker)
 * يدعم: pending → approved → editing → pending (دورة تعديل بعد الاعتماد)
 */
trait HasApproval
{
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeEditing($query)
    {
        return $query->where('status', 'editing');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function modifier()
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

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
     * بدء التعديل بعد الاعتماد
     * يحفظ القيم الأصلية ويغير الحالة إلى editing
     */
    public function startEditing(User $modifier): void
    {
        $updateData = [
            'status' => 'editing',
            'modified_by' => $modifier->id,
            'modified_at' => now(),
        ];

        // حفظ القيم الأصلية كـ JSON إذا كان العمود موجوداً
        if (\Schema::hasColumn($this->getTable(), 'original_values')) {
            $updateData['original_values'] = json_encode($this->getOriginal());
        }

        $this->update($updateData);
    }

    /**
     * إعادة تقديم للاعتماد بعد التعديل
     */
    public function resubmitForApproval(): void
    {
        $this->update([
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isEditing(): bool
    {
        return $this->status === 'editing';
    }

    /**
     * هل يمكن تعديل هذه العملية؟
     */
    public function canBeEdited(): bool
    {
        return $this->isApproved();
    }
}

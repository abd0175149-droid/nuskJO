<?php

namespace App\Traits;

use App\Exceptions\ApprovalConflict;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * HasApproval Trait
 * يُضاف للموديلات التي تحتاج نظام اعتماد (Maker-Checker)
 * يدعم: pending → approved → editing → pending (دورة تعديل بعد الاعتماد)
 *
 * كل انتقال ذري: UPDATE ... WHERE status = <من>، فإن غيّر مستخدم آخر الحالة قبلنا لا يُكتب شيء
 * ويُرمى ApprovalConflict. استدعِ الانتقال أول كتابة داخل المعاملة، قبل القيد وحركات الذمم.
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

    /** انتقال ذري من حالة إلى أخرى؛ false إن لم تعد الحالة كما قُرئت. */
    public function transitionStatus(string $from, string $to, array $extra = []): bool
    {
        $n = static::query()->whereKey($this->getKey())->where('status', $from)
            ->update($extra + ['status' => $to, 'updated_at' => now()]);

        if ($n === 1) {
            $this->refresh();

            return true;
        }

        return false;
    }

    /** فصل المهام: لا يعتمد المنشئ مستنده إلا المدير. */
    public function approvableBy(User $user): bool
    {
        return $user->isAdmin() || (int) $this->created_by !== (int) $user->id;
    }

    private function transitionOrFail(string $from, string $to, array $extra = []): void
    {
        if (!$this->transitionStatus($from, $to, $extra)) {
            throw new ApprovalConflict;
        }
    }

    public function approve(User $approver): void
    {
        $this->transitionOrFail('pending', 'approved', [
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $approver, string $reason = ''): void
    {
        $this->transitionOrFail('pending', 'rejected', [
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
        $extra = [
            'modified_by' => $modifier->id,
            'modified_at' => now(),
        ];

        // حفظ القيم الأصلية كـ JSON إذا كان العمود موجوداً
        if (Schema::hasColumn($this->getTable(), 'original_values')) {
            $extra['original_values'] = json_encode($this->getOriginal());
        }

        $this->transitionOrFail('approved', 'editing', $extra);
    }

    /**
     * إعادة تقديم للاعتماد بعد التعديل
     */
    public function resubmitForApproval(): void
    {
        $this->transitionOrFail('editing', 'pending', [
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

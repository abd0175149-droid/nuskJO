<?php

namespace App\Models;

use App\Traits\HasApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends Model
{
    use SoftDeletes, HasApproval;

    protected $fillable = [
        'transfer_number', 'agent_id', 'amount_sar', 'cost_jod',
        'exchange_rate', 'payment_method', 'reference_number',
        'transfer_date', 'notes', 'status', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
        'modified_by', 'modified_at', 'original_values',
        'difference_amount', 'difference_type',
        'expense_id', 'expense_category_id', 'revenue_account_id',
    ];

    protected $casts = [
        'amount_sar' => 'decimal:2',
        'cost_jod' => 'decimal:3',
        'exchange_rate' => 'decimal:6',
        'difference_amount' => 'decimal:3',
        'transfer_date' => 'date',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'original_values' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }

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
}

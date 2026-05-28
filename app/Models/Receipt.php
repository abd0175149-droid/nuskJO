<?php

namespace App\Models;

use App\Traits\HasApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receipt extends Model
{
    use SoftDeletes, HasApproval;

    protected $fillable = [
        'receipt_number', 'client_id', 'amount_jod', 'bank_commission',
        'payment_method', 'reference_number', 'receipt_date', 'bank_name',
        'check_date', 'notes', 'status', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
        'modified_by', 'modified_at', 'original_values',
    ];

    protected $casts = [
        'amount_jod' => 'decimal:3',
        'bank_commission' => 'decimal:3',
        'receipt_date' => 'date',
        'check_date' => 'date',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'original_values' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

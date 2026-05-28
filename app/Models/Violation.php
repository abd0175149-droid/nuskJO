<?php

namespace App\Models;

use App\Traits\HasApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Violation extends Model
{
    use SoftDeletes, HasApproval;

    protected $fillable = [
        'violation_number', 'agent_id', 'client_id', 'violation_type_id',
        'passport_number', 'passport_name', 'cost_sar', 'violation_date',
        'description', 'notes', 'billing_status', 'invoice_id',
        'status', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'modified_by', 'modified_at', 'original_values',
    ];

    protected $casts = [
        'cost_sar' => 'decimal:2',
        'violation_date' => 'date',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'original_values' => 'array',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function violationType(): BelongsTo { return $this->belongsTo(ViolationType::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function scopeUnbilled($query) { return $query->where('billing_status', 'unbilled')->where('status', 'approved'); }
    public function scopeBilled($query) { return $query->where('billing_status', 'billed'); }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isUnbilled(): bool { return $this->billing_status === 'unbilled' && $this->status === 'approved'; }

    public function approve(): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function reject(string $reason = ''): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }
}

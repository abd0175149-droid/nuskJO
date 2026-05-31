<?php

namespace App\Models;

use App\Traits\HasApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Disbursement extends Model
{
    use SoftDeletes, HasApproval;

    protected $fillable = [
        'disbursement_number', 'account_id', 'agent_id',
        'amount', 'currency', 'amount_sar', 'exchange_rate',
        'payment_method', 'reference_number', 'disbursement_date',
        'description', 'notes', 'status', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
        'modified_by', 'modified_at', 'original_values',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'amount_sar' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'disbursement_date' => 'date',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'original_values' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

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
}

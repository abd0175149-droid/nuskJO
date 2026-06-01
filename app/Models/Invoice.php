<?php

namespace App\Models;

use App\Traits\HasApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes, HasApproval;

    protected $fillable = [
        'invoice_number', 'client_id', 'client_phone', 'trip_date',
        'agent_id', // legacy — الآن الوكلاء في البنود
        'exchange_rate_snapshot',
        'subtotal_sar', 'discount_sar', 'total_sar', 'total_jod',
        'total_sell_jod', 'total_cost_sar', 'total_cost_jod', 'discount_jod',
        'services_cost_sar',
        'profit_sar', 'profit_jod',
        'invoice_date', 'due_date', 'notes', 'status', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
        'modified_by', 'modified_at', 'original_values',
    ];

    protected $casts = [
        'exchange_rate_snapshot' => 'decimal:6',
        'subtotal_sar' => 'decimal:2',
        'discount_sar' => 'decimal:2',
        'total_sar' => 'decimal:2',
        'total_jod' => 'decimal:3',
        'total_sell_jod' => 'decimal:3',
        'total_cost_sar' => 'decimal:2',
        'total_cost_jod' => 'decimal:3',
        'discount_jod' => 'decimal:3',
        'services_cost_sar' => 'decimal:2',

        'profit_sar' => 'decimal:2',
        'profit_jod' => 'decimal:3',
        'invoice_date' => 'date',
        'trip_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'modified_at' => 'datetime',
        'original_values' => 'array',
    ];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    // legacy — للتوافق مع الكود القديم
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }

    public function items(): HasMany { return $this->hasMany(InvoiceItem::class)->orderBy('sort_order'); }

    /**
     * جلب كل الوكلاء المشاركين في الفاتورة
     */
    public function getAgentsAttribute()
    {
        return Agent::whereIn('id', $this->items()->pluck('agent_id')->unique()->filter())->get();
    }

    public function serviceItems(): HasMany
    {
        return $this->items()->where('item_type', 'service');
    }

    public function scopeDraft($query) { return $query->where('status', 'draft'); }
    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
}

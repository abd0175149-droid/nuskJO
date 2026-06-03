<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name', 'code', 'phone', 'email', 'address',
        'city', 'country', 'currency', 'balance_jod', 'credit_limit_jod',
        'notes', 'is_active', 'created_by', 'agent_id',
        'nationality', 'id_number', 'contact_person', 'account_id',
    ];

    protected $casts = [
        'balance_jod' => 'decimal:3',
        'credit_limit_jod' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }



    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ledgerEntries()
    {
        return LedgerEntry::where('entity_type', 'client')
            ->where('entity_id', $this->id);
    }
}

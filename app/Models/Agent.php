<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'name', 'code', 'phone', 'email', 'address',
        'city', 'country', 'currency', 'balance_sar', 'notes',
        'is_active', 'created_by', 'contact_person', 'account_id',
    ];

    protected $casts = [
        'balance_sar' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
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
        return LedgerEntry::where('entity_type', 'agent')
            ->where('entity_id', $this->id);
    }
}

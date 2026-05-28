<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'entry_date', 'entity_type', 'entity_id', 'transaction_type',
        'transaction_id', 'debit', 'credit', 'balance_after',
        'currency', 'description', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'datetime',
        'debit' => 'decimal:3',
        'credit' => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];
}

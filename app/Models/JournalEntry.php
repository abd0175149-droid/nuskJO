<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_number', 'entry_date', 'description',
        'reference_type', 'reference_id',
        'total_debit', 'total_credit', 'created_by',
        'reversal_of', 'is_reversed',
    ];

    protected $casts = [
        'entry_date' => 'datetime',
        'total_debit' => 'decimal:3',
        'total_credit' => 'decimal:3',
        'is_reversed' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * التحقق من أن القيد متوازن
     */
    public function isBalanced(): bool
    {
        return round($this->total_debit, 3) === round($this->total_credit, 3);
    }
}

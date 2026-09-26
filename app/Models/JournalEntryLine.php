<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'description',
    ];

    protected $casts = [
        'debit' => 'decimal:3',
        'credit' => 'decimal:3',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * سطور القيود الحيّة فقط — للاستعلامات التي تدمج journal_entries بـ join.
     * انظر JournalEntry::scopeLive لتفسير سبب الاستثناء.
     */
    public function scopeLiveEntries($q)
    {
        return $q->where('journal_entries.is_reversed', 0)
            ->where(fn ($w) => $w->whereNull('journal_entries.reference_type')
                ->orWhere('journal_entries.reference_type', '!=', 'reversal'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

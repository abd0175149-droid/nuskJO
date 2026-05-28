<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'code', 'name', 'type', 'parent_id', 'is_system',
        'is_active', 'balance', 'currency', 'description',
    ];

    protected $casts = [
        'balance' => 'decimal:3',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code');
    }

    /**
     * تحميل الأبناء بشكل تكراري (للشجرة)
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * حساب الرصيد الفعلي من القيود
     */
    public function calculateBalance(): float
    {
        $totalDebit = $this->journalLines()->sum('debit');
        $totalCredit = $this->journalLines()->sum('credit');

        // الأصول والمصروفات: رصيد مدين (debit - credit)
        // الالتزامات وحقوق الملكية والإيرادات: رصيد دائن (credit - debit)
        if (in_array($this->type, ['asset', 'expense'])) {
            return $totalDebit - $totalCredit;
        }

        return $totalCredit - $totalDebit;
    }

    /**
     * حساب المجاميع لفترة معينة (لميزان المراجعة)
     */
    public function totalsForPeriod(?string $from = null, ?string $to = null): array
    {
        $baseQuery = $this->journalLines()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id');

        if ($from) {
            $baseQuery->where('journal_entries.entry_date', '>=', $from);
        }
        if ($to) {
            $baseQuery->where('journal_entries.entry_date', '<=', $to);
        }

        $debitQuery = clone $baseQuery;
        $creditQuery = clone $baseQuery;

        return [
            'total_debit' => (float) $debitQuery->sum('journal_entry_lines.debit'),
            'total_credit' => (float) $creditQuery->sum('journal_entry_lines.credit'),
        ];
    }
}

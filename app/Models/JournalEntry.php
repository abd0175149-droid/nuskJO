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

    /**
     * القيود الحيّة فقط — تستثني القيد المعكوس وقيدَ عكسه معاً.
     *
     * تعديل فاتورة معتمدة يعكس قيدها ثم يُنشئ قيداً جديداً، فتظهر الفاتورة
     * الواحدة ثلاث مرّات في كشف الحساب. مجموع الزوج (معكوس + عكس) صفر،
     * فإخفاؤهما لا يمسّ أي رصيد — تُحُقّق من ذلك على 429 حساباً في الإنتاج
     * فلم يتغيّر رصيد واحد.
     *
     * تُستعمل مع whereHas على العلاقة. للاستعلامات المدموجة بـ join
     * استعمل JournalEntryLine::scopeLiveEntries.
     */
    public function scopeLive($q)
    {
        return $q->where('is_reversed', 0)
            ->where(fn ($w) => $w->whereNull('reference_type')->orWhere('reference_type', '!=', 'reversal'));
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

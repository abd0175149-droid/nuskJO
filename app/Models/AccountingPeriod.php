<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'year', 'month', 'status', 'closed_by', 'closed_at', 'closing_entry_number',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * هل الفترة مقفلة؟
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * هل التاريخ المعطى يقع في فترة مقفلة؟
     */
    public static function isDateLocked(string $date): bool
    {
        $y = (int) date('Y', strtotime($date));
        $m = (int) date('n', strtotime($date));

        // تحقق من إقفال السنة كاملة (month=0)
        $yearLocked = self::where('year', $y)
            ->where('month', 0)
            ->where('status', 'closed')
            ->exists();
        if ($yearLocked) return true;

        // تحقق من إقفال الشهر المحدد
        return self::where('year', $y)
            ->where('month', $m)
            ->where('status', 'closed')
            ->exists();
    }
}

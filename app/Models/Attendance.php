<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id', 'date',
        'check_in', 'check_out',
        'check_in_ip', 'check_in_lat', 'check_in_lng', 'check_in_method',
        'check_out_ip', 'check_out_lat', 'check_out_lng', 'check_out_method',
        'worked_hours', 'late_minutes', 'overtime_minutes',
        'status', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'check_in_lat' => 'decimal:7',
        'check_in_lng' => 'decimal:7',
        'check_out_lat' => 'decimal:7',
        'check_out_lng' => 'decimal:7',
        'worked_hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ======================== Scopes ========================

    public function scopePresent($query) { return $query->where('status', 'present'); }
    public function scopeAbsent($query) { return $query->where('status', 'absent'); }
    public function scopeLate($query) { return $query->where('status', 'late'); }
    public function scopeForDate($query, $date) { return $query->where('date', $date); }
    public function scopeForMonth($query, int $month, int $year)
    {
        return $query->whereMonth('date', $month)->whereYear('date', $year);
    }

    // ======================== Helpers ========================

    public function isCheckedIn(): bool { return !is_null($this->check_in); }
    public function isCheckedOut(): bool { return !is_null($this->check_out); }
    public function isComplete(): bool { return $this->isCheckedIn() && $this->isCheckedOut(); }
}

<?php

namespace App\Observers;

use App\Models\Attendance;
use Illuminate\Support\Facades\Log;

class AttendanceObserver
{
    /**
     * عند تحديث سجل الحضور (تسجيل الانصراف)
     * يحسب: ساعات العمل، التأخير (مع فترة السماح)، العمل الإضافي
     */
    public function updated(Attendance $attendance): void
    {
        // فقط عند تسجيل الانصراف
        if (!$attendance->check_out || !$attendance->check_in) return;

        // تجنب الحلقة اللانهائية
        if (!$attendance->wasChanged('check_out')) return;

        try {
            $employee = $attendance->employee;
            if (!$employee) return;

            $shift = $employee->shift;
            if (!$shift) return;

            // تحقق من override لهذا اليوم
            $dayOfWeek = $attendance->date->dayOfWeek; // 0=أحد
            $override = $employee->shiftOverrides()
                ->where('day_of_week', $dayOfWeek)
                ->first();

            $shiftStart = $override?->start_time ?? $shift->start_time;
            $shiftEnd = $override?->end_time ?? $shift->end_time;

            // 1. حساب ساعات العمل
            $checkIn = $attendance->check_in;
            $checkOut = $attendance->check_out;
            $workedMinutes = $checkIn->diffInMinutes($checkOut);
            $workedHours = round($workedMinutes / 60, 2);

            // 2. فترة السماح الفعلية
            $graceMinutes = $employee->effective_grace_minutes;

            // 3. حساب التأخير
            $lateMinutes = 0;
            if (!$shift->is_flexible) {
                $shiftStartTime = \Carbon\Carbon::parse($attendance->date->format('Y-m-d') . ' ' . $shiftStart);
                $diff = $checkIn->diffInMinutes($shiftStartTime, false);

                if ($diff < 0) { // وصل متأخر
                    $actualLate = abs($diff) - $graceMinutes;
                    $lateMinutes = max(0, $actualLate);
                }
            }

            // 4. حساب العمل الإضافي
            $overtimeMinutes = 0;
            $shiftEndTime = \Carbon\Carbon::parse($attendance->date->format('Y-m-d') . ' ' . $shiftEnd);
            if ($checkOut->greaterThan($shiftEndTime)) {
                $overtimeMinutes = $shiftEndTime->diffInMinutes($checkOut);
            }

            // 5. تحديد الحالة
            $status = 'present';
            if ($shift->is_flexible) {
                // الوردية المرنة: فحص الحد الأدنى من الساعات
                if ($shift->min_hours && $workedHours < $shift->min_hours) {
                    $status = 'half_day';
                }
            } else {
                if ($lateMinutes > 0) {
                    $status = 'late';
                }
                if ($workedHours < 4) {
                    $status = 'half_day';
                }
            }

            // تحديث بدون trigger observer مرة ثانية
            Attendance::withoutEvents(function () use ($attendance, $workedHours, $lateMinutes, $overtimeMinutes, $status) {
                $attendance->update([
                    'worked_hours' => $workedHours,
                    'late_minutes' => $lateMinutes,
                    'overtime_minutes' => $overtimeMinutes,
                    'status' => $status,
                ]);
            });

            Log::info("[AttendanceObserver] ✅ {$attendance->employee->employee_number}: {$workedHours}h, late:{$lateMinutes}m, OT:{$overtimeMinutes}m → {$status}");
        } catch (\Throwable $e) {
            Log::error("[AttendanceObserver] خطأ: {$e->getMessage()}");
        }
    }
}

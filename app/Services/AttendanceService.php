<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLocation;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * حساب المسافة بين نقطتين بالإحداثيات (Haversine formula) بالمتر
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000; // بالمتر
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $dLat = $lat2 - $lat1;
        $dLng = $lng2 - $lng1;

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos($lat1) * cos($lat2) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * التحقق مما إذا كان الموقع الجغرافي ضمن أي من المواقع المعتمدة
     */
    public function validateGeolocation($lat, $lng): bool
    {
        $locations = AttendanceLocation::where('is_active', true)
            ->where('type', 'geo')
            ->get();

        if ($locations->isEmpty()) {
            return false;
        }

        foreach ($locations as $location) {
            $distance = $this->calculateDistance($lat, $lng, $location->latitude, $location->longitude);
            if ($distance <= $location->radius_meters) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق مما إذا كان عنوان IP ضمن النطاقات المعتمدة
     */
    public function validateIp($ip): bool
    {
        $locations = AttendanceLocation::where('is_active', true)
            ->where('type', 'ip')
            ->get();

        if ($locations->isEmpty()) {
            return false;
        }

        foreach ($locations as $location) {
            // Check exact IP or IP range (simplified logic)
            if ($location->ip_range === $ip) {
                return true;
            }
            // For true CIDR check, additional logic would be needed.
            // Using a simple check for now.
        }

        return false;
    }

    /**
     * تحديد الوردية الخاصة بالموظف لهذا اليوم
     */
    public function getEmployeeShiftForDay(Employee $employee, Carbon $date)
    {
        $dayOfWeek = $date->dayOfWeek; // 0 (Sunday) - 6 (Saturday)

        // التحقق من overrides أولاً
        $override = $employee->shiftOverrides()->where('day_of_week', $dayOfWeek)->first();
        if ($override && $override->shift_id) {
            return $override->shift;
        }

        // إرجاع الوردية الافتراضية
        return $employee->shift;
    }

    /**
     * جلب دقائق السماح الفعالة للموظف
     */
    public function getEffectiveGraceMinutes(Employee $employee)
    {
        if ($employee->custom_grace_minutes !== null) {
            return $employee->custom_grace_minutes;
        }

        return $employee->shift ? $employee->shift->grace_minutes : 0;
    }

    /**
     * تسجيل حضور موظف
     */
    public function checkIn(Employee $employee, array $data)
    {
        $date = Carbon::now()->toDateString();

        // التحقق إذا كان الموظف قد سجل حضور اليوم
        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        if ($attendance->exists && $attendance->check_in) {
            throw new \Exception('تم تسجيل الحضور مسبقاً لهذا اليوم.');
        }

        $attendance->check_in = Carbon::now();
        $attendance->check_in_ip = $data['ip'] ?? null;
        $attendance->check_in_lat = $data['lat'] ?? null;
        $attendance->check_in_lng = $data['lng'] ?? null;
        $attendance->check_in_method = $data['method'] ?? 'manual';
        
        // Initial status, observer will update based on shift time
        $attendance->status = 'present';
        $attendance->save();

        return $attendance;
    }

    /**
     * تسجيل انصراف موظف
     */
    public function checkOut(Attendance $attendance, array $data)
    {
        if ($attendance->check_out) {
            throw new \Exception('تم تسجيل الانصراف مسبقاً لهذا اليوم.');
        }

        $attendance->check_out = Carbon::now();
        $attendance->check_out_ip = $data['ip'] ?? null;
        $attendance->check_out_lat = $data['lat'] ?? null;
        $attendance->check_out_lng = $data['lng'] ?? null;
        $attendance->check_out_method = $data['method'] ?? 'manual';
        
        $attendance->save(); // The observer will calculate worked_hours, late_minutes, overtime

        return $attendance;
    }

    /**
     * حساب الساعات والمقاييس (يُستدعى من الـ Observer)
     */
    public function calculateWorkMetrics(Attendance $attendance)
    {
        if (!$attendance->check_in || !$attendance->check_out) {
            return;
        }

        $employee = $attendance->employee;
        $shift = $this->getEmployeeShiftForDay($employee, Carbon::parse($attendance->date));

        $checkIn = Carbon::parse($attendance->check_in);
        $checkOut = Carbon::parse($attendance->check_out);

        // حساب إجمالي ساعات العمل (بالدقائق أولاً للتبسيط ثم تحويلها لساعات)
        $workedMinutes = $checkIn->diffInMinutes($checkOut);
        
        // خصم وقت الاستراحة إذا كانت وردية غير مرنة، أو إذا كانت مرنة ولم يحدد
        if ($shift) {
            $workedMinutes -= $shift->break_minutes;
        }
        
        $workedMinutes = max(0, $workedMinutes);
        $attendance->worked_hours = round($workedMinutes / 60, 2);

        if (!$shift) {
            return;
        }

        // حساب التأخير إذا لم تكن الوردية مرنة
        if (!$shift->is_flexible) {
            $expectedStartTime = Carbon::parse($attendance->date . ' ' . $shift->start_time);
            $graceMinutes = $this->getEffectiveGraceMinutes($employee);
            $expectedStartTimeWithGrace = $expectedStartTime->copy()->addMinutes($graceMinutes);

            if ($checkIn->greaterThan($expectedStartTimeWithGrace)) {
                $attendance->late_minutes = $expectedStartTime->diffInMinutes($checkIn);
                $attendance->status = 'late';
            } else {
                $attendance->late_minutes = 0;
            }

            // حساب العمل الإضافي
            $expectedEndTime = Carbon::parse($attendance->date . ' ' . $shift->end_time);
            if ($checkOut->greaterThan($expectedEndTime)) {
                $overtimeMinutes = $expectedEndTime->diffInMinutes($checkOut);
                // ممكن تجاهل الإضافي أقل من 30 دقيقة حسب قوانين العمل
                $attendance->overtime_minutes = $overtimeMinutes;
            } else {
                $attendance->overtime_minutes = 0;
            }
        } else {
            // الوردية المرنة
            $attendance->late_minutes = 0; // لا يوجد تأخير في المرنة
            
            if ($shift->min_hours && $attendance->worked_hours > $shift->min_hours) {
                $attendance->overtime_minutes = ($attendance->worked_hours - $shift->min_hours) * 60;
            }
        }
    }
}

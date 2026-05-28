<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    protected AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    /**
     * عرض سجل الحضور (الإدارة)
     */
    public function index(Request $request)
    {
        // Must have permission
        if (!auth()->user()->can('attendance.view')) {
            abort(403);
        }

        $query = Attendance::with(['employee.user', 'employee.department']);

        // فلترة بالموظف
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // فلترة بالتاريخ
        if ($request->filled('date')) {
            $query->where('date', $request->date);
        }

        // فلترة بالحالة
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $attendances = $query->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        $employees = Employee::with('user')->get()->map(function ($emp) {
            return [
                'id' => $emp->id,
                'name' => $emp->user->name,
                'employee_number' => $emp->employee_number,
            ];
        });

        return Inertia::render('Attendance/Index', [
            'attendances' => $attendances,
            'employees' => $employees,
            'filters' => $request->only(['employee_id', 'date', 'status']),
        ]);
    }

    /**
     * تسجيل الدخول (API)
     */
    public function checkIn(Request $request)
    {
        $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'method' => 'required|string|in:ip,geo,webauthn,manual',
        ]);

        $user = auth()->user();
        if (!$user->employee) {
            return response()->json(['message' => 'ليس لديك ملف موظف لتسجيل الحضور'], 403);
        }

        $employee = $user->employee;
        $ip = $request->ip();

        // Validation for Geo or IP (Skipped manual validation for simplicity here, can be added later)
        if ($request->method === 'geo') {
            if (!$request->lat || !$request->lng || !$this->attendanceService->validateGeolocation($request->lat, $request->lng)) {
                return response()->json(['message' => 'موقعك الحالي غير معتمد لتسجيل الحضور'], 403);
            }
        } elseif ($request->method === 'ip') {
            if (!$this->attendanceService->validateIp($ip)) {
                return response()->json(['message' => 'شبكة الاتصال الحالية غير معتمدة لتسجيل الحضور'], 403);
            }
        }

        try {
            $attendance = $this->attendanceService->checkIn($employee, [
                'ip' => $ip,
                'lat' => $request->lat,
                'lng' => $request->lng,
                'method' => $request->method,
            ]);

            return response()->json([
                'message' => 'تم تسجيل الحضور بنجاح',
                'attendance' => $attendance
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * تسجيل الانصراف (API)
     */
    public function checkOut(Request $request)
    {
        $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'method' => 'required|string|in:ip,geo,webauthn,manual',
        ]);

        $user = auth()->user();
        if (!$user->employee) {
            return response()->json(['message' => 'ليس لديك ملف موظف لتسجيل الانصراف'], 403);
        }

        $employee = $user->employee;
        $date = Carbon::now()->toDateString();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $date)
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'لم تقم بتسجيل الحضور اليوم'], 400);
        }

        $ip = $request->ip();

        // Validation similar to checkIn
        if ($request->method === 'geo') {
            if (!$request->lat || !$request->lng || !$this->attendanceService->validateGeolocation($request->lat, $request->lng)) {
                return response()->json(['message' => 'موقعك الحالي غير معتمد لتسجيل الانصراف'], 403);
            }
        } elseif ($request->method === 'ip') {
            if (!$this->attendanceService->validateIp($ip)) {
                return response()->json(['message' => 'شبكة الاتصال الحالية غير معتمدة لتسجيل الانصراف'], 403);
            }
        }

        try {
            $attendance = $this->attendanceService->checkOut($attendance, [
                'ip' => $ip,
                'lat' => $request->lat,
                'lng' => $request->lng,
                'method' => $request->method,
            ]);

            return response()->json([
                'message' => 'تم تسجيل الانصراف بنجاح',
                'attendance' => $attendance
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * حالة حضور اليوم للموظف الحالي (API)
     */
    public function status(Request $request)
    {
        $user = auth()->user();
        if (!$user->employee) {
            return response()->json(['status' => 'none']);
        }

        $date = Carbon::now()->toDateString();
        $attendance = Attendance::where('employee_id', $user->employee->id)
            ->where('date', $date)
            ->first();

        if (!$attendance) {
            return response()->json([
                'status' => 'not_checked_in',
                'server_time' => Carbon::now()->toIso8601String()
            ]);
        }

        if (!$attendance->check_out) {
            return response()->json([
                'status' => 'checked_in',
                'check_in_time' => $attendance->check_in,
                'server_time' => Carbon::now()->toIso8601String()
            ]);
        }

        return response()->json([
            'status' => 'checked_out',
            'check_in_time' => $attendance->check_in,
            'check_out_time' => $attendance->check_out,
            'worked_hours' => $attendance->worked_hours,
            'server_time' => Carbon::now()->toIso8601String()
        ]);
    }

    /**
     * تعديل يدوي من قبل الإدارة
     */
    public function manualEdit(Request $request, Attendance $attendance)
    {
        if (!auth()->user()->can('attendance.manual_edit')) {
            abort(403);
        }

        $validated = $request->validate([
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'status' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validated['check_in']) {
            $attendance->check_in = Carbon::parse($attendance->date . ' ' . $validated['check_in']);
        }

        if ($validated['check_out']) {
            $attendance->check_out = Carbon::parse($attendance->date . ' ' . $validated['check_out']);
        }

        $attendance->status = $validated['status'];
        $attendance->notes = $validated['notes'];
        
        $attendance->save(); // The observer will recalculate hours

        return redirect()->back()->with('success', 'تم تعديل الحضور بنجاح');
    }
}

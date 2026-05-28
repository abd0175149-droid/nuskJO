<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\NumberingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $employees = Employee::with(['user:id,name,email,phone', 'department:id,name', 'shift:id,name'])
            ->when($request->search, function ($q, $s) {
                $q->whereHas('user', fn($q2) => $q2->where('name', 'like', "%{$s}%"))
                  ->orWhere('employee_number', 'like', "%{$s}%")
                  ->orWhere('national_id', 'like', "%{$s}%");
            })
            ->when($request->department_id, fn($q, $d) => $q->where('department_id', $d))
            ->when($request->country, fn($q, $c) => $q->where('country', $c))
            ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $departments = Department::select('id', 'name')->whereIsActive(true)->get();

        return Inertia::render('HR/Employees/Index', [
            'title' => 'الموظفين',
            'employees' => $employees,
            'departments' => $departments,
            'filters' => $request->only(['search', 'department_id', 'country', 'is_active']),
        ]);
    }

    public function create()
    {
        // Only users without an employee profile
        $users = User::whereDoesntHave('employee')->whereIsActive(true)->select('id', 'name', 'email')->get();
        $departments = Department::select('id', 'name')->whereIsActive(true)->get();
        $shifts = Shift::select('id', 'name')->whereIsActive(true)->get();

        return Inertia::render('HR/Employees/Create', [
            'title' => 'إضافة موظف جديد',
            'users' => $users,
            'departments' => $departments,
            'shifts' => $shifts,
        ]);
    }

    public function store(Request $request, NumberingService $numberingService)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:employees,user_id',
            'department_id' => 'nullable|exists:departments,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'job_title' => 'nullable|string|max:150',
            'hire_date' => 'required|date',
            'contract_type' => 'required|in:full_time,part_time,contract',
            'country' => 'required|in:SA,JO',
            'currency' => 'required|in:SAR,JOD',
            
            'basic_salary' => 'required|numeric|min:0',
            'housing_allowance' => 'required|numeric|min:0',
            'transport_allowance' => 'required|numeric|min:0',
            'other_allowance' => 'required|numeric|min:0',
            
            'overtime_calc_method' => 'required|in:fixed,multiplier',
            'overtime_hourly_rate' => 'required_if:overtime_calc_method,fixed|numeric|min:0',
            'overtime_multiplier' => 'required_if:overtime_calc_method,multiplier|numeric|min:1',
            
            'custom_grace_minutes' => 'nullable|integer|min:0',
            
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'bank_swift' => 'nullable|string|max:20',
            
            'national_id' => 'nullable|string|max:30',
            'passport_number' => 'nullable|string|max:30',
            
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($validated, $numberingService) {
            $validated['employee_number'] = $numberingService->generate('EMP');
            Employee::create($validated);
        });

        return redirect()->route('employees.index')->with('success', 'تم إضافة الموظف بنجاح');
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'user', 
            'department', 
            'shift', 
            'leaveBalances.leaveType',
            'advances' => fn($q) => $q->latest()->take(5),
            'attendances' => fn($q) => $q->latest()->take(5),
        ]);

        return Inertia::render('HR/Employees/Show', [
            'title' => 'ملف الموظف',
            'employee' => $employee,
        ]);
    }

    public function edit(Employee $employee)
    {
        $employee->load(['user']);
        
        $users = User::where(function($q) use ($employee) {
            $q->whereDoesntHave('employee')->orWhere('id', $employee->user_id);
        })->whereIsActive(true)->select('id', 'name', 'email')->get();
        
        $departments = Department::select('id', 'name')->whereIsActive(true)->get();
        $shifts = Shift::select('id', 'name')->whereIsActive(true)->get();

        return Inertia::render('HR/Employees/Edit', [
            'title' => 'تعديل موظف',
            'employee' => $employee,
            'users' => $users,
            'departments' => $departments,
            'shifts' => $shifts,
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:employees,user_id,' . $employee->id,
            'department_id' => 'nullable|exists:departments,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'job_title' => 'nullable|string|max:150',
            'hire_date' => 'required|date',
            'contract_type' => 'required|in:full_time,part_time,contract',
            'country' => 'required|in:SA,JO',
            'currency' => 'required|in:SAR,JOD',
            
            'basic_salary' => 'required|numeric|min:0',
            'housing_allowance' => 'required|numeric|min:0',
            'transport_allowance' => 'required|numeric|min:0',
            'other_allowance' => 'required|numeric|min:0',
            
            'overtime_calc_method' => 'required|in:fixed,multiplier',
            'overtime_hourly_rate' => 'required_if:overtime_calc_method,fixed|numeric|min:0',
            'overtime_multiplier' => 'required_if:overtime_calc_method,multiplier|numeric|min:1',
            
            'custom_grace_minutes' => 'nullable|integer|min:0',
            
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'bank_swift' => 'nullable|string|max:20',
            
            'national_id' => 'nullable|string|max:30',
            'passport_number' => 'nullable|string|max:30',
            
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $employee->update($validated);

        return redirect()->route('employees.index')->with('success', 'تم تحديث الموظف بنجاح');
    }

    public function destroy(Employee $employee)
    {
        if ($employee->payrollItems()->count() > 0 || $employee->advances()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف موظف لديه رواتب أو سلف مسجلة.');
        }

        $employee->delete();
        return redirect()->route('employees.index')->with('success', 'تم حذف الموظف بنجاح');
    }

    public function printStatement(Employee $employee)
    {
        // To be implemented in Reports Sprint
        abort(404);
    }
}

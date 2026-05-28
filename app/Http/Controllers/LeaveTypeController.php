<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveTypeController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('settings.view')) {
            abort(403);
        }

        $leaveTypes = LeaveType::orderBy('country')->orderBy('name')->get();

        return Inertia::render('Settings/LeaveTypes', [
            'leaveTypes' => $leaveTypes,
        ]);
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:leave_types,code',
            'country' => 'required|in:SA,JO,ALL',
            'default_days' => 'required|integer|min:0',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'requires_attachment' => 'boolean',
            'max_consecutive_days' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        LeaveType::create($validated);

        return redirect()->back()->with('success', 'تم إضافة نوع الإجازة بنجاح');
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:leave_types,code,' . $leaveType->id,
            'country' => 'required|in:SA,JO,ALL',
            'default_days' => 'required|integer|min:0',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
            'requires_attachment' => 'boolean',
            'max_consecutive_days' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        $leaveType->update($validated);

        return redirect()->back()->with('success', 'تم تحديث نوع الإجازة');
    }

    public function destroy(LeaveType $leaveType)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        if ($leaveType->requests()->exists()) {
            return redirect()->back()->withErrors(['error' => 'لا يمكن حذف نوع إجازة مرتبط بطلبات']);
        }

        $leaveType->delete();

        return redirect()->back()->with('success', 'تم حذف نوع الإجازة');
    }
}

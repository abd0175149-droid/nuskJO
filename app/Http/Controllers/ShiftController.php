<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $shifts = Shift::withCount('employees')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->when($request->country, fn ($q, $c) => $q->where('country', $c))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Shifts/Index', [
            'title' => 'الورديات',
            'shifts' => $shifts,
            'filters' => $request->only(['search', 'country']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:shifts,code',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'grace_minutes' => 'integer|min:0',
            'working_days' => 'required|array',
            'working_days.*' => 'integer|min:0|max:6',
            'country' => 'required|in:SA,JO,ALL',
            'break_minutes' => 'integer|min:0',
            'is_flexible' => 'boolean',
            'min_hours' => 'nullable|numeric|min:1',
            'is_active' => 'boolean',
        ]);

        Shift::create($validated);

        return redirect()->back()->with('success', 'تم إضافة الوردية بنجاح');
    }

    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:shifts,code,' . $shift->id,
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'grace_minutes' => 'integer|min:0',
            'working_days' => 'required|array',
            'working_days.*' => 'integer|min:0|max:6',
            'country' => 'required|in:SA,JO,ALL',
            'break_minutes' => 'integer|min:0',
            'is_flexible' => 'boolean',
            'min_hours' => 'nullable|numeric|min:1',
            'is_active' => 'boolean',
        ]);

        $shift->update($validated);

        return redirect()->back()->with('success', 'تم تحديث الوردية بنجاح');
    }

    public function destroy(Shift $shift)
    {
        if ($shift->employees()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف وردية مرتبطة بموظفين.');
        }

        $shift->delete();
        return redirect()->back()->with('success', 'تم حذف الوردية بنجاح');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceLocationController extends Controller
{
    /**
     * عرض مواقع الحضور
     */
    public function index()
    {
        // Require setting.update permission or create a specific one
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $locations = AttendanceLocation::orderBy('id', 'desc')->get();

        return Inertia::render('Settings/AttendanceLocations', [
            'locations' => $locations
        ]);
    }

    /**
     * إضافة موقع جديد
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:ip,geo',
            'ip_range' => 'nullable|required_if:type,ip|string|max:100',
            'latitude' => 'nullable|required_if:type,geo|numeric',
            'longitude' => 'nullable|required_if:type,geo|numeric',
            'radius_meters' => 'nullable|required_if:type,geo|integer|min:10',
            'is_active' => 'boolean',
        ]);

        try {
            AttendanceLocation::create($validated);
            return redirect()->back()->with('success', 'تم إضافة موقع الحضور بنجاح');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['name' => 'حدث خطأ أثناء الإضافة: ' . $e->getMessage()]);
        }
    }

    /**
     * تحديث الموقع
     */
    public function update(Request $request, AttendanceLocation $location)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:ip,geo',
            'ip_range' => 'nullable|required_if:type,ip|string|max:100',
            'latitude' => 'nullable|required_if:type,geo|numeric',
            'longitude' => 'nullable|required_if:type,geo|numeric',
            'radius_meters' => 'nullable|required_if:type,geo|integer|min:10',
            'is_active' => 'boolean',
        ]);

        try {
            $location->update($validated);
            return redirect()->back()->with('success', 'تم تحديث الموقع بنجاح');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['name' => 'حدث خطأ أثناء التحديث: ' . $e->getMessage()]);
        }
    }

    /**
     * حذف الموقع
     */
    public function destroy(AttendanceLocation $location)
    {
        if (!auth()->user()->can('settings.update')) {
            abort(403);
        }

        $location->delete();

        return redirect()->back()->with('success', 'تم حذف الموقع بنجاح');
    }
}

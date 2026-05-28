<?php

namespace App\Http\Controllers;

use App\Models\ViolationType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ViolationTypeController extends Controller
{
    public function index(Request $request)
    {
        $types = ViolationType::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('ViolationTypes/Index', [
            'title' => 'أنواع المخالفات',
            'types' => $types,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'default_cost_sar' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:1000',
        ]);

        $lastCode = ViolationType::where('code', 'like', 'VLT-%')->orderByDesc('code')->value('code');
        $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
        $validated['code'] = 'VLT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        $validated['is_active'] = true;

        ViolationType::create($validated);
        return redirect()->back()->with('success', 'تم إضافة نوع المخالفة بنجاح');
    }

    public function update(Request $request, ViolationType $violationType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'default_cost_sar' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $violationType->update($validated);
        return redirect()->back()->with('success', 'تم تحديث نوع المخالفة بنجاح');
    }

    public function destroy(ViolationType $violationType)
    {
        if ($violationType->violations()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف نوع مخالفة مرتبط بمخالفات.');
        }
        $violationType->delete();
        return redirect()->back()->with('success', 'تم حذف نوع المخالفة بنجاح');
    }
}

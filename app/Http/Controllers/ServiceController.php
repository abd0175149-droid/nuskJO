<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $services = Service::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Services/Index', [
            'title' => 'الخدمات',
            'services' => $services,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $lastCode = Service::where('code', 'like', 'SRV-%')->orderByDesc('code')->value('code');
        $nextNum = $lastCode ? (int)substr($lastCode, 4) + 1 : 1;
        $validated['code'] = 'SRV-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        $validated['is_active'] = true;
        $validated['default_price_sar'] = 0;
        $validated['default_price_jod'] = 0;

        Service::create($validated);

        return redirect()->back()->with('success', 'تم إضافة الخدمة بنجاح');
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $service->update($validated);

        return redirect()->back()->with('success', 'تم تحديث الخدمة بنجاح');
    }

    public function destroy(Service $service)
    {
        if ($service->invoiceItems()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف خدمة مرتبطة بفواتير.');
        }

        $service->delete();
        return redirect()->back()->with('success', 'تم حذف الخدمة بنجاح');
    }
}

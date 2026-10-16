<?php

namespace App\Http\Controllers;

use App\Services\Ops\SystemStatus;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحة «حالة النظام» (E23) للقراءة فقط: الصلاحية ops.view في كل دالة، والعلم ops_status_page (مطفأ = 404).
 * اللقطة من SystemStatus مخزّنة 60 ثانية؛ «حدّث الآن» يعيد بناءها بحد 6 في الدقيقة.
 */
class OpsStatusController extends Controller
{
    public function index(): Response
    {
        $this->authorizeView();

        return Inertia::render('Ops/Status', ['title' => 'حالة النظام', 'status' => SystemStatus::snapshot()]);
    }

    public function data(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(SystemStatus::snapshot())->header('Cache-Control', 'no-store');
    }

    public function refresh(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(SystemStatus::snapshot(fresh: true))->header('Cache-Control', 'no-store');
    }

    private function authorizeView(): void
    {
        abort_unless(FeatureFlags::on('ops_status_page'), 404);
        abort_unless(auth()->user()->can('ops.view'), 403);
    }
}

<?php

use App\Http\Controllers\InternalMetricsController;
use Illuminate\Support\Facades\Route;

/*
 * مقاييس التشغيل بصيغة Prometheus (E24) خارج مجموعتي web وapi (bootstrap/app.php): لا جلسة ولا كوكي ولا CSRF ولا Inertia.
 * 404 ما لم يُضبط OPS_METRICS_TOKEN ويُرسل بـ Bearer، و30 طلباً في الدقيقة على مخزن الحدود (file، لا SQLite).
 * لا مستهلك اليوم: يُفعَّل بقرار المالك وحده، ويمكن حجبه أيضاً من Cloudflare بقاعدة مسار.
 */
Route::get('internal/metrics', InternalMetricsController::class)->middleware('throttle:30,1,ops-metrics')->name('internal.metrics');

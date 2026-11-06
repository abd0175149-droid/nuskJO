<?php

use App\Http\Controllers\OpenWaWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * webhook بوابة OpenWA (2026-11-06) خارج مجموعتي web وapi (bootstrap/app.php): لا جلسة تُكتب لكل إشعار، ولا كوكي، ولا CSRF.
 * رابط لكل رقم بـ uuid صفه، والحماية بتوقيع HMAC بسر الرقم على الجسم الخام داخل المتحكم.
 * مسارا ميتا (api/whatsapp/webhook) أُزيلا: رقم ميتا اختباري بقرار المالك.
 */
Route::post('api/openwa/webhook/{channel}', [OpenWaWebhookController::class, 'receive'])->name('openwa.webhook');

// تقارير CSP من المتصفحات (E26.4): خارج web للسبب نفسه (لا جلسة ولا CSRF ولا Inertia)، بحد معدّل وحجم.
// المتصفح يرسلها بلا كوكي أحياناً، فلا مصادقة؛ ولا يُحفظ منها إلا التوجيه والمصدر وقالب المسار
Route::post('csp-report', \App\Http\Controllers\CspReportController::class)->middleware('throttle:csp-report')->name('csp.report');

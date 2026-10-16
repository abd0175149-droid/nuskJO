<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * ويبهوك ميتا خارج مجموعتي web وapi (bootstrap/app.php): لا جلسة تُكتب لكل إشعار، ولا كوكي، ولا CSRF.
 * الحماية بتوقيع HMAC على الجسم الخام داخل المتحكم، والرابط نفسه المسجّل عند ميتا.
 */
Route::get('api/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('api/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

// تقارير CSP من المتصفحات (E26.4): خارج web للسبب نفسه (لا جلسة ولا CSRF ولا Inertia)، بحد معدّل وحجم.
// المتصفح يرسلها بلا كوكي أحياناً، فلا مصادقة؛ ولا يُحفظ منها إلا التوجيه والمصدر وقالب المسار
Route::post('csp-report', \App\Http\Controllers\CspReportController::class)->middleware('throttle:csp-report')->name('csp.report');

<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * ويبهوك ميتا خارج مجموعتي web وapi (bootstrap/app.php): لا جلسة تُكتب لكل إشعار، ولا كوكي، ولا CSRF.
 * الحماية بتوقيع HMAC على الجسم الخام داخل المتحكم، والرابط نفسه المسجّل عند ميتا.
 */
Route::get('api/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('api/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

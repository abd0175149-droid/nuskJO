<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// Auth Routes (Guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
});

// صفحات قانونية عامة (تطلبها ميتا) — بلا تسجيل دخول
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/data-deletion', 'legal.data-deletion')->name('legal.data-deletion');

// ويبهوك واتساب في routes/webhooks.php، خارج مجموعة web (بلا جلسة ولا CSRF ولا Inertia)

// Authenticated Routes
Route::middleware(['auth', 'auth.session', 'active', 'mfa', 'authz.fence'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password');

    // Agents & Clients CRUD
    Route::resource('agents', AgentController::class)
        ->middlewareFor(['index', 'show'], 'perm:agents.view')
        ->middlewareFor(['create', 'store'], 'perm:agents.create')
        ->middlewareFor(['edit', 'update'], 'perm:agents.update')
        ->middlewareFor('destroy', 'perm:agents.delete');
    Route::resource('clients', ClientController::class)
        ->middlewareFor(['index', 'show'], 'perm:clients.view')
        ->middlewareFor(['create', 'store'], 'perm:clients.create')
        ->middlewareFor(['edit', 'update'], 'perm:clients.update')
        ->middlewareFor('destroy', 'perm:clients.delete');

    // سندات الصرف (بديل الحوالات والمصاريف)
    Route::resource('disbursements', DisbursementController::class)->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'perm:disbursements.view')
        ->middlewareFor('store', 'perm:disbursements.create')
        ->middlewareFor('destroy', 'perm:admin');
    Route::post('disbursements/{disbursement}/approve', [DisbursementController::class, 'approve'])->name('disbursements.approve')->middleware('perm:disbursements.approve');
    Route::post('disbursements/{disbursement}/reject', [DisbursementController::class, 'reject'])->name('disbursements.reject')->middleware('perm:disbursements.reject');
    Route::post('disbursements/{disbursement}/start-edit', [DisbursementController::class, 'startEdit'])->name('disbursements.start-edit')->middleware('perm:admin');
    Route::put('disbursements/{disbursement}/update-approved', [DisbursementController::class, 'updateApproved'])->name('disbursements.update-approved')->middleware('perm:admin');
    Route::get('disbursements/{disbursement}/print', [DisbursementController::class, 'print'])->name('disbursements.print')->middleware('perm:disbursements.view');

    // سندات القبض
    Route::resource('receipts', ReceiptController::class)->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'perm:receipts.view')
        ->middlewareFor('store', 'perm:receipts.create')
        ->middlewareFor('destroy', 'perm:admin');
    Route::post('receipts/{receipt}/approve', [ReceiptController::class, 'approve'])->name('receipts.approve')->middleware('perm:receipts.approve');
    Route::post('receipts/{receipt}/reject', [ReceiptController::class, 'reject'])->name('receipts.reject')->middleware('perm:receipts.reject');
    Route::post('receipts/{receipt}/start-edit', [ReceiptController::class, 'startEdit'])->name('receipts.start-edit')->middleware('perm:admin');
    Route::put('receipts/{receipt}/update-approved', [ReceiptController::class, 'updateApproved'])->name('receipts.update-approved')->middleware('perm:admin');

    // الخدمات
    Route::resource('services', ServiceController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:services.view')
        ->middlewareFor('store', 'perm:services.create')
        ->middlewareFor('update', 'perm:services.update')
        ->middlewareFor('destroy', 'perm:services.delete');

    // Settings
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index')->middleware('perm:settings.view');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update')->middleware('perm:settings.update');
    Route::post('settings/exchange-rate', [SettingController::class, 'storeExchangeRate'])->name('settings.exchange-rate')->middleware('perm:settings.update');
    Route::post('settings/upload-template', [SettingController::class, 'uploadTemplate'])->name('settings.upload-template')->middleware('perm:settings.update');
    Route::post('settings/brand-asset', [SettingController::class, 'uploadBrandAsset'])->name('settings.brand-asset')->middleware('perm:settings.update');
    Route::get('settings/backups', [\App\Http\Controllers\BackupController::class, 'index'])->name('settings.backups')->middleware('perm:backups.view');
    Route::post('settings/backups/run', [\App\Http\Controllers\BackupController::class, 'runNow'])->name('settings.backups.run')->middleware('perm:backups.run');
    Route::post('settings/backups/ack-restore', [\App\Http\Controllers\BackupController::class, 'acknowledgeRestore'])->name('settings.backups.ack')->middleware('perm:backups.restore_ack');
    Route::get('settings/print-layout', [SettingController::class, 'printLayout'])->name('settings.print-layout')->middleware('perm:settings.view');
    Route::post('settings/print-layout', [SettingController::class, 'savePrintLayout'])->name('settings.save-print-layout')->middleware('perm:settings.update');
    Route::resource('attendance-locations', \App\Http\Controllers\AttendanceLocationController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor(['index', 'store', 'update', 'destroy'], 'perm:settings.update');

    // Invoices
    Route::resource('invoices', \App\Http\Controllers\InvoiceController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:invoices.view')
        ->middlewareFor('store', 'perm:invoices.create')
        ->middlewareFor('update', 'perm:invoices.update')
        ->middlewareFor('destroy', 'perm:admin');
    Route::post('invoices/{invoice}/approve', [\App\Http\Controllers\InvoiceController::class, 'approve'])->name('invoices.approve')->middleware('perm:invoices.approve');
    Route::post('invoices/{invoice}/reject', [\App\Http\Controllers\InvoiceController::class, 'reject'])->name('invoices.reject')->middleware('perm:invoices.reject');
    Route::post('invoices/{invoice}/start-edit', [\App\Http\Controllers\InvoiceController::class, 'startEdit'])->name('invoices.start-edit')->middleware('perm:admin');
    // معاينة فاتورة العرض: السعر والتكلفة لكل وكيل من OfferPricing نفسه الذي يحفظ الفاتورة
    Route::post('invoices/offer-preview', [\App\Http\Controllers\InvoiceController::class, 'offerPreview'])->name('invoices.offer-preview')->middleware('perm:invoices.create');

    // API: تفاصيل فاتورة
    Route::get('api/invoices/{invoice}/details', [\App\Http\Controllers\InvoiceController::class, 'details'])->middleware('perm:invoices.view');
    // فاتورة PDF وإرسالها على واتساب (E32): العلم invoice_whatsapp_send والصلاحيتان في المتحكم، والإرسال بحد wa-files
    Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\InvoiceController::class, 'pdf'])->name('invoices.pdf')->middleware('perm:invoices.view');
    Route::get('api/invoices/{invoice}/whatsapp-targets', [\App\Http\Controllers\InvoiceWhatsAppController::class, 'targets'])->middleware('perm:invoices.view');
    Route::post('api/invoices/{invoice}/send-whatsapp', [\App\Http\Controllers\InvoiceWhatsAppController::class, 'send'])->middleware(['perm:invoices.view', 'throttle:wa-files']);

    // API: FCM Tokens
    Route::post('api/fcm-token', [\App\Http\Controllers\FcmTokenController::class, 'store']);
    Route::delete('api/fcm-token', [\App\Http\Controllers\FcmTokenController::class, 'destroy']);
    Route::post('api/fcm-token/test', [\App\Http\Controllers\FcmTokenController::class, 'test']);

    // API: Notifications
    Route::get('api/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('api/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('api/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead']);

    // Expense Categories
    Route::resource('expense-categories', \App\Http\Controllers\ExpenseCategoryController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:settings.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'perm:settings.update');

    // Reports
    Route::get('reports/builder', [\App\Http\Controllers\ReportController::class, 'builder'])->name('reports.builder')->middleware('perm:reports.view');
    Route::get('reports/trip-date', [\App\Http\Controllers\ReportController::class, 'tripDate'])->name('reports.trip-date')->middleware('perm:reports.trip_date');
    Route::get('api/travelers-today', [\App\Http\Controllers\ReportController::class, 'travelersToday'])->middleware('perm:reports.trip_date');
    Route::get('reports/employee-profit', [\App\Http\Controllers\ReportController::class, 'employeeProfit'])->name('reports.employee-profit')->middleware('perm:reports.employee_profit');
    Route::get('reports/agents-balances', [\App\Http\Controllers\ReportController::class, 'agentsBalances'])->name('reports.agents-balances')->middleware('perm:reports.view');
    Route::get('reports/clients-balances', [\App\Http\Controllers\ReportController::class, 'clientsBalances'])->name('reports.clients-balances')->middleware('perm:reports.view');
    Route::get('reports/profit-loss', fn () => redirect('/accounting/profit-loss'))->middleware('perm:accounting.view');
    Route::get('reports/daily-summary', [\App\Http\Controllers\ReportController::class, 'dailySummary'])->name('reports.daily-summary')->middleware('perm:reports.view');

    // ===== بوت الواتساب =====
    Route::get('whatsapp/inbox', [\App\Http\Controllers\WhatsAppController::class, 'inbox'])->name('whatsapp.inbox')->middleware('perm:whatsapp.view');
    Route::get('api/whatsapp/conversations', [\App\Http\Controllers\WhatsAppController::class, 'conversations'])->middleware('perm:whatsapp.view');
    Route::get('api/whatsapp/inbox-summary', [\App\Http\Controllers\WhatsAppController::class, 'inboxSummary'])->middleware('perm:whatsapp.view');
    Route::get('api/whatsapp/conversations/{conversation}/messages', [\App\Http\Controllers\WhatsAppController::class, 'messages'])->middleware('perm:whatsapp.view');
    // حد wa-send لكل موظف (E28): الرد والسعر وطلب الرقم، وكل مسار إرسال لاحق
    Route::post('api/whatsapp/conversations/{conversation}/send', [\App\Http\Controllers\WhatsAppController::class, 'send'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send']);
    Route::post('api/whatsapp/conversations/{conversation}/request-contact', [\App\Http\Controllers\WhatsAppController::class, 'requestContact'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send']);
    // مرفقات العملاء (E14): ربط {message} محصور بنطاق المحادثة. الرابط بلا امتداد فلا تلتقطه كتلة nginx للملفات الثابتة
    Route::get('api/whatsapp/messages/{message}/media', [\App\Http\Controllers\WhatsAppMediaController::class, 'show'])->name('whatsapp.media.show')->middleware(['perm:whatsapp.media', 'throttle:60,1']);
    Route::post('api/whatsapp/messages/{message}/media/refetch', [\App\Http\Controllers\WhatsAppMediaController::class, 'refetch'])->name('whatsapp.media.refetch')->middleware(['perm:whatsapp.media', 'throttle:60,1']);
    Route::get('api/whatsapp/clients/search', [\App\Http\Controllers\WhatsAppController::class, 'searchClients'])->middleware('perm:whatsapp.link_client');
    // طلبات حذف البيانات (E13): عرض وتحقّق ورفض؛ التنفيذ من سطر الأوامر وحده
    Route::get('whatsapp/erasure-requests', [\App\Http\Controllers\WhatsAppErasureController::class, 'index'])->name('whatsapp.erasure')->middleware('perm:whatsapp.erase');
    Route::post('whatsapp/erasure-requests/{erasureRequest}/verify', [\App\Http\Controllers\WhatsAppErasureController::class, 'verify'])->middleware('perm:whatsapp.erase');
    Route::post('whatsapp/erasure-requests/{erasureRequest}/reject', [\App\Http\Controllers\WhatsAppErasureController::class, 'reject'])->middleware('perm:whatsapp.erase');
    Route::post('api/whatsapp/conversations/{conversation}/link-client', [\App\Http\Controllers\WhatsAppController::class, 'linkClient'])->middleware('perm:whatsapp.link_client');
    Route::delete('api/whatsapp/conversations/{conversation}/link-client', [\App\Http\Controllers\WhatsAppController::class, 'unlinkClient'])->middleware('perm:whatsapp.link_client');
    Route::post('api/whatsapp/conversations/{conversation}/toggle-bot', [\App\Http\Controllers\WhatsAppController::class, 'toggleBot'])->middleware('perm:whatsapp.bot_toggle');
    Route::post('api/whatsapp/conversations/{conversation}/resolve', [\App\Http\Controllers\WhatsAppController::class, 'resolve'])->middleware('perm:whatsapp.reply');

    // توجيه المحادثات بالموضوع
    Route::post('api/whatsapp/conversations/{conversation}/topic', [\App\Http\Controllers\WhatsAppController::class, 'toggleTopic'])->middleware('perm:whatsapp.reply');
    Route::post('api/whatsapp/conversations/{conversation}/assign', [\App\Http\Controllers\WhatsAppController::class, 'assign'])->middleware('perm:whatsapp.assign');
    Route::post('api/whatsapp/conversations/{conversation}/read', [\App\Http\Controllers\WhatsAppController::class, 'markRead'])->middleware('perm:whatsapp.reply');
    // متابعة الصندوق (E17): حالة داخلية بلا نداء لميتا، وكلها عبر الربط المقيّد بالنطاق
    Route::post('api/whatsapp/conversations/{conversation}/close', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'close'])->middleware('perm:whatsapp.reply');
    Route::post('api/whatsapp/conversations/{conversation}/reopen', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'reopen'])->middleware('perm:whatsapp.reply');
    Route::post('api/whatsapp/conversations/{conversation}/snooze', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'snooze'])->middleware('perm:whatsapp.reply');
    Route::delete('api/whatsapp/conversations/{conversation}/snooze', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'unsnooze'])->middleware('perm:whatsapp.reply');
    Route::post('api/whatsapp/conversations/{conversation}/pin', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'pin'])->middleware('perm:whatsapp.view');
    Route::delete('api/whatsapp/conversations/{conversation}/pin', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'unpin'])->middleware('perm:whatsapp.view');
    Route::post('api/whatsapp/conversations/{conversation}/mark-unread', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'markUnread'])->middleware('perm:whatsapp.view');
    Route::post('api/whatsapp/conversations/{conversation}/reprocess', [\App\Http\Controllers\WhatsAppConversationStateController::class, 'reprocess'])->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/conversations/{conversation}/quick-replies/render', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'render'])->middleware('perm:whatsapp.reply');
    Route::get('whatsapp/routing', [\App\Http\Controllers\WhatsAppController::class, 'routing'])->name('whatsapp.routing')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/routing', [\App\Http\Controllers\WhatsAppController::class, 'saveRouting'])->name('whatsapp.routing.save')->middleware('perm:whatsapp.settings');
    // إعدادات البوت (E10): حفظ جزئي برمز نسخة، وإيقاف طارئ بسبب، وأسرار لا تعود للمتصفح
    Route::get('whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'show'])->name('whatsapp.settings')->middleware('perm:whatsapp.settings');
    Route::patch('whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'update'])->name('whatsapp.settings.patch')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'legacyUpdate'])->name('whatsapp.settings.update')->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/settings', [\App\Http\Controllers\WhatsAppSettingsController::class, 'current'])->middleware('perm:whatsapp.settings');
    // الإيقاف لمن يملك whatsapp.settings أو whatsapp.kill_switch، والاستئناف لـ whatsapp.settings (فحص المتحكم)
    Route::post('api/whatsapp/settings/kill-switch', [\App\Http\Controllers\WhatsAppSettingsController::class, 'killSwitch'])->middleware(['perm:whatsapp.settings,whatsapp.kill_switch', 'throttle:20,1']);
    Route::post('api/whatsapp/settings/secrets/{secret}', [\App\Http\Controllers\WhatsAppSettingsController::class, 'setSecret'])->middleware(['perm:whatsapp.settings', 'throttle:10,1']);
    Route::delete('api/whatsapp/settings/secrets/{secret}', [\App\Http\Controllers\WhatsAppSettingsController::class, 'clearSecret'])->middleware(['perm:whatsapp.settings', 'throttle:10,1']);
    Route::post('api/whatsapp/settings/verify-token', [\App\Http\Controllers\WhatsAppSettingsController::class, 'rotateVerifyToken'])->middleware(['perm:whatsapp.settings', 'throttle:10,1']);
    Route::patch('api/whatsapp/settings/flags/{flag}', [\App\Http\Controllers\WhatsAppSettingsController::class, 'toggleFlag'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    Route::patch('api/whatsapp/settings/identity-mode', [\App\Http\Controllers\WhatsAppSettingsController::class, 'identityMode'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    Route::post('api/whatsapp/settings/llm-breaker/open', [\App\Http\Controllers\WhatsAppSettingsController::class, 'breakerOpen'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    Route::post('api/whatsapp/settings/last-error/clear', [\App\Http\Controllers\WhatsAppSettingsController::class, 'clearLastError'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    Route::post('api/whatsapp/settings/llm-breaker/close', [\App\Http\Controllers\WhatsAppSettingsController::class, 'breakerClose'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    Route::get('whatsapp/analytics', [\App\Http\Controllers\WhatsAppController::class, 'analytics'])->name('whatsapp.analytics')->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/analytics', [\App\Http\Controllers\WhatsAppController::class, 'analyticsData'])->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/prices', [\App\Http\Controllers\WhatsAppController::class, 'savePrices'])->name('whatsapp.prices')->middleware('perm:whatsapp.settings');
    Route::post('api/whatsapp/test-key', [\App\Http\Controllers\WhatsAppController::class, 'testKey'])->middleware('perm:whatsapp.settings');
    // فحص ميتا يدوياً (رمز الوصول وصحة القناة): 6 في الدقيقة
    Route::post('api/whatsapp/meta-check', [\App\Http\Controllers\WhatsAppController::class, 'metaCheck'])->middleware(['perm:whatsapp.settings', 'throttle:6,1']);
    // صحة القناة كآلة حالات (E22): العرض والرفع اليدوي بسبب و«تم الاطلاع» وحمولة حدث واحد (whatsapp.settings)
    Route::get('api/whatsapp/channel-health', [\App\Http\Controllers\WhatsAppChannelHealthController::class, 'show'])->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/channel-health/events/{event}', [\App\Http\Controllers\WhatsAppChannelHealthController::class, 'event'])->middleware(['perm:whatsapp.settings', 'throttle:30,1']);
    Route::post('api/whatsapp/channel-health/unblock', [\App\Http\Controllers\WhatsAppChannelHealthController::class, 'unblock'])->middleware(['perm:whatsapp.settings', 'throttle:6,1']);
    Route::post('api/whatsapp/channel-health/acknowledge', [\App\Http\Controllers\WhatsAppChannelHealthController::class, 'acknowledge'])->middleware(['perm:whatsapp.settings', 'throttle:20,1']);
    // الإخفاقات والطابور (E24): العلم failures_center (مطفأ = 404)، والإعادة بالعلم failures_redrive. throttle بمفتاح لكل مسار
    Route::get('whatsapp/failures', [\App\Http\Controllers\WhatsAppFailuresController::class, 'index'])->name('whatsapp.failures')->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/failures', [\App\Http\Controllers\WhatsAppFailuresController::class, 'data'])->middleware(['perm:whatsapp.settings', 'throttle:30,1,wa-failures']);
    Route::post('api/whatsapp/messages/{message}/redrive', [\App\Http\Controllers\WhatsAppFailuresController::class, 'redrive'])->name('whatsapp.failures.redrive')->middleware(['perm:whatsapp.settings', 'throttle:10,1,wa-redrive']);
    Route::post('api/ops/failed-jobs/{uuid}/retry', [\App\Http\Controllers\WhatsAppFailuresController::class, 'retryJob'])->name('ops.failed-jobs.retry')->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['perm:whatsapp.settings', 'throttle:10,1,ops-retry']);
    Route::post('api/whatsapp/webhook-events/{id}/replay', [\App\Http\Controllers\WhatsAppFailuresController::class, 'replayWebhook'])->name('whatsapp.webhook-events.replay')->whereNumber('id')->middleware(['perm:whatsapp.settings', 'throttle:10,1,wa-replay']);

    // أرقام الإدارة: المساعد الإداري على واتساب وصلاحيات كل رقم
    Route::get('whatsapp/admin-numbers', [\App\Http\Controllers\WhatsAppAdminNumberController::class, 'index'])->name('whatsapp.admin-numbers')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/admin-numbers', [\App\Http\Controllers\WhatsAppAdminNumberController::class, 'store'])->name('whatsapp.admin-numbers.store')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/admin-numbers/{adminNumber}', [\App\Http\Controllers\WhatsAppAdminNumberController::class, 'update'])->name('whatsapp.admin-numbers.update')->middleware('perm:whatsapp.settings');
    Route::delete('whatsapp/admin-numbers/{adminNumber}', [\App\Http\Controllers\WhatsAppAdminNumberController::class, 'destroy'])->name('whatsapp.admin-numbers.destroy')->middleware('perm:whatsapp.settings');

    // قاعدة معرفة بوت العملاء — معلومات مستقلة
    // الردود الجاهزة (E17.7): الإدارة بـ whatsapp.quick_replies، والاعتماد بـ whatsapp.settings
    Route::get('whatsapp/quick-replies', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'index'])->name('whatsapp.quick-replies')->middleware('perm:whatsapp.quick_replies');
    Route::post('whatsapp/quick-replies', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'store'])->name('whatsapp.quick-replies.store')->middleware('perm:whatsapp.quick_replies');
    Route::post('whatsapp/quick-replies/reorder', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'reorder'])->name('whatsapp.quick-replies.reorder')->middleware('perm:whatsapp.quick_replies');
    Route::put('whatsapp/quick-replies/{quickReply}', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'update'])->name('whatsapp.quick-replies.update')->middleware('perm:whatsapp.quick_replies');
    Route::delete('whatsapp/quick-replies/{quickReply}', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'destroy'])->name('whatsapp.quick-replies.destroy')->middleware('perm:whatsapp.quick_replies');
    Route::post('whatsapp/quick-replies/{quickReply}/approve', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'approve'])->name('whatsapp.quick-replies.approve')->middleware('perm:whatsapp.settings');
    Route::get('api/whatsapp/quick-replies', [\App\Http\Controllers\WhatsAppQuickReplyController::class, 'usable'])->middleware('perm:whatsapp.reply');
    Route::get('whatsapp/knowledge', [\App\Http\Controllers\WhatsAppKnowledgeController::class, 'index'])->name('whatsapp.knowledge')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/knowledge', [\App\Http\Controllers\WhatsAppKnowledgeController::class, 'store'])->name('whatsapp.knowledge.store')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/knowledge/reorder', [\App\Http\Controllers\WhatsAppKnowledgeController::class, 'reorder'])->name('whatsapp.knowledge.reorder')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/knowledge/{entry}', [\App\Http\Controllers\WhatsAppKnowledgeController::class, 'update'])->name('whatsapp.knowledge.update')->middleware('perm:whatsapp.settings');
    Route::delete('whatsapp/knowledge/{entry}', [\App\Http\Controllers\WhatsAppKnowledgeController::class, 'destroy'])->name('whatsapp.knowledge.destroy')->middleware('perm:whatsapp.settings');
    // البحث في الرسائل وتصدير محادثة (E30): throttle بمفتاح لكل مسار، فلا يشارك عدّاد مسارات throttle الأخرى للمستخدم نفسه
    Route::get('api/whatsapp/search', [\App\Http\Controllers\WhatsAppSearchController::class, 'search'])->middleware(['perm:whatsapp.view', 'throttle:30,1,wa-search']);
    Route::get('api/whatsapp/conversations/{conversation}/export', [\App\Http\Controllers\WhatsAppExportController::class, 'export'])->middleware(['perm:whatsapp.export', 'throttle:5,1,wa-export']);

    // التصنيفات والملاحظة المثبتة والترجمة عند الطلب (E29): الإدارة للمدير، وكل مسار محادثة عبر الربط المقيّد بالنطاق،
    // والرسالة من المحادثة نفسها وحدها. الأعلام wa_labels وthread_pins وstaff_translation تُفحص في المتحكمات (مطفأ = 404)
    Route::get('whatsapp/labels', [\App\Http\Controllers\WhatsAppLabelController::class, 'index'])->name('whatsapp.labels')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/labels', [\App\Http\Controllers\WhatsAppLabelController::class, 'store'])->name('whatsapp.labels.store')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/labels/reorder', [\App\Http\Controllers\WhatsAppLabelController::class, 'reorder'])->name('whatsapp.labels.reorder')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/labels/{label}', [\App\Http\Controllers\WhatsAppLabelController::class, 'update'])->name('whatsapp.labels.update')->middleware('perm:whatsapp.settings');
    Route::delete('whatsapp/labels/{label}', [\App\Http\Controllers\WhatsAppLabelController::class, 'destroy'])->name('whatsapp.labels.destroy')->middleware('perm:whatsapp.settings');
    Route::post('api/whatsapp/conversations/{conversation}/labels/{label}', [\App\Http\Controllers\WhatsAppConversationLabelController::class, 'attach'])->middleware('perm:whatsapp.reply');
    Route::delete('api/whatsapp/conversations/{conversation}/labels/{label}', [\App\Http\Controllers\WhatsAppConversationLabelController::class, 'detach'])->middleware('perm:whatsapp.reply');
    Route::get('api/whatsapp/conversations/{conversation}/facts', [\App\Http\Controllers\WhatsAppThreadPinController::class, 'facts'])->middleware('perm:whatsapp.view');
    // اللوحة الجانبية للمحادثة: الرحلات والفواتير والطلبات والملاحظات (العلم conversation_panel)
    Route::get('api/whatsapp/conversations/{conversation}/panel', [\App\Http\Controllers\WhatsAppConversationPanelController::class, 'show'])->middleware('perm:whatsapp.view');
    Route::put('api/whatsapp/conversations/{conversation}/pin-note', [\App\Http\Controllers\WhatsAppThreadPinController::class, 'saveNote'])->middleware('perm:whatsapp.reply');
    Route::delete('api/whatsapp/conversations/{conversation}/pin-note', [\App\Http\Controllers\WhatsAppThreadPinController::class, 'clearNote'])->middleware('perm:whatsapp.reply');
    Route::post('api/whatsapp/conversations/{conversation}/messages/{message}/pin', [\App\Http\Controllers\WhatsAppThreadPinController::class, 'pinMessage'])->middleware('perm:whatsapp.reply')->scopeBindings();
    Route::delete('api/whatsapp/conversations/{conversation}/messages/{message}/pin', [\App\Http\Controllers\WhatsAppThreadPinController::class, 'unpinMessage'])->middleware('perm:whatsapp.reply')->scopeBindings();
    // الترجمة: 20 في الدقيقة لكل مستخدم في دلو مستقل (البادئة wa-translate)
    Route::post('api/whatsapp/conversations/{conversation}/messages/{message}/translate', [\App\Http\Controllers\WhatsAppTranslationController::class, 'translate'])->middleware(['perm:whatsapp.view', 'throttle:20,1,wa-translate'])->scopeBindings();
    Route::post('api/whatsapp/conversations/{conversation}/translate-reply', [\App\Http\Controllers\WhatsAppTranslationController::class, 'translateReply'])->middleware(['perm:whatsapp.reply', 'throttle:20,1,wa-translate']);
    Route::post('api/whatsapp/conversations/{conversation}/send-translated', [\App\Http\Controllers\WhatsAppTranslationController::class, 'sendTranslated'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send']);
    Route::post('api/whatsapp/settings/llm-terms', [\App\Http\Controllers\WhatsAppSettingsController::class, 'llmTerms'])->middleware(['perm:whatsapp.settings', 'throttle:10,1']);
    // الردود الحتمية (E37): الإدارة بـ whatsapp.auto_rules، والمكوّنات الرسمية (كتابة في ميتا) بـ whatsapp.settings
    Route::get('whatsapp/auto-rules', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'index'])->name('whatsapp.auto-rules')->middleware('perm:whatsapp.auto_rules');
    Route::post('whatsapp/auto-rules', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'store'])->name('whatsapp.auto-rules.store')->middleware('perm:whatsapp.auto_rules');
    Route::post('whatsapp/auto-rules/reorder', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'reorder'])->name('whatsapp.auto-rules.reorder')->middleware('perm:whatsapp.auto_rules');
    Route::put('whatsapp/auto-rules/{rule}', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'update'])->name('whatsapp.auto-rules.update')->middleware('perm:whatsapp.auto_rules');
    Route::delete('whatsapp/auto-rules/{rule}', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'destroy'])->name('whatsapp.auto-rules.destroy')->middleware('perm:whatsapp.auto_rules');
    Route::post('whatsapp/auto-rules/{rule}/toggle', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'toggle'])->name('whatsapp.auto-rules.toggle')->middleware('perm:whatsapp.auto_rules');
    Route::post('api/whatsapp/auto-rules/test', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'test'])->middleware(['perm:whatsapp.auto_rules', 'throttle:30,1,wa-rule-test']);
    Route::post('api/whatsapp/auto-rules/simulate', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'simulate'])->middleware(['perm:whatsapp.auto_rules', 'throttle:4,1,wa-rule-sim']);
    Route::get('api/whatsapp/conversational-automation', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'automation'])->middleware(['perm:whatsapp.settings', 'throttle:10,1,wa-conv-read']);
    Route::post('api/whatsapp/conversational-automation/sync', [\App\Http\Controllers\WhatsAppAutoRuleController::class, 'syncAutomation'])->middleware(['perm:whatsapp.settings', 'throttle:3,1,wa-conv-sync']);
    // ملف من الصندوق (E32): الربط المقيّد وحد wa-files، والرفع إلى ميتا مؤجل في الطابور. لكتلة nginx الخاصة بالمسار حدها (20m)
    Route::post('api/whatsapp/conversations/{conversation}/send-file', [\App\Http\Controllers\WhatsAppFileController::class, 'store'])->middleware(['perm:whatsapp.reply', 'throttle:wa-files']);
    // الرسائل الغنية (E33): التصحيح المقتبس والتفاعل والدبوس والبطاقات، كلٌّ بعلمه في المتحكم وبالربط المسيّج. {message} من
    // المحادثة نفسها (فحص صريح لأن Route::bind('message') يسبق scopeBindings). والمواقع والبطاقات المعتمدة للمدير
    Route::post('api/whatsapp/conversations/{conversation}/messages/{message}/correct', [\App\Http\Controllers\WhatsAppRichMessageController::class, 'correct'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send'])->scopeBindings();
    Route::post('api/whatsapp/conversations/{conversation}/messages/{message}/react', [\App\Http\Controllers\WhatsAppRichMessageController::class, 'react'])->middleware(['perm:whatsapp.reply', 'throttle:60,1,wa-react'])->scopeBindings();
    Route::post('api/whatsapp/conversations/{conversation}/send-location', [\App\Http\Controllers\WhatsAppRichMessageController::class, 'sendLocation'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send']);
    Route::post('api/whatsapp/conversations/{conversation}/send-contact', [\App\Http\Controllers\WhatsAppRichMessageController::class, 'sendContact'])->middleware(['perm:whatsapp.reply', 'throttle:wa-send']);
    Route::get('whatsapp/shared-items', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'index'])->name('whatsapp.shared-items')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/shared-items/places', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'storePlace'])->name('whatsapp.shared-items.places.store')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/shared-items/places/{place}', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'updatePlace'])->name('whatsapp.shared-items.places.update')->middleware('perm:whatsapp.settings');
    Route::delete('whatsapp/shared-items/places/{place}', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'destroyPlace'])->name('whatsapp.shared-items.places.destroy')->middleware('perm:whatsapp.settings');
    Route::post('whatsapp/shared-items/cards', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'storeCard'])->name('whatsapp.shared-items.cards.store')->middleware('perm:whatsapp.settings');
    Route::put('whatsapp/shared-items/cards/{card}', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'updateCard'])->name('whatsapp.shared-items.cards.update')->middleware('perm:whatsapp.settings');
    Route::delete('whatsapp/shared-items/cards/{card}', [\App\Http\Controllers\WhatsAppSharedItemsController::class, 'destroyCard'])->name('whatsapp.shared-items.cards.destroy')->middleware('perm:whatsapp.settings');

    // الحظر الرسمي (E31): whatsapp.block (للمدير) والعلم wa_block. الحظر وفكه من الصندوق عبر الربط المقيّد بالنطاق
    Route::post('api/whatsapp/conversations/{conversation}/block', [\App\Http\Controllers\WhatsAppBlockController::class, 'block'])->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    Route::delete('api/whatsapp/conversations/{conversation}/block', [\App\Http\Controllers\WhatsAppBlockController::class, 'unblockConversation'])->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    Route::get('whatsapp/blocks', [\App\Http\Controllers\WhatsAppBlockController::class, 'index'])->name('whatsapp.blocks')->middleware('perm:whatsapp.block');
    Route::post('api/whatsapp/blocks/sync', [\App\Http\Controllers\WhatsAppBlockController::class, 'sync'])->middleware(['perm:whatsapp.block', 'throttle:3,10']);
    Route::post('api/whatsapp/blocks/untracked/register', [\App\Http\Controllers\WhatsAppBlockController::class, 'registerUntracked'])->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    Route::post('api/whatsapp/blocks/untracked/unblock', [\App\Http\Controllers\WhatsAppBlockController::class, 'unblockUntracked'])->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    Route::post('api/whatsapp/blocks/{block}/unblock', [\App\Http\Controllers\WhatsAppBlockController::class, 'unblock'])->whereNumber('block')->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    Route::post('api/whatsapp/blocks/{block}/reblock', [\App\Http\Controllers\WhatsAppBlockController::class, 'reblock'])->whereNumber('block')->middleware(['perm:whatsapp.block', 'throttle:10,1']);
    // ملف واتساب للأعمال (E31): whatsapp.settings، والمزامنة والصورة خلف علميهما. لا مسار يغيّر اسم العرض
    Route::get('api/whatsapp/business-profile', [\App\Http\Controllers\WhatsAppBusinessProfileController::class, 'show'])->middleware(['perm:whatsapp.settings', 'throttle:30,1']);
    Route::post('api/whatsapp/business-profile/sync', [\App\Http\Controllers\WhatsAppBusinessProfileController::class, 'sync'])->middleware(['perm:whatsapp.settings', 'throttle:5,10']);
    Route::post('api/whatsapp/business-profile/photo', [\App\Http\Controllers\WhatsAppBusinessProfileController::class, 'photo'])->middleware(['perm:whatsapp.settings', 'throttle:5,10']);
    // الرموز العامة بلا عرض (E38): ملصق المكتب وصفحات التواصل
    Route::get('api/whatsapp/qr-links', [\App\Http\Controllers\WhatsAppQrController::class, 'index'])->middleware('perm:whatsapp.settings');
    Route::post('api/whatsapp/qr-links', [\App\Http\Controllers\WhatsAppQrController::class, 'store'])->middleware(['perm:whatsapp.settings', 'throttle:10,1']);
    Route::delete('api/whatsapp/qr-links/{link}', [\App\Http\Controllers\WhatsAppQrController::class, 'destroy'])->whereNumber('link')->middleware('perm:whatsapp.settings');
    Route::get('whatsapp/qr-links/{link}/{format}', [\App\Http\Controllers\WhatsAppQrController::class, 'download'])->whereNumber('link')->where('format', 'png|svg')->middleware('perm:whatsapp.settings');
    // أدوات التشخيص (E23): فحص الإعدادات بزر (قراءة، 3 كل 10 دقائق)
    Route::post('api/whatsapp/doctor', [\App\Http\Controllers\WhatsAppController::class, 'runDoctor'])->middleware(['perm:whatsapp.settings', 'throttle:3,10,wa-doctor']);
    // «اختبار الاتصال» (العلم channel_selftest): قراءات رسمية وارتداد موقّع، 6 في الدقيقة
    Route::post('api/whatsapp/channel-test', [\App\Http\Controllers\WhatsAppController::class, 'channelTest'])->middleware(['perm:whatsapp.settings', 'throttle:6,1,wa-channel-test']);
    // «إرسال تجريبي» (العلم wa_test_send): أرقام الإدارة المعلَّمة وحدها، 3 في الدقيقة للمستخدم و10 في الساعة للنظام
    Route::post('api/whatsapp/test-send', [\App\Http\Controllers\WhatsAppController::class, 'testSend'])->middleware(['perm:whatsapp.settings', 'throttle:3,1,wa-test-send']);
    Route::get('api/whatsapp/test-send/{testMessage}/status', [\App\Http\Controllers\WhatsAppController::class, 'testSendStatus'])->whereNumber('testMessage')->middleware(['perm:whatsapp.settings', 'throttle:60,1,wa-test-status']);
    // حالة النظام (E23): قراءة فقط بالصلاحية ops.view والعلم ops_status_page
    Route::get('ops/status', [\App\Http\Controllers\OpsStatusController::class, 'index'])->name('ops.status')->middleware('perm:ops.view');
    Route::get('api/ops/status', [\App\Http\Controllers\OpsStatusController::class, 'data'])->middleware('perm:ops.view');
    Route::post('api/ops/status/refresh', [\App\Http\Controllers\OpsStatusController::class, 'refresh'])->middleware(['perm:ops.view', 'throttle:6,1,ops-status']);
    // المكالمات والتعايش (E39): بطاقتا الإعدادات لصاحب whatsapp.settings خلف أعلامهما. لا مسار يكتب إعدادات الاتصال عند ميتا
    Route::get('api/whatsapp/calling', [\App\Http\Controllers\WhatsAppCallingController::class, 'show'])->middleware('perm:whatsapp.settings');
    Route::post('api/whatsapp/calling/refresh', [\App\Http\Controllers\WhatsAppCallingController::class, 'refresh'])->middleware(['perm:whatsapp.settings', 'throttle:6,1,wa-calling-read']);
    Route::put('api/whatsapp/calling/settings', [\App\Http\Controllers\WhatsAppCallingController::class, 'update'])->middleware(['perm:whatsapp.settings', 'throttle:20,1,wa-calling-save']);
    Route::get('api/whatsapp/coexistence', [\App\Http\Controllers\WhatsAppCoexistenceController::class, 'show'])->middleware('perm:whatsapp.settings');
    Route::put('api/whatsapp/coexistence/settings', [\App\Http\Controllers\WhatsAppCoexistenceController::class, 'update'])->middleware(['perm:whatsapp.settings', 'throttle:20,1,wa-coex-save']);

    // العروض — سجل واحد يقرؤه البوت والبطاقة والعروض الجاهزة وفاتورة العرض
    Route::get('offers/ready', [\App\Http\Controllers\OfferController::class, 'ready'])->name('offers.ready')->middleware('perm:offers.view');
    Route::resource('offers', \App\Http\Controllers\OfferController::class)->only(['index', 'create', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor(['index', 'show'], 'perm:offers.view')
        ->middlewareFor(['create', 'store'], 'perm:offers.create')
        ->middlewareFor('update', 'perm:offers.update')
        ->middlewareFor('destroy', 'perm:offers.delete');
    Route::post('offers/{offer}/toggle-bot', [\App\Http\Controllers\OfferController::class, 'toggleBot'])->name('offers.toggle-bot')->middleware('perm:offers.update');
    Route::post('offers/{offer}/settlement/preview', [\App\Http\Controllers\OfferController::class, 'settlementPreview'])->name('offers.settlement.preview')->middleware('perm:offers.update');
    Route::post('offers/{offer}/settlement', [\App\Http\Controllers\OfferController::class, 'settle'])->name('offers.settlement')->middleware('perm:offers.update');

    // بطاقة العرض التسويقية (صورة تُرسل للعميل على واتساب)
    Route::post('offers/{offer}/card', [\App\Http\Controllers\OfferController::class, 'generateCard'])->name('offers.card.generate')->middleware('perm:offers.update');
    Route::get('offers/{offer}/card/preview', [\App\Http\Controllers\OfferController::class, 'previewCard'])->name('offers.card.preview')->middleware('perm:offers.update');
    Route::post('offers/{offer}/card/upload', [\App\Http\Controllers\OfferController::class, 'uploadCard'])->name('offers.card.upload')->middleware('perm:offers.update');
    Route::delete('offers/{offer}/card', [\App\Http\Controllers\OfferController::class, 'deleteCard'])->name('offers.card.delete')->middleware('perm:offers.update');
    Route::post('offers/{offer}/hero', [\App\Http\Controllers\OfferController::class, 'uploadHero'])->name('offers.hero.upload')->middleware('perm:offers.update');

    // المشاركة (E38): رموز QR الرسمية للعرض وبطاقة الحالة 9:16 للنشر اليدوي. العلمان wa_qr_links وoffer_story_card
    Route::get('api/offers/{offer}/share', [\App\Http\Controllers\OfferShareController::class, 'index'])->middleware('perm:offers.view');
    Route::post('offers/{offer}/qr', [\App\Http\Controllers\OfferShareController::class, 'storeQr'])->middleware(['perm:offers.update', 'throttle:10,1']);
    Route::delete('offers/{offer}/qr/{link}', [\App\Http\Controllers\OfferShareController::class, 'destroyQr'])->whereNumber('link')->middleware('perm:offers.update');
    Route::get('offers/{offer}/qr/{link}/{format}', [\App\Http\Controllers\OfferShareController::class, 'downloadQr'])->whereNumber('link')->where('format', 'png|svg')->middleware('perm:offers.view');
    Route::post('offers/{offer}/card-qr', [\App\Http\Controllers\OfferShareController::class, 'toggleCardQr'])->middleware('perm:offers.update');
    Route::post('offers/{offer}/story', [\App\Http\Controllers\OfferShareController::class, 'generateStory'])->middleware(['perm:offers.update', 'throttle:6,1']);
    Route::get('offers/{offer}/story/download', [\App\Http\Controllers\OfferShareController::class, 'downloadStory'])->middleware(['perm:offers.view', 'throttle:6,1']);

    // طلبات التسعير والحجز
    Route::get('quote-requests', [\App\Http\Controllers\QuoteRequestController::class, 'index'])->name('quote-requests.index')->middleware('perm:quotes.view');
    Route::post('quote-requests/{quoteRequest}/price', [\App\Http\Controllers\QuoteRequestController::class, 'price'])->name('quote-requests.price')->middleware(['perm:quotes.price', 'throttle:wa-send']);
    Route::post('quote-requests/{quoteRequest}/status', [\App\Http\Controllers\QuoteRequestController::class, 'updateStatus'])->name('quote-requests.status')->middleware('perm:quotes.price');
    // تصدير CSV (E24): العلم quote_export والصلاحية quotes.export، 5 في الدقيقة بمفتاحه
    Route::get('quote-requests/export', [\App\Http\Controllers\QuoteRequestController::class, 'export'])->name('quote-requests.export')->middleware(['perm:quotes.export', 'throttle:5,1,quote-export']);

    // تجربة مزوّد أسعار الرحلات (أداة مقارنة — المدير فقط)
    Route::get('flights/search', [\App\Http\Controllers\FlightSearchController::class, 'index'])->name('flights.search')->middleware('perm:admin');
    Route::post('api/flights/search', [\App\Http\Controllers\FlightSearchController::class, 'search'])->name('flights.search.run')->middleware('perm:admin');

    // Accounting
    Route::get('accounting/chart-of-accounts', [\App\Http\Controllers\AccountingController::class, 'chartOfAccounts'])->name('accounting.chart')->middleware('perm:accounting.view');
    Route::get('accounting/chart-of-accounts/print', [\App\Http\Controllers\AccountingController::class, 'printChart'])->name('accounting.chart.print')->middleware('perm:accounting.view');
    Route::post('accounting/accounts', [\App\Http\Controllers\AccountingController::class, 'storeAccount'])->name('accounting.accounts.store')->middleware('perm:accounting.manage');
    Route::get('accounting/accounts/next-code', [\App\Http\Controllers\AccountingController::class, 'nextCode'])->name('accounting.accounts.next-code')->middleware('perm:accounting.view');
    Route::put('accounting/accounts/{account}', [\App\Http\Controllers\AccountingController::class, 'updateAccount'])->name('accounting.accounts.update')->middleware('perm:accounting.manage');
    Route::delete('accounting/accounts/{account}', [\App\Http\Controllers\AccountingController::class, 'destroyAccount'])->name('accounting.accounts.destroy')->middleware('perm:accounting.manage');
    Route::get('accounting/trial-balance', [\App\Http\Controllers\AccountingController::class, 'trialBalance'])->name('accounting.trial-balance')->middleware('perm:accounting.view');
    Route::post('accounting/trial-balance/relocate-line', [\App\Http\Controllers\AccountingController::class, 'relocateLine'])->name('accounting.trial-balance.relocate-line')->middleware('perm:accounting.manage');
    Route::get('accounting/journal-entries', [\App\Http\Controllers\AccountingController::class, 'journalEntries'])->name('accounting.journal-entries')->middleware('perm:accounting.view');
    Route::post('accounting/journal-entries', [\App\Http\Controllers\AccountingController::class, 'storeJournal'])->name('accounting.journal-entries.store')->middleware('perm:accounting.manage');
    Route::put('accounting/journal-entries/{entry}', [\App\Http\Controllers\AccountingController::class, 'updateJournal'])->name('accounting.journal-entries.update')->middleware('perm:accounting.manage');
    Route::delete('accounting/journal-entries/{entry}', [\App\Http\Controllers\AccountingController::class, 'destroyJournal'])->name('accounting.journal-entries.destroy')->middleware('perm:accounting.manage');
    Route::post('accounting/journal-entries/{entry}/reverse', [\App\Http\Controllers\AccountingController::class, 'reverseEntry'])->name('accounting.journal-entries.reverse')->middleware('perm:accounting.manage');
    Route::get('accounting/periods', [\App\Http\Controllers\AccountingController::class, 'periods'])->name('accounting.periods')->middleware('perm:accounting.view');
    Route::post('accounting/periods/close', [\App\Http\Controllers\AccountingController::class, 'closePeriod'])->name('accounting.periods.close')->middleware('perm:accounting.close');
    Route::post('accounting/periods/open', [\App\Http\Controllers\AccountingController::class, 'openPeriod'])->name('accounting.periods.open')->middleware('perm:accounting.close');
    Route::post('accounting/close-year', [\App\Http\Controllers\AccountingController::class, 'closeYear'])->name('accounting.close-year')->middleware('perm:accounting.close');
    Route::post('accounting/fiscal-years', [\App\Http\Controllers\AccountingController::class, 'storeFiscalYear'])->name('accounting.fiscal-years.store')->middleware('perm:accounting.manage');
    Route::delete('accounting/fiscal-years/{fiscalYear}', [\App\Http\Controllers\AccountingController::class, 'destroyFiscalYear'])->name('accounting.fiscal-years.destroy')->middleware('perm:accounting.manage');
    Route::get('accounting/profit-loss', [\App\Http\Controllers\AccountingController::class, 'profitAndLoss'])->name('accounting.profit-loss')->middleware('perm:accounting.view');
    Route::get('accounting/balance-sheet', [\App\Http\Controllers\AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet')->middleware('perm:accounting.view');
    Route::get('accounting/accounts/{account}/details', [\App\Http\Controllers\AccountingController::class, 'accountDetails'])->name('accounting.accounts.details')->middleware('perm:accounting.view');

    // Print Pages
    Route::get('invoices/{invoice}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])->name('invoices.print')->middleware('perm:invoices.view');
    Route::get('agents/{agent}/print-statement', [AgentController::class, 'printStatement'])->name('agents.print-statement')->middleware('perm:agents.view');
    Route::get('clients/{client}/print-statement', [ClientController::class, 'printStatement'])->name('clients.print-statement')->middleware('perm:clients.view');
    Route::get('receipts/{receipt}/print', [ReceiptController::class, 'print'])->name('receipts.print')->middleware('perm:receipts.view');
    Route::get('disbursements/{disbursement}/print', [DisbursementController::class, 'print'])->name('disbursements.print.page')->middleware('perm:disbursements.view');
    Route::get('accounting/accounts/{account}/print', [\App\Http\Controllers\AccountingController::class, 'printAccountDetails'])->name('accounting.accounts.print')->middleware('perm:accounting.view');
    Route::get('accounting/trial-balance/print', [\App\Http\Controllers\AccountingController::class, 'printTrialBalance'])->name('accounting.trial-balance.print')->middleware('perm:accounting.view');
    Route::get('accounting/profit-loss/print', [\App\Http\Controllers\AccountingController::class, 'printProfitLoss'])->name('accounting.profit-loss.print')->middleware('perm:accounting.view');
    Route::get('accounting/balance-sheet/print', [\App\Http\Controllers\AccountingController::class, 'printBalanceSheet'])->name('accounting.balance-sheet.print')->middleware('perm:accounting.view');

    // Users & Roles
    Route::resource('users', \App\Http\Controllers\UserController::class)->except(['create', 'show', 'edit'])
        ->middlewareFor('index', 'perm:users.view')
        ->middlewareFor('store', 'perm:users.create')
        ->middlewareFor('update', 'perm:users.update')
        ->middlewareFor('destroy', 'perm:users.delete');
    // سجل التدقيق (E09): قراءة فقط، والصفحة خلف العلم audit.page
    Route::get('audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('audit-logs.index')->middleware('perm:audit.view');
    Route::get('audit-logs/export', [\App\Http\Controllers\AuditLogController::class, 'export'])->name('audit-logs.export')->middleware('perm:audit.export');
    Route::get('api/audit-logs/entity', [\App\Http\Controllers\AuditLogController::class, 'entity'])->name('audit-logs.entity')->middleware('perm:audit.view');
    Route::get('api/audit-logs/{auditLog}', [\App\Http\Controllers\AuditLogController::class, 'show'])->whereNumber('auditLog')->name('audit-logs.show')->middleware('perm:audit.view');
    Route::put('users/{user}/reset-password', [\App\Http\Controllers\UserController::class, 'resetPassword'])->name('users.reset-password')->middleware('perm:admin');
    Route::resource('roles', \App\Http\Controllers\RoleController::class)->except(['create', 'show'])
        ->middlewareFor(['index', 'store', 'edit', 'update', 'destroy'], 'perm:roles.manage');

    // HR Module
    Route::resource('departments', \App\Http\Controllers\DepartmentController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:employees.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'perm:employees.update');
    Route::resource('shifts', \App\Http\Controllers\ShiftController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:employees.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'perm:employees.update');
    Route::resource('employees', \App\Http\Controllers\EmployeeController::class)
        ->middlewareFor(['index', 'show'], 'perm:employees.view')
        ->middlewareFor(['create', 'store'], 'perm:employees.create')
        ->middlewareFor(['edit', 'update'], 'perm:employees.update')
        ->middlewareFor('destroy', 'perm:employees.delete');
    Route::get('employees/{employee}/print-statement', [\App\Http\Controllers\EmployeeController::class, 'printStatement'])->name('employees.print-statement')->middleware('perm:employees.view');

    // Attendance
    Route::get('attendance', [\App\Http\Controllers\AttendanceController::class, 'index'])->name('attendance.index')->middleware('perm:attendance.view');
    Route::post('attendance/check-in', [\App\Http\Controllers\AttendanceController::class, 'checkIn'])->name('attendance.check-in');
    Route::post('attendance/check-out', [\App\Http\Controllers\AttendanceController::class, 'checkOut'])->name('attendance.check-out');
    Route::get('api/attendance/status', [\App\Http\Controllers\AttendanceController::class, 'status'])->name('attendance.status');
    Route::post('attendance/{attendance}/manual-edit', [\App\Http\Controllers\AttendanceController::class, 'manualEdit'])->name('attendance.manual-edit')->middleware('perm:attendance.manual_edit');

    // Leaves
    Route::resource('leaves', \App\Http\Controllers\LeaveController::class)->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'perm:leaves.view')
        ->middlewareFor('store', 'perm:leaves.create')
        ->middlewareFor('destroy', 'perm:leaves.delete');
    Route::post('leaves/{leave}/approve', [\App\Http\Controllers\LeaveController::class, 'approve'])->name('leaves.approve')->middleware('perm:leaves.approve');
    Route::post('leaves/{leave}/reject', [\App\Http\Controllers\LeaveController::class, 'reject'])->name('leaves.reject')->middleware('perm:leaves.reject,leaves.approve');
    // Leave Types (Settings)
    Route::resource('leave-types', \App\Http\Controllers\LeaveTypeController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'perm:settings.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'perm:settings.update');

    // Advances
    Route::resource('advances', \App\Http\Controllers\AdvanceController::class)->only(['index', 'store', 'destroy'])
        ->middlewareFor('index', 'perm:advances.view')
        ->middlewareFor('store', 'perm:advances.create')
        ->middlewareFor('destroy', 'perm:advances.delete');
    Route::post('advances/{advance}/approve', [\App\Http\Controllers\AdvanceController::class, 'approve'])->name('advances.approve')->middleware('perm:advances.approve');
    Route::post('advances/{advance}/reject', [\App\Http\Controllers\AdvanceController::class, 'reject'])->name('advances.reject')->middleware('perm:advances.reject,advances.approve');

    // Payroll
    Route::get('payrolls', [\App\Http\Controllers\PayrollController::class, 'index'])->name('payrolls.index')->middleware('perm:payroll.view');
    Route::post('payrolls/generate', [\App\Http\Controllers\PayrollController::class, 'generate'])->name('payrolls.generate')->middleware('perm:payroll.generate');
    Route::get('payrolls/{payroll}', [\App\Http\Controllers\PayrollController::class, 'show'])->name('payrolls.show')->middleware('perm:payroll.view');
    Route::post('payrolls/{payroll}/submit', [\App\Http\Controllers\PayrollController::class, 'submit'])->name('payrolls.submit')->middleware('perm:payroll.generate');
    Route::post('payrolls/{payroll}/approve', [\App\Http\Controllers\PayrollController::class, 'approve'])->name('payrolls.approve')->middleware('perm:payroll.approve');
    Route::post('payrolls/{payroll}/reject', [\App\Http\Controllers\PayrollController::class, 'reject'])->name('payrolls.reject')->middleware('perm:payroll.reject');
    Route::get('payrolls/{payroll}/print', [\App\Http\Controllers\PayrollController::class, 'print'])->name('payrolls.print')->middleware('perm:payroll.view');
    Route::get('payrolls/{payroll}/export-bank', [\App\Http\Controllers\PayrollController::class, 'exportBank'])->name('payrolls.export-bank')->middleware('perm:payroll.view');
    Route::get('payslip/{employee}/{month}/{year}', [\App\Http\Controllers\PayrollController::class, 'payslip'])->name('payslip');

    // ESS (Employee Self-Service)
    Route::get('hr/my-attendance', [\App\Http\Controllers\HRReportController::class, 'myAttendance'])->name('hr.my-attendance');
    Route::get('hr/my-requests', [\App\Http\Controllers\HRReportController::class, 'myRequests'])->name('hr.my-requests');
    Route::get('hr/my-payslip', [\App\Http\Controllers\HRReportController::class, 'myPayslip'])->name('hr.my-payslip');

    // HR Reports
    Route::get('hr/reports', [\App\Http\Controllers\HRReportController::class, 'attendance'])->name('hr.reports')->middleware('perm:hr_reports.view');
    Route::get('hr/reports/attendance', [\App\Http\Controllers\HRReportController::class, 'attendance'])->name('hr.reports.attendance')->middleware('perm:hr_reports.view');
    Route::get('hr/reports/payroll', [\App\Http\Controllers\HRReportController::class, 'payrollSummary'])->name('hr.reports.payroll')->middleware('perm:hr_reports.view');
    Route::get('hr/reports/employee-statement/{employee}', [\App\Http\Controllers\HRReportController::class, 'employeeStatement'])->name('hr.reports.employee-statement')->middleware('perm:hr_reports.view');

    // ===== العامل الثاني والتحقق المعزّز (E27) =====
    // التحدّي وتأكيد الهوية وصفحة الأمان: للمستخدم نفسه (authz.allowlist)، والتحدّي مستثنى بالاسم من الوسيط mfa
    Route::get('two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'verify'])->name('two-factor.verify')->middleware('throttle:mfa');
    Route::get('security/confirm', [\App\Http\Controllers\Auth\ConfirmStepUpController::class, 'show'])->name('security.confirm');
    Route::post('security/confirm', [\App\Http\Controllers\Auth\ConfirmStepUpController::class, 'store'])->name('security.confirm.store')->middleware('throttle:mfa');
    Route::get('profile/security', [\App\Http\Controllers\ProfileSecurityController::class, 'show'])->name('profile.security');
    Route::post('profile/two-factor', [\App\Http\Controllers\ProfileSecurityController::class, 'enable'])->name('profile.two-factor.enable')->middleware(['stepup:always', 'throttle:6,1']);
    Route::post('profile/two-factor/confirm', [\App\Http\Controllers\ProfileSecurityController::class, 'confirm'])->name('profile.two-factor.confirm')->middleware('throttle:mfa');
    Route::post('profile/two-factor/recovery-codes', [\App\Http\Controllers\ProfileSecurityController::class, 'regenerateCodes'])->name('profile.two-factor.recovery-codes')->middleware(['stepup:always', 'throttle:6,1']);
    Route::delete('profile/two-factor', [\App\Http\Controllers\ProfileSecurityController::class, 'disable'])->name('profile.two-factor.disable')->middleware('stepup:always');
    // إعادة تعيين عامل موظف فقد هاتفه: المدير وحده بسبب (والطوارئ الكاملة: security:mfa-reset من الخادم)
    Route::post('users/{user}/two-factor/reset', [\App\Http\Controllers\UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset')->middleware(['perm:admin', 'stepup:always']);

    // ===== التكاملات الصادرة ورموز MCP (E40) =====
    // الوجهات خلف العلم integration_outbox والرموز خلف mcp_server (404 في المتحكم). الإنشاء والتعديل والحذف والتدوير
    // وإصدار الرمز في security.stepup.routes. ping بحد 6 في الدقيقة، والإعادة 20
    Route::get('settings/integrations', [\App\Http\Controllers\IntegrationController::class, 'index'])->name('integrations.index')->middleware('perm:integrations.manage,mcp.use');
    Route::post('settings/integrations/endpoints', [\App\Http\Controllers\IntegrationController::class, 'store'])->name('integrations.endpoints.store')->middleware('perm:integrations.manage');
    Route::put('settings/integrations/endpoints/{endpoint}', [\App\Http\Controllers\IntegrationController::class, 'update'])->name('integrations.endpoints.update')->middleware('perm:integrations.manage');
    Route::delete('settings/integrations/endpoints/{endpoint}', [\App\Http\Controllers\IntegrationController::class, 'destroy'])->name('integrations.endpoints.destroy')->middleware('perm:integrations.manage');
    Route::post('settings/integrations/endpoints/{endpoint}/rotate', [\App\Http\Controllers\IntegrationController::class, 'rotate'])->name('integrations.endpoints.rotate')->middleware('perm:integrations.manage');
    Route::post('settings/integrations/endpoints/{endpoint}/ping', [\App\Http\Controllers\IntegrationController::class, 'ping'])->name('integrations.endpoints.ping')->middleware(['perm:integrations.manage', 'throttle:6,1']);
    Route::post('settings/integrations/endpoints/{endpoint}/toggle', [\App\Http\Controllers\IntegrationController::class, 'toggle'])->name('integrations.endpoints.toggle')->middleware('perm:integrations.manage');
    Route::get('api/integrations/deliveries', [\App\Http\Controllers\IntegrationController::class, 'deliveries'])->name('integrations.deliveries')->middleware('perm:integrations.manage');
    Route::post('api/integrations/deliveries/{row}/redrive', [\App\Http\Controllers\IntegrationController::class, 'redrive'])->name('integrations.deliveries.redrive')->middleware(['perm:integrations.manage', 'throttle:20,1']);
    Route::post('settings/integrations/mcp-tokens', [\App\Http\Controllers\IntegrationController::class, 'createToken'])->name('integrations.mcp-tokens.store')->middleware('perm:mcp.use');
    Route::delete('settings/integrations/mcp-tokens/{mcpToken}', [\App\Http\Controllers\IntegrationController::class, 'revokeToken'])->name('integrations.mcp-tokens.destroy')->middleware('perm:mcp.use');
});

// التحقق المعزّز (E27): الأفعال الحساسة في config('security.stepup.routes') بأسمائها (أو «الطريقة المسار» لمسار بلا اسم)،
// ويُلحق وسيطها بعد صلاحية المسار، فلا يُسأل عن هويته من لا يملك الفعل أصلاً. يعمل بالعلم step_up
foreach (Route::getRoutes()->getRoutes() as $route) {
    $methods = array_values(array_diff($route->methods(), ['HEAD']));
    $key = $route->getName() ?? ($methods[0] ?? '').' '.$route->uri();
    if (in_array($key, config('security.stepup.routes', []), true)) {
        $route->middleware('stepup');
    }
}

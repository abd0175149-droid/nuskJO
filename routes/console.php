<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تذكير قبل إغلاق نافذة الـ24 ساعة لمحادثة تنتظر ردّاً بشرياً.
// لا قوالب ميتا عندنا (قرار المالك)، فبعد النافذة لا يستطيع أحد مراسلة العميل.
Artisan::command('whatsapp:window-reminders', function () {
    $sent = 0;

    \App\Models\WaConversation::where('needs_attention', true)
        ->where('last_inbound_at', '>', now()->subDay())
        ->where('last_inbound_at', '<=', now()->subHours(21))   // يبقى 3 ساعات أو أقل
        ->get()
        ->each(function ($c) use (&$sent) {
            // تذكير واحد لكل رسالة واردة — رسالة جديدة من العميل تفتح نافذة جديدة
            $key = "wa:winrem:{$c->id}:" . $c->last_inbound_at->timestamp;
            if (!\Illuminate\Support\Facades\Cache::add($key, 1, now()->addDay())) {
                return;
            }

            $left = (int) $c->windowMinutesLeft();
            $title = '⏳ عميل ينتظر ردّاً';
            $body = ($c->display_name ?: $c->phone) . ' — تبقّى ' . intdiv($left, 60) . 'س ' . ($left % 60)
                . 'د قبل إغلاق نافذة الرد، وبعدها لا يمكن مراسلته حتى يكتب من جديد.';
            $url = '/whatsapp/inbox?conv=' . $c->id;

            if ($c->assigned_to) {
                \App\Services\NotificationService::send((int) $c->assigned_to, $title, $body, ['type' => 'whatsapp', 'icon' => '⏳', 'action_url' => $url]);
            } else {
                \App\Services\WhatsApp\WhatsAppInbox::notifyAdmins($title, $body, $url);
            }
            $sent++;
        });

    $this->info("أُرسل {$sent} تذكيراً");
})->purpose('تذكير الموظفين قبل إغلاق نافذة الـ24 ساعة في واتساب');

Schedule::command('whatsapp:window-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// مطابقة دورية بين الدفترين (المبسّط مقابل القيود) + إشعار الإدارة عند أي انحراف
Schedule::command('accounting:reconcile')
    ->dailyAt('01:00')
    ->withoutOverlapping();

// نسخة احتياطية يومية (E01) — تعمل فقط إن فُعّل BACKUP_ENABLED
Schedule::command('backup:run --reason=scheduled')
    ->timezone('Asia/Amman')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->when(fn () => config('backup.enabled'));

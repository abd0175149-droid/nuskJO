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

// تقرير السياج (E04) للقراءة فقط: ما يراه كل موظف بعد السياج، قبل تفعيل WA_FENCE_QUOTES وبعد تغيير التوجيه.
// أعداد ومعرّفات فقط: لا أسماء ولا هواتف في الطرفية.
Artisan::command('whatsapp:fence-report {--json : مخرجات JSON}', function () {
    $total = \App\Models\WaConversation::count();
    $adminChats = \App\Models\WaConversation::where(fn ($q) => $q->where('is_admin_chat', true)
        ->orWhereIn('phone', \App\Models\WaAdminNumber::select('phone')))->count();
    $unrouted = \App\Models\WaConversation::whereNull('assigned_to')
        ->whereNotExists(fn ($s) => $s->selectRaw(1)->from('wa_conversation_topics')
            ->whereColumn('wa_conversation_topics.conversation_id', 'wa_conversations.id'))->count();
    $openQuotes = \App\Models\QuoteRequest::whereIn('status', ['new', 'priced']);
    $fenceQuotes = (bool) config('services.whatsapp.fence_quotes');

    $rows = \App\Models\User::with('role.permissions')->where('is_active', true)->orderBy('id')->get()
        ->filter(fn ($u) => $u->can('whatsapp.view'))
        ->map(function ($u) use ($adminChats, $openQuotes) {
            // طلبات التسعير كما ستكون بعد التفعيل، أياً كانت قيمة المفتاح الآن
            $was = config('services.whatsapp.fence_quotes');
            config(['services.whatsapp.fence_quotes' => true]);
            $quotesFenced = (clone $openQuotes)->visibleTo($u)->count();
            config(['services.whatsapp.fence_quotes' => $was]);

            return [
                'user' => $u->id,
                'role' => $u->role?->slug,
                'view_all' => $u->can('whatsapp.view_all') ? 'yes' : 'no',
                'topics' => count(\App\Services\WhatsApp\TopicRouter::topicsForUser($u->id)),
                'visible' => \App\Models\WaConversation::query()->visibleTo($u)->count(),
                'assigned' => \App\Models\WaConversation::where('assigned_to', $u->id)->count(),
                'admin_hidden' => $u->can('whatsapp.admin_chats') ? 0 : $adminChats,
                'quotes_fenced' => $quotesFenced,
            ];
        })->values();

    $summary = [
        'conversations' => $total,
        'admin_chats' => $adminChats,
        'unrouted' => $unrouted,
        'open_quotes' => (clone $openQuotes)->count(),
        'fence_quotes' => $fenceQuotes,
        'users_seeing_nothing' => $rows->where('visible', 0)->where('view_all', 'no')->pluck('user')->values()->all(),
    ];

    if ($this->option('json')) {
        $this->line(json_encode(['summary' => $summary, 'users' => $rows->all()], JSON_UNESCAPED_UNICODE));

        return 0;
    }

    $this->line("المحادثات: {$total} · محادثات الإدارة: {$adminChats} · بلا وسم ولا توكيل (للمدير وحده): {$unrouted}");
    $this->line('طلبات التسعير المفتوحة: '.$summary['open_quotes'].' · WA_FENCE_QUOTES: '.($fenceQuotes ? 'مفعّل' : 'مطفأ'));
    $this->table(['مستخدم', 'الدور', 'يرى الكل', 'اختصاصات', 'يرى', 'موكلة له', 'إدارة محجوبة', 'طلبات بعد التفعيل'], $rows->all());
    if ($summary['users_seeing_nothing']) {
        $this->warn('لا يرون أي محادثة (أضف اختصاصاً في /whatsapp/routing أو وكّل لهم): '.implode(', ', $summary['users_seeing_nothing']));
    }

    return 0;
})->purpose('للقراءة فقط: ما يراه كل موظف من محادثات واتساب وطلبات التسعير بعد السياج (أعداد فقط)');

// نهاية دعم إصدار Graph (E05): تنبيه يومي للمدير قبل 60 يوماً، أو لإصدار منتهٍ أو مجهول، أو ويبهوك بلا إصدار مضبوط
Artisan::command('whatsapp:graph-version-check', function () {
    $sent = 0;
    $kinds = ['requests' => ['إصدار الطلبات', 'graph_version'], 'webhook' => ['إصدار الويبهوك', 'webhook_version']];
    foreach ($kinds as $kind => [$label, $key]) {
        $version = config("services.whatsapp.{$key}") ?: null;
        $status = \App\Services\WhatsApp\GraphVersions::status($version);

        if ($kind === 'webhook' && $version === null) {
            [$title, $reason] = ['⚠️ إصدار Graph يحتاج مراجعة', 'WA_WEBHOOK_VERSION غير مضبوط — اضبطه على إصدار الويبهوك في لوحة ميتا'];
        } elseif ($status['level'] === 'ok') {
            continue;
        } elseif ($status['level'] === 'unknown') {
            [$title, $reason] = ['⚠️ إصدار Graph يحتاج مراجعة', "الإصدار {$version} غير موجود في جدول الإصدارات"];
        } elseif ($status['level'] === 'expired') {
            [$title, $reason] = ['⛔ إصدار Graph انتهى دعمه', "الإصدار {$version} انتهى في {$status['sunset']}"];
        } else {
            [$title, $reason] = ['⚠️ إصدار Graph يقترب من نهاية دعمه', "الإصدار {$version} ينتهي في {$status['sunset']} (بعد {$status['days_left']} يوماً)"];
        }

        $this->warn("{$label}: {$reason}");
        if (\Illuminate\Support\Facades\Cache::add("wa:graphver:{$kind}:".($version ?? 'none').':'.now()->toDateString(), 1, now()->addDay())) {
            \Illuminate\Support\Facades\Log::error("WA graph version check [{$kind}]: {$reason}");
            \App\Services\WhatsApp\WhatsAppInbox::notifyAdmins($title, "{$label}: {$reason} — راجع خطة الترقية", '/whatsapp/settings');
            $sent++;
        }
    }

    $this->info($sent ? "أُرسل {$sent} تنبيهاً" : 'لا تنبيه جديد');

    return 0;
})->purpose('تنبيه المدير قبل 60 يوماً من نهاية دعم إصدار Graph للطلبات أو للويبهوك');

Schedule::command('whatsapp:graph-version-check')
    ->timezone('Asia/Amman')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// أعلام موثوقية الرد (E07) وقت التشغيل، بلا نشر: whatsapp:reliability [flag] [on|off]
Artisan::command('whatsapp:reliability {flag?} {state?}', function () {
    $settings = \App\Models\WaBotSetting::current();
    $flag = $this->argument('flag');

    if ($flag !== null) {
        if (!array_key_exists($flag, \App\Models\WaBotSetting::RELIABILITY_FLAGS)) {
            $this->error("علم غير معروف: {$flag}");

            return 1;
        }
        $state = $this->argument('state');
        if (!in_array($state, ['on', 'off'], true)) {
            $this->error('الحالة on أو off');

            return 1;
        }
        $before = $settings->flag($flag);
        \App\Support\AuditActor::as('system', 'artisan:whatsapp:reliability', fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($settings, $flag, $state, $before) {
            $settings->update(['reliability' => array_merge($settings->reliability ?? [], [$flag => $state === 'on'])]);
            \App\Models\AuditLog::log(\App\Enums\AuditAction::WaReliabilityFlag, 'wa_bot_settings', $settings->id, $flag, ['from' => $before, 'to' => $state === 'on']);
        }));
        \App\Services\WhatsApp\WaLog::warning('reliability.flag', ['field' => $flag, 'status' => $state]);
    }

    $this->table(['العلم', 'الحالة', 'الافتراضي'], collect(\App\Models\WaBotSetting::RELIABILITY_FLAGS)
        ->map(fn ($default, $name) => [$name, $settings->fresh()->flag($name) ? 'on' : 'off', $default ? 'on' : 'off'])->values()->all());

    return 0;
})->purpose('عرض أعلام موثوقية رد الواتساب أو تبديلها: whatsapp:reliability [flag] [on|off]');

// نبضات التشغيل (E08): المجدول يكتب نبضته، ويدفع لكل عامل وظيفة تكتب نبضته (OpsHealth على /up)
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(\App\Services\Ops\OpsHealth::SCHEDULE_BEAT, now()->getTimestamp()))
    ->everyMinute()
    ->name('ops:schedule-beat')
    ->withoutOverlapping();
foreach (\App\Services\Ops\OpsHealth::queues() as $queue) {
    Schedule::job(new \App\Jobs\OpsHeartbeat($queue), $queue)
        ->everyMinute()
        ->name("ops:queue-beat:{$queue}");
}
// فحوص ميتا للقراءة (WA_META_CHECKS): الرمز يومياً وصحة القناة كل ساعة، والنتيجة في بطاقة صحة النظام
Schedule::command('whatsapp:credentials-check')
    ->dailyAt(config('ops.credentials_check_at'))
    ->timezone('Asia/Amman')
    ->withoutOverlapping(10);
Schedule::command('whatsapp:health-status')
    ->hourly()
    ->withoutOverlapping(10);

// الوظائف الفاشلة تحمل بيانات عملاء في payload والاستثناء: أسبوع ثم تُحذف
Schedule::command('queue:prune-failed --hours=168')->daily();

// عميل بلا رقم (اسم مستخدم واتساب، BSUID وحده): store ثم staff ثم full (E11a، docs/RELEASE.md)
Artisan::command('whatsapp:identity-mode {mode?}', function () {
    $mode = $this->argument('mode');
    if ($mode !== null) {
        if (!in_array($mode, \App\Services\WhatsApp\IdentityMode::MODES, true)) {
            $this->error('الوضع: '.implode(' أو ', \App\Services\WhatsApp\IdentityMode::MODES));

            return 1;
        }
        $before = \App\Services\WhatsApp\IdentityMode::current();
        \App\Support\AuditActor::as('system', 'artisan:whatsapp:identity-mode', fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($mode, $before) {
            \App\Models\Setting::set('wa_identity_mode', $mode);
            \App\Models\AuditLog::log(\App\Enums\AuditAction::WaIdentityMode, 'setting', null, 'wa_identity_mode', ['from' => $before, 'to' => $mode]);
        }));
        \App\Services\WhatsApp\WaLog::warning('identity.mode', ['status' => $mode]);
    }
    $this->line('وضع العميل بلا رقم: '.\App\Services\WhatsApp\IdentityMode::current());

    return 0;
})->purpose('عرض أو تبديل التعامل مع عميل بلا رقم: whatsapp:identity-mode [store|staff|full]');

Schedule::command('whatsapp:window-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// المُصالِح (يعمل فقط والعلم reconcile مفعّل؛ إنذار تأخر الطابور دائماً). المانع 5 دقائق لا 24 ساعة،
// فلا يعلق إن قُتل schedule:work
Schedule::command('whatsapp:reconcile-replies')
    ->everyMinute()
    ->withoutOverlapping(5);

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

// مراقب النسخ: تأخر اللقطة أو التجميع أو السحب أو التمرين، وامتلاء القرص (تنبيه يومي لكل نوع)
Schedule::command('backup:watchdog')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => config('backup.enabled'));

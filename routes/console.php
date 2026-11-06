<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
        \App\Support\AuditActor::as('system', 'artisan:whatsapp:reliability', fn () => \App\Services\WhatsApp\Settings\SettingsWriter::apply(
            ['reliability' => array_merge($settings->reliability ?? [], [$flag => $state === 'on'])],
            null, \App\Enums\AuditAction::WaReliabilityFlag, ['flag' => $flag, 'from' => $before, 'to' => $state === 'on'], label: $flag));
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
// الوظائف الفاشلة تحمل بيانات عملاء في payload والاستثناء: أسبوع ثم تُحذف
Schedule::command('queue:prune-failed --hours=168')->daily();

// دورة حياة البيانات (E13): جدولة موحّدة بعد النسخة الليلية (02:30)، ولا تقليم بلا نسخة حديثة.
// model:prune للقائمة البيضاء config('retention.prunable_models') وحدها: ضجيج التدقيق (E09) والإشعارات
// وإجراءات المساعد الإداري واستهلاك البوت بعد تجميعه. لا جدول مالي أبداً
Schedule::command('whatsapp:usage-rollup')
    ->timezone('Asia/Amman')
    ->monthlyOn(1, '03:10')
    ->withoutOverlapping();
Schedule::command('model:prune', ['--model' => config('retention.prunable_models'), '--chunk' => 500])
    ->timezone('Asia/Amman')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->when(fn () => \App\Support\Retention::backupFresh());
Schedule::command('ops:prune-cache')
    ->timezone('Asia/Amman')
    ->dailyAt('03:40')
    ->withoutOverlapping();
Schedule::command('whatsapp:prune')
    ->timezone('Asia/Amman')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->when(fn () => \App\Support\FeatureFlags::on('retention_purge') && \App\Support\Retention::backupFresh());
// أرشيف المرفقات (E14): التقليم بعد النسخة الليلية، والكنس كل ساعة (مؤقت ويتيم ومفقود وسقف)
Schedule::command('whatsapp:media-prune')
    ->timezone('Asia/Amman')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->when(fn () => \App\Support\Retention::backupFresh());
Schedule::command('whatsapp:media-sweep')
    ->hourly()
    ->withoutOverlapping();
// الردود العالقة (E17): قائمة لبطاقة المدير، وتنبيه مرة في الساعة إن بقيت عالقة 15 دقيقة
Schedule::command('whatsapp:stuck-replies --notify')
    ->everyTenMinutes()
    ->withoutOverlapping(10);
// المحادثات المؤجلة (E17) تعود «تدخّلاً» في موعدها
Schedule::command('whatsapp:snooze-wake')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

// دفتر الإرسال (E19.8): المعلّق أقدم من 15 دقيقة يصير «غير مؤكد» مع شارة وتنبيه. لا يعيد الإرسال، ولا يعمل إلا بالعلم send_ledger
Schedule::command('whatsapp:reap-pending')
    ->everyTenMinutes()
    ->withoutOverlapping(5);

// استقبال الويبهوك (E20): إعادة الفاشل والمنقطع كل دقيقة بميزانية 3 محاولات، والمحو كل ساعة (الجسم 72 ساعة حداً
// أقصى، والأثر الوصفي 14 يوماً). لا يتوقفان بالعلم: صفوف قائمة تُعاد وتُمحى حتى بعد إطفاء الحفظ
Schedule::command('whatsapp:replay-webhooks')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => !\App\Support\FeatureFlags::on('ops_tick'));
Schedule::command('whatsapp:webhook-events-prune')
    ->hourlyAt(15)
    ->withoutOverlapping(10);

// فهرس البحث في الرسائل (E30): فحص يومي بعد النسخة الليلية والتقليم (03:45) والمرفقات (04:00)، يصلح ما ينقص
// (هجرة تعيد بناء wa_messages تُسقط الـ triggers بصمت) ويُشعر المدراء مرة في اليوم
Schedule::command('whatsapp:fts-check --repair')
    ->timezone('Asia/Amman')
    ->dailyAt('04:10')
    ->withoutOverlapping(30);
// صمت الوارد في وقت الدوام (E37): قراءة فقط، والعلم inbound_silence_alert يقرر التنبيه. لا يدخل /up
Schedule::command('whatsapp:inbound-silence')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5);
// حسابات الموظفين (E27): آخر نشاط من جدول الجلسات كل 15 دقيقة باستعلام واحد (لا كتابة لكل طلب)، وتقرير الخمول
// الأسبوعي للمدراء صباح الأحد (يرسل بالعلم idle_report، ولا يعطّل أحداً)
Schedule::command('users:sync-last-seen')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);
Schedule::command('users:idle-report')
    ->timezone('Asia/Amman')
    ->weeklyOn(0, '08:47')
    ->withoutOverlapping();
// الحظر (E31): مقارنة قرائية يومية مع قائمة المحظورين على كل رقم، بالعلم wa_block وحده. لا تغيّر أي حظر،
// وتُشعر المدراء إن ظهر حظر لا يجده الرقم أو حظر عليه بلا سجل عندنا
Schedule::command('whatsapp:blocks-check')
    ->timezone('Asia/Amman')
    ->dailyAt('04:10')
    ->withoutOverlapping(10);

// بوابة OpenWA (2026-11-06): صحة كل رقم بفحص حقيقي كل دقيقتين (وإعادة تشغيل العالق)، ومعرفة هواتف مرسلي @lid كل 30 دقيقة
Schedule::command('whatsapp:channel-watch')
    ->everyTwoMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();
Schedule::command('whatsapp:resolve-lids')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10);
// طابور رسائل النظام: الحجز والتوزيع كل دقيقة بحدود كل رقم ونوع (SendGovernor)، وشبكة أمان للجدولة الحدثية كل 15 دقيقة
Schedule::command('wa:outbox-tick')
    ->everyMinute()
    ->withoutOverlapping(3);
Schedule::command('wa:schedule-system')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// فحص الإعدادات اليومي (E23): الحصيلة في 'wa:doctor:last' يومين لبطاقة الإعدادات وصفحة الحالة، وتنبيه واحد عند تحوّل
// فحص إلى خطأ (العلم channel_monitor)
Schedule::command('whatsapp:doctor --budget=20 --store --no-ansi')
    ->timezone('Asia/Amman')
    ->dailyAt('07:50')
    ->withoutOverlapping(10);

// رسائل الموظف الصوتية (E34): كنس ما بقي من ملفات التحويل المؤقتة (عملية قُتلت قبل حذفها) كل ساعة
Schedule::command('whatsapp:voice-tmp-sweep')
    ->hourlyAt(35)
    ->withoutOverlapping(10);

// التكاملات الصادرة (E40): المُصالِح كل 5 دقائق (لا يعمل إلا بالعلم integration_outbox)، والتقليم يومياً بعد النسخة
// (المُسلَّم 30 يوماً والفاشل 90، يعمل والعلم مطفأ: تقليص لا حماية)
Schedule::command('integrations:redrive')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);
Schedule::command('integrations:prune')
    ->timezone('Asia/Amman')
    ->dailyAt('04:20')
    ->withoutOverlapping();
// المراقبة التشغيلية (E24): لقطة المقاييس كل 5 دقائق في مخزن ops (file) وقواعد التنبيه (العلم ops_alerts؛ مطفأ = سجل perf
// فقط). قراءات مجمّعة وحدها، ولا رسالة واتساب. القائمة الموحدة للمهام وميزانية الحمل في docs/ops/load-budget.md
Schedule::command('whatsapp:ops-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->name('wa:ops-check');
// ميزانية الحمل (E24): أمرا الدقيقة أعلاه (إعادة الويبهوك والمُصالِح) في عملية واحدة بالعلم ops_tick
Schedule::command('ops:tick')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => \App\Support\FeatureFlags::on('ops_tick'));
// قاطع مزوّد الذكاء (E12): محاولة half-open بنداء صناعي بلا بيانات عملاء، فيُغلق القاطع ويصل إشعار العودة
// حتى لو لم يراسل أحد. يعمل فقط والقاطع مفتوح وانتهت مدته ولا محاولة جارية
Artisan::command('whatsapp:llm-probe', function () {
    $s = \App\Models\WaBotSetting::current();
    $provider = (string) $s->provider;
    if (\App\Services\WhatsApp\LlmCircuitBreaker::mode($s) === 'off' || !$s->llmKey()) {
        return 0;
    }
    $until = \App\Services\WhatsApp\LlmCircuitBreaker::openUntil($provider);
    if ($until === null || $until->isFuture() || \App\Services\WhatsApp\LlmCircuitBreaker::allow($provider) !== \App\Services\WhatsApp\LlmCircuitBreaker::PROBE) {
        return 0;
    }
    try {
        $llm = \App\Services\WhatsApp\Llm\LlmFactory::make($provider);
        $llm->generate((string) $s->llmKey(), (string) $s->model, ['ping'], $llm->buildHistory([['role' => 'user', 'text' => 'ping']]), [], 15);
        \App\Services\WhatsApp\LlmCircuitBreaker::recordSuccess($provider);
        $this->info('المزوّد عاد');
    } catch (\Throwable $e) {
        $classified = \App\Services\WhatsApp\Llm\LlmErrorClassifier::fromThrowable($provider, $e)
            ?? new \App\Services\WhatsApp\Llm\Exceptions\LlmUnavailable($provider, 'probe failed');
        \App\Services\WhatsApp\LlmCircuitBreaker::recordFailure($provider, $classified);
        $this->warn('المزوّد ما زال متعطّلاً: '.$classified->kind());
    }

    return 0;
})->purpose('محاولة half-open لقاطع مزوّد الذكاء (E12)');

Schedule::command('whatsapp:llm-probe')
    ->everyTwoMinutes()
    ->withoutOverlapping(5);

// طلبات حذف تقترب من مهلتها (E13): تنبيه يومي للمدراء
Schedule::command('whatsapp:erasure-sla')
    ->timezone('Asia/Amman')
    ->dailyAt('09:10')
    ->withoutOverlapping();

// المُصالِح (يعمل فقط والعلم reconcile مفعّل؛ إنذار تأخر الطابور دائماً). المانع 5 دقائق لا 24 ساعة،
// فلا يعلق إن قُتل schedule:work
Schedule::command('whatsapp:reconcile-replies')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => !\App\Support\FeatureFlags::on('ops_tick'));

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

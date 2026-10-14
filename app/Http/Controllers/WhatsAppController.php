<?php

namespace App\Http\Controllers;

use App\Console\Commands\BackupSafeMode;
use App\Models\Setting;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\TopicRouter;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    // ==================== الإنبوكس ====================

    public function inbox(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $s = WaBotSetting::current();

        return Inertia::render('WhatsApp/Inbox', [
            'title' => 'محادثات الواتساب',
            'conversations' => $this->conversationList($request),
            'filters' => $request->only(['filter', 'search', 'topic']),
            'status' => [
                'channel_ready' => $s->channelReady(),
                'bot_ready' => $s->botReady(),
                'bot_enabled' => $s->enabled,
                'suspended' => $s->wa_suspended,
            ],
            'canReply' => auth()->user()->can('whatsapp.reply'),
            'canAssign' => auth()->user()->can('whatsapp.assign'),
            'canToggleBot' => auth()->user()->can('whatsapp.bot_toggle'),
            'seesAll' => auth()->user()->can('whatsapp.view_all'),
            'canSeeAdminChats' => auth()->user()->can('whatsapp.admin_chats'),
            'topics' => TopicRouter::TOPICS,
            // من يُوكَّل يجب أن يستطيع فتح المحادثة؛ وإلا رُفض التوكيل في assign
            'staff' => auth()->user()->can('whatsapp.assign')
                ? \App\Models\User::with('role.permissions')->where('is_active', true)->orderBy('name')->get()
                    ->filter(fn ($u) => $u->can('whatsapp.view'))
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                : [],
        ]);
    }

    /** JSON — لتحديث القائمة دورياً */
    public function conversations(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        return response()->json([
            'conversations' => $this->conversationList($request),
            'unread_total' => (int) WaConversation::query()->visibleTo(auth()->user())->sum('unread_count'),
        ]);
    }

    private function conversationList(Request $request): array
    {
        $filter = $request->get('filter', 'all');

        $me = auth()->user();
        // محادثات أرقام الإدارة تُعلَّم في الصندوق — يخدمها المساعد الإداري لا بوت العملاء
        $adminPhones = rescue(fn () => \App\Models\WaAdminNumber::where('is_active', true)->pluck('phone')->flip()->all(), [], false);

        return WaConversation::query()
            ->with(['client:id,name,code', 'assignee:id,name'])
            // السياج: محادثات الإدارة، ثم الاختصاص والتوكيل (الشروط أدناه تضيّق ولا توسّع)
            ->visibleTo($me)
            ->when($request->topic, fn ($q, $t) => $q->whereExists(function ($sub) use ($t) {
                $sub->selectRaw(1)->from('wa_conversation_topics')
                    ->whereColumn('wa_conversation_topics.conversation_id', 'wa_conversations.id')
                    ->where('wa_conversation_topics.topic', $t);
            }))
            ->when($filter === 'mine', fn ($q) => $q->where('assigned_to', $me->id))
            ->when($request->search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('phone', 'like', "%{$s}%")
                      ->orWhere('display_name', 'like', "%{$s}%");
                });
            })
            ->when($filter === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            ->when($filter === 'attention', fn ($q) => $q->where('needs_attention', true))
            ->when($filter === 'bot', fn ($q) => $q->where('bot_enabled', true))
            ->when($filter === 'human', fn ($q) => $q->where('bot_enabled', false))
            ->orderByDesc('last_message_at')
            ->limit(80)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'phone' => $c->phone,
                'name' => $c->display_name ?: $c->client?->name ?: $c->phone,
                'client' => $c->client?->name,
                'client_id' => $c->client_id,
                'bot_enabled' => (bool) $c->bot_enabled,
                'paused' => $c->bot_paused_until && $c->bot_paused_until->isFuture(),
                'needs_attention' => (bool) $c->needs_attention,
                'unread' => (int) $c->unread_count,
                'preview' => $c->last_message_preview,
                'last_at' => $c->last_message_at?->diffForHumans(),
                'window_open' => $c->isWindowOpen(),
                'window_left' => $c->windowMinutesLeft(),
                'is_admin' => (bool) $c->is_admin_chat || isset($adminPhones[$c->phone]),
                'assignee' => $c->assignee?->name,
                'assigned_to' => $c->assigned_to,
                'topics' => TopicRouter::topicsOf($c),
            ])->all();
    }

    /**
     * خيط المحادثة بمؤشر keyset على الفهرس [conversation_id, id]:
     *   بلا مؤشر: أحدث 100 · before=<id>: الأقدم منه · after=<id>&since=<وقت>: الجديد وتحديثات حالة الصادر.
     * طلب GET بلا آثار جانبية: التصفير صار POST /read صريحاً.
     */
    public function messages(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $v = $request->validate([
            'before' => 'nullable|integer|min:1|prohibits:after',
            'after' => 'nullable|integer|min:0|prohibits:before',
            'since' => 'nullable|date',
            'limit' => 'nullable|integer|min:1',
        ]);
        $syncedAt = now()->subSeconds(5)->format('Y-m-d H:i:s');   // تداخل مقصود؛ تطبيق التحديثات بالمعرّف idempotent
        $limit = min((int) ($v['limit'] ?? 100), 100);
        $columns = ['id', 'direction', 'source', 'msg_type', 'body', 'status', 'error_message', 'staff_id', 'created_at'];

        $hasMore = false;
        $gap = false;
        $updates = [];

        if (isset($v['after'])) {
            $rows = $conversation->messages()->with('staff:id,name')
                ->where('id', '>', $v['after'])->orderBy('id')->limit(200)->get($columns);
            $gap = $rows->count() >= 200;

            if (!empty($v['since'])) {
                $updates = $conversation->messages()
                    ->where('direction', 'out')->where('id', '<=', $v['after'])
                    ->where('updated_at', '>=', $v['since'])
                    ->orderByDesc('id')->limit(100)
                    ->get(['id', 'status', 'error_message'])
                    ->map(fn ($m) => ['id' => $m->id, 'status' => $m->status, 'error' => $m->error_message])
                    ->all();
            }
        } else {
            $rows = $conversation->messages()->with('staff:id,name')
                ->when(isset($v['before']), fn ($q) => $q->where('id', '<', $v['before']))
                ->orderByDesc('id')->limit($limit + 1)->get($columns);
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }

        // staff_id كان يُحفظ ولا يُرسل للواجهة، فلم يكن يظهر من أرسل الرسالة
        $msgs = $rows->map(fn ($m) => [
            'id' => $m->id,
            'direction' => $m->direction,
            'source' => $m->source,
            'type' => $m->msg_type,
            'body' => $m->body,
            'status' => $m->status,
            'error' => $m->error_message,
            'sender' => $m->source === 'staff' ? ($m->staff?->name ?: 'موظف') : null,
            'at' => $m->created_at?->format('Y-m-d H:i'),
        ])->values();

        $first = !isset($v['before']) && !isset($v['after']);

        return response()->json(array_filter([
            'messages' => $msgs,
            'has_more' => $hasMore,
            'gap' => $gap,
            'updates' => $updates,
            'synced_at' => $syncedAt,
            'conversation' => [
                'id' => $conversation->id,
                'name' => $conversation->display_name ?: $conversation->phone,
                'phone' => $conversation->phone,
                'client' => $conversation->client?->name,
                'bot_enabled' => (bool) $conversation->bot_enabled,
                'needs_attention' => (bool) $conversation->needs_attention,
                'unread' => (int) $conversation->unread_count,
                'window_open' => $conversation->isWindowOpen(),
                'window_left' => $conversation->windowMinutesLeft(),
                'is_admin' => (bool) $conversation->is_admin_chat || (bool) \App\Models\WaAdminNumber::forPhone($conversation->phone),
                'topics' => TopicRouter::topicsOf($conversation),
                'assignee' => $conversation->assignee?->name,
                'assigned_to' => $conversation->assigned_to,
            ],
            'notes' => $first ? $conversation->notes()->orderByDesc('id')->limit(10)->pluck('note') : null,
        ], fn ($value) => $value !== null));
    }

    // ==================== المواضيع والتوجيه ====================

    /** إضافة أو إزالة وسم موضوع يدوياً */
    public function toggleTopic(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $data = $request->validate([
            'topic' => ['required', 'string', Rule::in(array_keys(TopicRouter::TOPICS))],
            'attach' => 'required|boolean',
        ]);

        $me = auth()->user();
        if ($data['attach']) {
            // التوجيه مسموح؛ والتوكيل الصامت يذهب للمختص الأساسي لا للموظف نفسه
            TopicRouter::tag($conversation, $data['topic'], 'manual', $me->id);
        } else {
            // إزالة وسم خارج اختصاصك تخفي المحادثة عن مختصه: للمسؤول عن التوزيع وحده
            $mine = in_array($data['topic'], TopicRouter::topicsForUser($me->id), true);
            abort_unless($mine || $me->can('whatsapp.assign') || $me->can('whatsapp.view_all'), 403, 'لا يمكنك إزالة وسم خارج اختصاصك');
            TopicRouter::untag($conversation, $data['topic']);
        }

        $message = $data['attach'] ? 'أُضيف الوسم' : 'أُزيل الوسم';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'topics' => TopicRouter::topicsOf($conversation)])
            : back()->with('success', $message);
    }

    /** نقل المحادثة لموظف آخر */
    public function assign(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.assign'), 403);

        $data = $request->validate(['user_id' => 'nullable|integer|exists:users,id']);

        // لا توكيل لمن لا يستطيع فتح المحادثة: مستخدم فعّال يملك whatsapp.view
        if (!empty($data['user_id'])) {
            $assignee = \App\Models\User::find($data['user_id']);
            if (!$assignee?->is_active || !$assignee->can('whatsapp.view')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['user_id' => 'هذا المستخدم لا يستطيع فتح محادثات الواتساب']);
            }
        }

        $conversation->update(['assigned_to' => $data['user_id'] ?: null]);

        if ($data['user_id']) {
            try {
                \App\Services\NotificationService::send(
                    (int) $data['user_id'],
                    '💬 محادثة واتساب مُحوّلة إليك',
                    ($conversation->display_name ?: $conversation->phone) . ' — حوّلها ' . auth()->user()->name,
                    ['type' => 'whatsapp', 'icon' => '💬', 'action_url' => '/whatsapp/inbox']
                );
            } catch (\Throwable $e) {
                // الإشعار ليس حرجاً — التوكيل محفوظ
            }
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'تم تحديث التوكيل', 'assigned_to' => $conversation->assigned_to])
            : back()->with('success', 'تم تحديث التوكيل');
    }

    /** شاشة توجيه المواضيع — المدير يحدّد مختصّي كل موضوع */
    public function routing()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        return Inertia::render('WhatsApp/Routing', [
            'title' => 'توجيه محادثات الواتساب',
            'topics' => TopicRouter::routingTable(),
            'users' => \App\Models\User::where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    /** حفظ خريطة التوجيه كاملة */
    public function saveRouting(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $request->validate([
            'routes' => 'present|array',
            'routes.*.topic' => ['required', 'string', Rule::in(array_keys(TopicRouter::TOPICS))],
            'routes.*.users' => 'present|array',
            'routes.*.users.*.user_id' => 'required|exists:users,id',
            'routes.*.users.*.is_primary' => 'boolean',
        ]);

        DB::transaction(function () use ($data) {
            DB::table('wa_topic_users')->delete();

            foreach ($data['routes'] as $route) {
                $seenPrimary = false;

                foreach ($route['users'] as $u) {
                    // أساسيّ واحد فقط لكل موضوع — الأول يفوز
                    $primary = !$seenPrimary && !empty($u['is_primary']);
                    $seenPrimary = $seenPrimary || $primary;

                    DB::table('wa_topic_users')->insert([
                        'topic' => $route['topic'],
                        'user_id' => $u['user_id'],
                        'is_primary' => $primary,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return back()->with('success', 'حُفظ التوجيه');
    }

    /** التصفير صريح عند فتح المحادثة، لا جانبياً مع كل استطلاع للرسائل */
    public function markRead(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $conversation->update(['unread_count' => 0]);

        return response()->json(['ok' => true]);
    }

    /** ردّ الموظف — يوقف البوت مؤقتاً تلقائياً داخل بوّابة الإرسال */
    public function send(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $data = $request->validate(['body' => 'required|string|max:4000']);

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', auth()->id());

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function toggleBot(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.bot_toggle'), 403);

        $conversation->update([
            'bot_enabled' => !$conversation->bot_enabled,
            'needs_attention' => false,
            'bot_paused_until' => null,
        ]);

        return response()->json(['bot_enabled' => (bool) $conversation->fresh()->bot_enabled]);
    }

    public function resolve(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        $conversation->update(['needs_attention' => false]);
        return response()->json(['ok' => true]);
    }

    // ==================== الإعدادات ====================

    public function settings()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $s = WaBotSetting::current();

        return Inertia::render('WhatsApp/Settings', [
            'title' => 'إعدادات بوت الواتساب',
            'settings' => [
                'enabled' => (bool) $s->enabled,
                'wa_suspended' => (bool) $s->wa_suspended,
                'wa_phone_number_id' => $s->wa_phone_number_id,
                // يُتعلّمان من أول رسالة لرقمنا؛ بهما تُميَّز أحداث الحساب لأرقام أخرى على التطبيق نفسه
                'wa_waba_id' => $s->wa_waba_id,
                'wa_display_phone' => $s->wa_display_phone,
                'wa_verify_token' => $s->wa_verify_token,
                'provider' => $s->provider ?: 'google',
                'model' => $s->model,
                'system_prompt' => $s->system_prompt ?: BotEngine::DEFAULT_PROMPT,
                'kb_entries' => rescue(fn () => \App\Models\WaKnowledgeEntry::where('is_active', true)->count(), 0, false),
                'kb_chars' => rescue(fn () => \App\Models\WaKnowledgeEntry::activeChars(), 0, false),
                'context_messages' => (int) $s->context_messages,
                'pause_minutes' => (int) $s->pause_minutes,
                'max_tool_loops' => (int) $s->max_tool_loops,
                'rate_limit_per_hour' => (int) $s->rate_limit_per_hour,
                'fail_message' => $s->fail_message,
                'fail_handoff' => (bool) $s->fail_handoff,
                'tools_config' => $s->tools_config ?? [],
                // الأسرار: نكشف وجودها فقط لا قيمتها
                'has_token' => !empty($s->token()),
                'has_app_secret' => !empty($s->appSecret()),
                'has_api_key' => !empty($s->llmKey()),
                'env_token' => !empty(config('services.whatsapp.token')),
                'env_api_key' => $s->envKeyPresent(),
            ],
            'providers' => \App\Services\WhatsApp\Llm\LlmFactory::PROVIDERS,
            'webhookUrl' => url('/api/whatsapp/webhook'),
            'defaultPrompt' => BotEngine::DEFAULT_PROMPT,
            'toolList' => \App\Services\WhatsApp\BotTools::LABELS,
            'graph' => \App\Services\WhatsApp\GraphVersions::panel(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $request->validate([
            'enabled' => 'boolean',
            'wa_suspended' => 'boolean',
            'wa_phone_number_id' => 'nullable|string|max:64',
            'wa_verify_token' => 'nullable|string|max:128',
            'wa_token' => 'nullable|string',
            'wa_app_secret' => 'nullable|string',
            'api_key' => 'nullable|string',
            'provider' => 'nullable|in:google,anthropic',
            'model' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'system_prompt' => 'nullable|string|max:20000',
            'knowledge_base' => 'nullable|string|max:40000',
            'context_messages' => 'integer|min:4|max:60',
            'pause_minutes' => 'integer|min:1|max:1440',
            'max_tool_loops' => 'integer|min:1|max:8',
            'rate_limit_per_hour' => 'integer|min:1|max:200',
            'fail_message' => 'nullable|string|max:500',
            'fail_handoff' => 'boolean',
            'tools_config' => 'nullable|array',
        ], [
            // اسم النموذج يدخل مسار رابط المزوّد
            'model.regex' => 'اسم النموذج يقبل الحروف والأرقام و . _ - فقط',
        ]);

        // الأسرار الفارغة لا تمسح المحفوظ
        foreach (['wa_token', 'wa_app_secret', 'api_key'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $settings = WaBotSetting::current();

        // بعد استعادة نسخة: القفل يبقى حتى يعتمد المدير المراجعة (وضعه مسموح دائماً)
        if (array_key_exists('wa_suspended', $data) && !$data['wa_suspended'] && $settings->wa_suspended
            && Setting::get(BackupSafeMode::REVIEW_KEY)) {
            throw ValidationException::withMessages([
                'wa_suspended' => 'أكمل مراجعة ما بعد الاستعادة من صفحة النسخ الاحتياطي أولاً',
            ]);
        }

        // رقم جديد: ما تعلّمناه عن الرقم القديم لا يصلح لتمييز أحداث الحساب، فيُتعلّم من جديد مع أول رسالة
        if (array_key_exists('wa_phone_number_id', $data) && (string) $data['wa_phone_number_id'] !== (string) $settings->wa_phone_number_id
            && \Illuminate\Support\Facades\Schema::hasColumn('wa_bot_settings', 'wa_display_phone')) {
            $settings->forceFill(['wa_waba_id' => null, 'wa_display_phone' => null]);
        }

        $settings->update($data);

        // أسماء الحقول فقط، لا قيمة أي سر
        \App\Models\AuditLog::log('update', 'wa_bot_settings', $settings->id, 'إعدادات البوت', ['changed' => array_keys($data)]);

        return back()->with('success', 'تم حفظ إعدادات البوت');
    }

    // ==================== التحليلات ====================

    public function analytics()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        [$usage, $quality] = $this->maskAdminChats(\App\Services\WhatsApp\BotAnalytics::usage(), \App\Services\WhatsApp\BotAnalytics::quality());

        return Inertia::render('WhatsApp/Analytics', [
            'title' => 'استهلاك البوت وجودته',
            'usage' => $usage,
            'quality' => $quality,
        ]);
    }

    public function analyticsData()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        [$usage, $quality] = $this->maskAdminChats(\App\Services\WhatsApp\BotAnalytics::usage(), \App\Services\WhatsApp\BotAnalytics::quality());

        return response()->json(['usage' => $usage, 'quality' => $quality]);
    }

    /**
     * محادثات أرقام الإدارة في التحليلات: «محادثة إدارة» بلا رابط ولا نص لمن لا يملك whatsapp.admin_chats.
     * الأرقام المجمّعة تبقى كما هي.
     */
    private function maskAdminChats(array $usage, array $quality): array
    {
        if (auth()->user()->can('whatsapp.admin_chats')) {
            return [$usage, $quality];
        }

        $ids = collect($usage['top_conversations'] ?? [])->pluck('id')
            ->merge(collect($quality['attention'] ?? [])->pluck('conversation_id'))
            ->filter()->unique()->values();
        $adminIds = $ids->isEmpty() ? [] : WaConversation::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('is_admin_chat', true)
                ->orWhereIn('phone', \App\Models\WaAdminNumber::select('phone')))
            ->pluck('id')->flip()->all();

        $usage['top_conversations'] = array_map(
            fn ($row) => isset($adminIds[$row['id']]) ? array_merge($row, ['id' => null, 'name' => 'محادثة إدارة']) : $row,
            $usage['top_conversations'] ?? []
        );
        $quality['attention'] = array_map(
            fn ($row) => isset($adminIds[$row['conversation_id'] ?? 0]) ? array_merge($row, ['conversation_id' => null, 'question' => 'محادثة إدارة']) : $row,
            $quality['attention'] ?? []
        );

        return [$usage, $quality];
    }

    public function savePrices(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $request->validate([
            'model_prices' => 'required|array',
            'cache_discount' => 'nullable|numeric|min:0|max:1',
        ]);
        WaBotSetting::current()->update($data);

        return back()->with('success', 'تم حفظ الأسعار');
    }

    /**
     * فحص المفتاح وجلب النماذج المتاحة فعلياً لهذا الحساب.
     * أسماء النماذج تتغيّر باستمرار، فنقرأها حيّاً بدل تثبيتها في الكود.
     */
    public function testKey(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $request->validate([
            'provider' => 'required|in:google,anthropic',
            'api_key' => 'nullable|string',
        ]);

        $s = WaBotSetting::current();
        // مفتاح جديد من النموذج، وإلا المحفوظ/البيئة للمزوّد المطلوب
        $key = $data['api_key'] ?: (WaBotSetting::envKeyFor($data['provider']) ?: $s->api_key);

        if (blank($key)) {
            return response()->json(['ok' => false, 'error' => 'لا يوجد مفتاح — أدخل المفتاح أولاً.'], 422);
        }

        try {
            $models = \App\Services\WhatsApp\Llm\LlmFactory::make($data['provider'])->models($key);
        } catch (\RuntimeException $e) {
            // رسالة المزوّد كما بنيناها في المزوّد («Google: …»)، منقّحة ومعها المفتاح المفحوص
            $error = \App\Support\SecretRedactor::clean(mb_substr($e->getMessage(), 0, 300), [$key]);
            \Illuminate\Support\Facades\Log::warning('llm.key_test.rejected', ['reason' => $error]);

            return response()->json(['ok' => false, 'error' => $error], 422);
        } catch (\Throwable $e) {
            // ومنها ConnectionException: نصه يحمل الرابط كاملاً، فلا يُعاد للواجهة
            $ref = \App\Support\ErrorRef::log($e, 'llm.key_test.failed');

            return response()->json(['ok' => false, 'error' => "تعذّر الاتصال بالمزوّد (مهلة أو شبكة) — حاول لاحقاً. رقم المرجع: {$ref}"], 422);
        }

        return response()->json([
            'ok' => true,
            'count' => count($models),
            'models' => $models,
        ]);
    }
}

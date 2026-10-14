<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\TopicRouter;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
                // البيئة أو القاعدة (منطق OR)، ومن أوقف: env | manual | meta_event | graph_error | restore
                'suspended' => $s->sendingSuspended(),
                'suspended_source' => $s->suspendSource(),
                // شريط عام بلا تفاصيل: التفاصيل في بطاقة صحة القناة لمن يملك whatsapp.settings
                'meta_blocked' => data_get($s->wa_channel_health, 'can_send_message') === 'BLOCKED'
                    || data_get($s->wa_credentials_status, 'state') === 'critical',
            ],
            // وضع التحويل (E12): الحالة والأوقات فقط، بلا سبب تقني
            'llm' => \App\Services\WhatsApp\LlmCircuitBreaker::publicStatus(),
            'canReply' => auth()->user()->can('whatsapp.reply'),
            'canAssign' => auth()->user()->can('whatsapp.assign'),
            'canToggleBot' => auth()->user()->can('whatsapp.bot_toggle'),
            'seesAll' => auth()->user()->can('whatsapp.view_all'),
            'canSeeAdminChats' => auth()->user()->can('whatsapp.admin_chats'),
            // ربط المحادثة بعميل (E11.8) يحتاج رؤية العملاء للبحث
            'canLinkClient' => auth()->user()->can('whatsapp.link_client') && auth()->user()->can('clients.view'),
            // مرفقات العملاء (E14): أزرار العرض والتنزيل، والخادم يفرضها بنفسه
            'canMedia' => auth()->user()->can('whatsapp.media'),
            'identityMode' => \App\Services\WhatsApp\IdentityMode::current(),
            'topics' => TopicRouter::TOPICS,
            'attentionReasons' => \App\Services\WhatsApp\ReplyState::REASONS,
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
            'llm' => \App\Services\WhatsApp\LlmCircuitBreaker::publicStatus(),
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
            // محادثة دُمجت في أخرى (E11): رسائلها انتقلت، فلا تظهر
            ->where('status', '!=', 'merged')
            ->when($request->topic, fn ($q, $t) => $q->whereExists(function ($sub) use ($t) {
                $sub->selectRaw(1)->from('wa_conversation_topics')
                    ->whereColumn('wa_conversation_topics.conversation_id', 'wa_conversations.id')
                    ->where('wa_conversation_topics.topic', $t);
            }))
            ->when($filter === 'mine', fn ($q) => $q->where('assigned_to', $me->id))
            ->when($request->search, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('phone', 'like', "%{$s}%")
                      ->orWhere('display_name', 'like', "%{$s}%")
                      ->orWhere('wa_username', 'like', "%{$s}%")
                      ->orWhere('wa_profile_name', 'like', "%{$s}%");
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
                'name' => $c->label(),
                // عميل باسم مستخدم بلا رقم (E11a): المعرّف نفسه لا يُعرض
                'phoneless' => !$c->hasPhone(),
                'username' => $c->wa_username,
                // لقب يضعه العميل بنفسه: غير موثّق
                'profile_name' => $c->wa_profile_name,
                'from_ad' => !empty($c->referral),
                'client' => $c->client?->name,
                'client_code' => $c->client?->code,
                'client_id' => $c->client_id,
                'bot_enabled' => (bool) $c->bot_enabled,
                'paused' => $c->bot_paused_until && $c->bot_paused_until->isFuture(),
                'needs_attention' => (bool) $c->needs_attention,
                'attention_reason' => $c->attention_reason,
                'unread' => (int) $c->unread_count,
                'preview' => $c->last_message_preview,
                'last_at' => $c->last_message_at?->diffForHumans(),
                'window_open' => $c->isWindowOpen(),
                'window_left' => $c->windowMinutesLeft(),
                'is_admin' => (bool) $c->is_admin_chat || ($c->phone !== null && isset($adminPhones[$c->phone])),
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

        // من فتح المحادثة: صف واحد لكل موظف ومحادثة كل 30 دقيقة (الاستطلاع كل 15 ثانية لا يُغرق السجل).
        // بالمعرّف وحده، بلا هاتف ولا اسم
        $viewKey = 'audit:view:'.auth()->id().':'.$conversation->id;
        if (config('audit.extended') && \Illuminate\Support\Facades\Cache::store(config('audit.sampler_store', 'file'))->add($viewKey, 1, 60 * (int) config('audit.conversation_view_minutes', 30))) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaConversationView, 'wa_conversation', $conversation->id);
        }

        $v = $request->validate([
            'before' => 'nullable|integer|min:1|prohibits:after',
            'after' => 'nullable|integer|min:0|prohibits:before',
            'since' => 'nullable|date',
            'limit' => 'nullable|integer|min:1',
        ]);
        $syncedAt = now()->subSeconds(5)->format('Y-m-d H:i:s');   // تداخل مقصود؛ تطبيق التحديثات بالمعرّف idempotent
        $limit = min((int) ($v['limit'] ?? 100), 100);
        $columns = ['id', 'direction', 'source', 'msg_type', 'body', 'status', 'error_message', 'staff_id', 'created_at'];
        // الاقتباس والوسائط (E11): بعد الهجرة 000012 وحدها
        $e11 = \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['reply_to_wamid', 'media_mime', 'media_filename']);
        if ($e11) {
            array_push($columns, 'reply_to_wamid', 'media_mime', 'media_filename');
        }
        // حالة الأرشيف (E14): بعد الهجرة 000015 وحدها
        $e14 = $e11 && \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['media_status', 'media_size', 'media_id', 'media_error']);
        if ($e14) {
            array_push($columns, 'media_status', 'media_size', 'media_id', 'media_error');
        }
        $user = auth()->user();
        $media = fn ($m) => \App\Services\WhatsApp\Media\MediaPresenter::forThread($m, $user);

        $hasMore = false;
        $gap = false;
        $updates = [];

        if (isset($v['after'])) {
            $rows = $conversation->messages()->with('staff:id,name')
                ->where('id', '>', $v['after'])->orderBy('id')->limit(200)->get($columns);
            $gap = $rows->count() >= 200;

            if (!empty($v['since'])) {
                // حالات الصادر، وحالة أرشفة المرفق الوارد (جارٍ الحفظ ← عرض) بلا إعادة تحميل
                $updates = $conversation->messages()
                    ->where(fn ($q) => $e14 ? $q->where('direction', 'out')->orWhereNotNull('media_status') : $q->where('direction', 'out'))
                    ->where('id', '<=', $v['after'])
                    ->where('updated_at', '>=', $v['since'])
                    ->orderByDesc('id')->limit(100)
                    ->get(array_merge(['id', 'status', 'error_message', 'msg_type', 'direction', 'created_at'], $e14 ? ['media_mime', 'media_filename', 'media_status', 'media_size', 'media_id', 'media_error'] : []))
                    ->map(fn ($m) => ['id' => $m->id, 'status' => $m->status, 'error' => $m->error_message] + ($e14 && ($md = $media($m)) ? ['media' => $md] : []))
                    ->all();
            }
        } else {
            $rows = $conversation->messages()->with('staff:id,name')
                ->when(isset($v['before']), fn ($q) => $q->where('id', '<', $v['before']))
                ->orderByDesc('id')->limit($limit + 1)->get($columns);
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }

        // المقتبس من المحادثة نفسها وحدها: مقتطف 80 حرفاً
        $quoted = $e11 && ($ids = $rows->pluck('reply_to_wamid')->filter()->unique()->values()->all())
            ? $conversation->messages()->whereIn('wamid', $ids)->pluck('body', 'wamid')->map(fn ($b) => mb_substr(trim((string) $b), 0, 80))->all()
            : [];

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
            'is_system' => $m->source === 'system' && $m->direction === 'in',
            'reply_to' => $m->reply_to_wamid ? ($quoted[$m->reply_to_wamid] ?? null) : null,
            // حالة المرفق وما يُسمح به (E14). لا مسار ولا رابط ولا معرّف ميتا
            'media' => $e14 ? $media($m) : ($m->media_mime ? ['mime' => $m->media_mime, 'name' => $m->media_filename, 'status' => null] : null),
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
                'name' => $conversation->label(),
                'phone' => $conversation->phone,
                'phoneless' => !$conversation->hasPhone(),
                'username' => $conversation->wa_username,
                'profile_name' => $conversation->wa_profile_name,
                'client' => $conversation->client?->name,
                'client_id' => $conversation->client_id,
                'client_code' => $conversation->client?->code,
                'client_link' => $conversation->client_link,
                'identity_mode' => \App\Services\WhatsApp\IdentityMode::current(),
                'bot_enabled' => (bool) $conversation->bot_enabled,
                'needs_attention' => (bool) $conversation->needs_attention,
                'attention_reason' => $conversation->attention_reason,
                'unread' => (int) $conversation->unread_count,
                'window_open' => $conversation->isWindowOpen(),
                'window_left' => $conversation->windowMinutesLeft(),
                'is_admin' => (bool) $conversation->is_admin_chat || ($conversation->hasPhone() && (bool) \App\Models\WaAdminNumber::forPhone($conversation->phone)),
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

        \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaTopic, 'wa_conversation', $conversation->id, null, ['topic' => $data['topic'], 'attach' => (bool) $data['attach']]);
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

        $from = $conversation->assigned_to;
        $conversation->update(['assigned_to' => $data['user_id'] ?: null]);
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaAssign, 'wa_conversation', $conversation->id, null, ['from' => $from, 'to' => $conversation->assigned_to]);

        if ($data['user_id']) {
            try {
                \App\Services\NotificationService::send(
                    (int) $data['user_id'],
                    '💬 محادثة واتساب مُحوّلة إليك',
                    $conversation->noticeLabel() . ' — حوّلها ' . auth()->user()->name,
                    ['type' => 'whatsapp', 'icon' => '💬', 'action_url' => '/whatsapp/inbox?conv=' . $conversation->id]
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
            // من يستلم أي موضوع: قبل وبعد، في المعاملة نفسها
            $before = DB::table('wa_topic_users')->orderBy('topic')->orderBy('user_id')->get()
                ->groupBy('topic')->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all())->all();
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

            $after = collect($data['routes'])->mapWithKeys(fn ($r) => [$r['topic'] => collect($r['users'])->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all()])->filter()->sortKeys()->all();
            \App\Models\AuditLog::log(\App\Enums\AuditAction::WaRoutingUpdate, 'wa_routing', null, null, ['before' => $before, 'after' => $after]);
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

        $data = $request->validate(['body' => 'required|string|max:4000', 'ack_warnings' => 'sometimes|boolean']);

        // بيانات حساسة في رد الموظف (E15.9): تحذير يؤكده، لا منع. التأكيد يُدقَّق بالفاعل
        $warnings = \App\Services\WhatsApp\Outbound\OutboundPolicy::evaluate($conversation, ['text' => ['body' => $data['body']]], $data['body'], 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json([
                'ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'الرسالة تحوي ما يشبه '.\App\Services\WhatsApp\Outbound\OutboundPolicy::describe($warnings)
                    .'. سياسة واتساب تمنع طلب هذه الأرقام أو مشاركتها. أرسل رغم ذلك؟',
            ], 409);
        }

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', auth()->id());
        if ($warnings && $res['ok']) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaPolicyOverride, 'wa_conversation', $conversation->id, null, ['reasons' => $warnings]);
        }

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    // ==================== ربط المحادثة بعميل (E11.8) ====================

    /** بحث للربط اليدوي: الاسم والكود ورقم مقنّع، بلا رصيد ولا رقم كامل. 10 نتائج على الأكثر */
    public function searchClients(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.link_client') && auth()->user()->can('clients.view'), 403);
        $q = trim($request->validate(['q' => 'required|string|min:2|max:60'])['q']);
        $digits = \App\Services\WhatsApp\PhoneNormalizer::digits($q);

        $clients = \App\Models\Client::query()
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'code', 'phone']);

        return response()->json(['clients' => $clients->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
            'phone_masked' => strlen($d = \App\Services\WhatsApp\PhoneNormalizer::digits($c->phone)) >= 3 ? '•••'.substr($d, -3) : null,
        ])->values()]);
    }

    /**
     * ربط يدوي بتأكيد خادمي أن الموظف رأى الكود (client_code يطابق). يحل محل أي ربط سابق، ويبقى الحاجز
     * المالي على المحادثة بلا رقم. لا ملاحظة للعميل (الملاحظات تصل للبوت)، والسجل في audit_logs.
     */
    public function linkClient(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.link_client'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'client_code' => 'required|string|max:50',
        ]);
        $client = \App\Models\Client::findOrFail($data['client_id']);
        if (!hash_equals((string) $client->code, trim($data['client_code']))) {
            return response()->json(['message' => 'الكود لا يطابق العميل المختار.', 'errors' => ['client_code' => ['الكود لا يطابق العميل المختار.']]], 422);
        }

        $from = $conversation->client_id;
        DB::transaction(function () use ($conversation, $client, $from) {
            $conversation->forceFill([
                'client_id' => $client->id, 'client_link' => 'manual',
                'client_linked_by' => auth()->id(), 'client_linked_at' => now(),
            ])->save();
            \App\Models\AuditLog::log(\App\Enums\AuditAction::WaLinkClient, 'wa_conversation', $conversation->id, $conversation->label(), [
                'client_from' => $from, 'client_to' => $client->id, 'client_code' => $client->code, 'phoneless' => !$conversation->hasPhone(),
            ]);
        });

        return response()->json(['ok' => true, 'client' => ['id' => $client->id, 'name' => $client->name, 'code' => $client->code], 'client_link' => 'manual']);
    }

    /** فك الربط: blocked، فلا يعيد الربط الآلي ربطها مع الرسالة التالية */
    public function unlinkClient(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.link_client'), 403);

        $from = $conversation->client_id;
        DB::transaction(function () use ($conversation, $from) {
            $conversation->forceFill([
                'client_id' => null, 'client_link' => 'blocked',
                'client_linked_by' => auth()->id(), 'client_linked_at' => now(),
            ])->save();
            \App\Models\AuditLog::log(\App\Enums\AuditAction::WaUnlinkClient, 'wa_conversation', $conversation->id, $conversation->label(), [
                'client_from' => $from, 'client_to' => null,
            ]);
        });

        return response()->json(['ok' => true, 'client' => null, 'client_link' => 'blocked']);
    }

    /** زر «طلب الرقم» لمحادثة بلا رقم (E11.6): نص ثابت يبنيه الكود، داخل النافذة ووفق وضع الهوية */
    public function requestContact(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        if (filled($conversation->phone)) {
            return response()->json(['ok' => false, 'code' => 'HAS_PHONE', 'error' => 'رقم العميل معروف — لا حاجة لطلبه.'], 422);
        }

        $res = WhatsAppChannel::sendContactRequest($conversation, self::CONTACT_REQUEST_BODY, 'staff', auth()->id());

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    private const CONTACT_REQUEST_BODY = 'لنكمل خدمتك، شارك رقم هاتفك بالضغط على الزر أدناه.';

    public function toggleBot(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.bot_toggle'), 403);

        $conversation->update([
            'bot_enabled' => !$conversation->bot_enabled,
            'needs_attention' => false,
            'bot_paused_until' => null,
        ] + (\App\Services\WhatsApp\ReplyState::available() ? ['attention_reason' => null] : []));

        $enabled = (bool) $conversation->fresh()->bot_enabled;
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaBotToggle, 'wa_conversation', $conversation->id, null, ['to' => $enabled]);

        return response()->json(['bot_enabled' => $enabled]);
    }

    public function resolve(WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);
        $conversation->update(['needs_attention' => false] + (\App\Services\WhatsApp\ReplyState::available() ? ['attention_reason' => null] : []));
        // «تمّت المعالجة»: ما وصل حتى الآن خُدم، فلا يرد البوت عليه لاحقاً
        \App\Services\WhatsApp\ReplyState::advanceToNewest($conversation);

        return response()->json(['ok' => true]);
    }

    // ==================== الإعدادات ====================

    /** «افحص الآن» في بطاقة صحة القناة: الفحصان معاً، ويُرجع الملخص المحفوظ فقط */
    public function metaCheck()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $health = app(\App\Services\WhatsApp\MetaHealth::class);
        $health->credentials();
        $health->channel();

        return response()->json(['meta' => \App\Services\WhatsApp\MetaHealth::summary(WaBotSetting::current()->fresh())]);
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
        // عبر الكاتب الوحيد: يرفع نسخة الإعدادات ويُدقَّق بالقيم الناتجة
        \App\Services\WhatsApp\Settings\SettingsWriter::apply($data, null, \App\Enums\AuditAction::WaModelPrices,
            ['models' => array_keys($data['model_prices'])], label: 'أسعار النموذج');

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
        } catch (\App\Services\WhatsApp\Llm\LlmException $e) {
            if ($e->httpStatus === null) {
                // لا رد من المزوّد (مهلة أو شبكة): رقم مرجعي بدل التفاصيل
                $ref = \App\Support\ErrorRef::log($e, 'llm.key_test.failed');

                return response()->json(['ok' => false, 'error' => "تعذّر الاتصال بالمزوّد (مهلة أو شبكة) — حاول لاحقاً. رقم المرجع: {$ref}"], 422);
            }
            // رسالة المزوّد مصنّفة ومنقّاة («Gemini HTTP 400: …»)، ومعها المفتاح المفحوص
            $error = \App\Support\SecretRedactor::clean(mb_substr($e->getMessage(), 0, 300), [$key]);
            \Illuminate\Support\Facades\Log::warning('llm.key_test.rejected', ['reason' => $error]);

            return response()->json(['ok' => false, 'error' => $error], 422);
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

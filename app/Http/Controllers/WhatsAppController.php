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
        $page = $this->conversationPage($request);

        // الصندوق الجديد (E16) خلف العلم inbox_v2، ويجرّبه المدير قبل التفعيل بـ ?v2=1. القديم للتراجع أسبوعين
        $v2 = \App\Support\FeatureFlags::on('inbox_v2') || ($request->boolean('v2') && auth()->user()->isAdmin());

        return Inertia::render($v2 ? 'WhatsApp/Inbox' : 'WhatsApp/InboxLegacy', [
            'title' => 'محادثات الواتساب',
            'conversations' => $page['items'],
            'listMeta' => ['has_more' => $page['has_more'], 'next_cursor' => $page['next_cursor']],
            'filters' => $request->only(['filter', 'search', 'topic', 'label']),
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
                // ميتا ترفض الإرسال على مستوى القناة (E18): الوصف والإجراء للمدير، وسطر عام لغيره
                'meta_error' => $this->channelMetaError($s),
            // صحة القناة (E22): الحالة والتسمية والانتهاء وهل تمنع، بلا سبب ولا رمز (التفاصيل في البطاقة لصاحب whatsapp.settings)
            ] + \App\Services\WhatsApp\ChannelHealth::publicStatus($s),
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
            // إرسال الملفات من المنشئ (E32): الأنواع والحدود والعلم؛ الخادم يفحص كل شيء من جديد
            'mediaPolicy' => auth()->user()->can('whatsapp.reply') ? \App\Services\WhatsApp\Outbound\OutboundMediaPolicy::forClient() : null,
            // رسائل الموظف الصوتية (E34): الزر بالعلم ووجود ffmpeg، والحدود للمتصفح؛ الخادم يفحص كل شيء من جديد
            'voicePolicy' => auth()->user()->can('whatsapp.reply') && \App\Support\FeatureFlags::on('staff_voice_notes') ? [
                'enabled' => \App\Services\WhatsApp\MediaConverter::available(),
                'max_seconds' => (int) config('whatsapp.voice.max_seconds'),
                'max_bytes' => (int) config('whatsapp.voice.max_upload_bytes'),
            ] : null,
            // متابعة الصندوق (E17): الإغلاق والتأجيل والتثبيت و«غير مقروء»
            'followup' => \App\Services\WhatsApp\InboxFollowup::enabled(),
            // بطاقات أعلى الصندوق (E17.6): أعداد فقط، وتتحدّث من /api/whatsapp/inbox-summary
            'summary' => \App\Services\WhatsApp\InboxFollowup::enabled() ? \App\Services\WhatsApp\InboxSummary::for(auth()->user()) : null,
            'canReprocess' => auth()->user()->can('whatsapp.settings') && \App\Support\FeatureFlags::on('reprocess_button'),
            // الردود الجاهزة في المنشئ (E17.7)
            'quickReplies' => auth()->user()->can('whatsapp.reply') && \App\Support\FeatureFlags::on('quick_replies'),
            'identityMode' => \App\Services\WhatsApp\IdentityMode::current(),
            // E29: التصنيفات والملاحظة المثبتة والترجمة عند الطلب. الأعلام للواجهة، والخادم يفحص كل شيء مجدداً
            'labels' => \App\Services\WhatsApp\LabelService::enabled() ? \App\Services\WhatsApp\LabelService::activeList() : [],
            'canManageLabels' => auth()->user()->can('whatsapp.settings'),
            'flags' => [
                'wa_labels' => \App\Services\WhatsApp\LabelService::enabled(),
                'thread_pins' => \App\Services\WhatsApp\ThreadPins::enabled(),
                'staff_translation' => \App\Services\WhatsApp\MessageTranslator::enabled(),
            ],
            // E33: الاقتباس والتفاعل والمعاينة والمشاركة، وقائمة تفاعل الموظف، والمواقع والبطاقات المعتمدة لمنتقيي المنشئ (الأسماء وحدها، والخادم يعيد الفحص)
            'richFeatures' => \App\Services\WhatsApp\RichThread::features(),
            'staffReactions' => (array) config('whatsapp.staff_reactions', []),
            'shareItems' => \App\Support\FeatureFlags::on('wa_share_cards') && auth()->user()->can('whatsapp.reply')
                ? \App\Http\Controllers\WhatsAppSharedItemsController::pickerItems() : null,
            'translateTargets' => \App\Services\WhatsApp\MessageTranslator::enabled() ? \App\Services\WhatsApp\MessageTranslator::REPLY_TARGETS : [],
            // البحث في الرسائل وفلاتر الخيط (E30: المدراء أولاً ثم الجميع)، والتصدير بصلاحيته وعلمه. الخادم يعيد الفحص
            'messageSearch' => \App\Services\WhatsApp\Search\MessageSearch::enabledFor(auth()->user()),
            'canExport' => auth()->user()->can('whatsapp.export') && \App\Support\FeatureFlags::on('conversation_export'),
            // الحظر الرسمي (E31): الزر والفلتر لمن يملك whatsapp.block والعلم مفعّل، والخادم يفرضهما بنفسه
            'canBlock' => self::canBlock(),
            // الصندوق اللحظي (E28): الاشتراك في أحداث wa.changed على قناة المستخدم، والاستطلاع احتياطي
            'realtime' => ['inbox' => \App\Support\FeatureFlags::on('inbox_realtime')],
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

    private static function canBlock(): bool
    {
        return auth()->user()->can('whatsapp.block') && \App\Support\FeatureFlags::on('wa_block') && \App\Services\WhatsApp\BlockService::available();
    }

    /** آخر خطأ قناة من فئة auth أو integrity خلال 24 ساعة، مع meta_error_v2 وحده */
    private function channelMetaError(WaBotSetting $s): ?array
    {
        $last = (array) ($s->wa_last_error ?? []);
        if (!\App\Services\WhatsApp\MetaErrorCatalog::v2() || !in_array($last['category'] ?? null, ['auth', 'integrity'], true)
            || !rescue(fn () => now()->diffInHours(\Illuminate\Support\Carbon::parse($last['at']), true) < 24, false, false)) {
            return null;
        }

        return auth()->user()->can('whatsapp.settings')
            ? ['message' => $last['message'] ?? null, 'action' => $last['action'] ?? null, 'code' => $last['error_code'] ?? null]
            : ['generic' => true];
    }

    /** بطاقات أعلى الصندوق (E17.6): أعداد محصورة بالنطاق ومخزّنة 60 ثانية، بلا أسماء ولا أرقام ولا نصوص */
    public function inboxSummary()
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        return response()->json(\App\Services\WhatsApp\InboxSummary::for(auth()->user()));
    }

    /** JSON — لتحديث القائمة دورياً */
    public function conversations(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.view'), 403);

        $page = $this->conversationPage($request);

        return response()->json([
            'conversations' => $page['items'],
            // «تحميل المزيد» (E16): cursor بصيغة unix_ts:id من آخر صف، وبلا cursor الصفحة الأولى كما كانت
            'has_more' => $page['has_more'],
            'next_cursor' => $page['next_cursor'],
            'unread_total' => (int) WaConversation::query()->visibleTo(auth()->user())->sum('unread_count'),
            'llm' => \App\Services\WhatsApp\LlmCircuitBreaker::publicStatus(),
        ]);
    }

    /** حجم صفحة القائمة؛ يُطلب صف زائد لمعرفة has_more */
    private const LIST_PAGE = 50;

    /**
     * صفحة من القائمة بمؤشر keyset على (last_message_at, id) تنازلياً (E16.6). السياج (visibleTo) يُطبَّق قبل الترقيم،
     * فلا يرى الموظف في أي صفحة ما خارج نطاقه. cursor غير صالح = 422.
     *
     * @return array{items: array, has_more: bool, next_cursor: ?string}
     */
    private function conversationPage(Request $request): array
    {
        $cursor = $request->validate(['cursor' => ['nullable', 'string', 'regex:/^\d{1,10}:\d{1,12}$/']])['cursor'] ?? null;

        // المثبّتة (E17، 5 على الأكثر) أعلى الصفحة الأولى وخارج الترقيم، فيبقى المؤشر على (last_message_at, id) صحيحاً
        $pinned = \App\Services\WhatsApp\InboxFollowup::enabled() ? \App\Services\WhatsApp\InboxFollowup::pinnedIds(auth()->user()) : [];
        $apart = $pinned && $request->get('filter', 'all') !== 'pinned';
        $top = $apart && !$cursor
            ? $this->conversationQuery($request)->whereIn('wa_conversations.id', $pinned)->orderByDesc('last_message_at')->orderByDesc('wa_conversations.id')->get()
            : collect();

        $rows = $this->conversationQuery($request)
            ->when($apart, fn ($q) => $q->whereNotIn('wa_conversations.id', $pinned))
            ->when($cursor, function ($q, $c) {
                [$ts, $id] = array_map('intval', explode(':', $c));
                if ($ts === 0) {
                    // آخر صف بلا last_message_at: ما بعده بلا وقت وبمعرّف أصغر
                    return $q->whereNull('last_message_at')->where('wa_conversations.id', '<', $id);
                }
                $at = \Illuminate\Support\Carbon::createFromTimestamp($ts)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

                return $q->where(fn ($w) => $w->where('last_message_at', '<', $at)
                    ->orWhere(fn ($e) => $e->where('last_message_at', $at)->where('wa_conversations.id', '<', $id))
                    ->orWhereNull('last_message_at'));
            })
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(self::LIST_PAGE + 1)
            ->get();

        $more = $rows->count() > self::LIST_PAGE;
        $rows = $rows->take(self::LIST_PAGE);
        $last = $rows->last();

        return [
            'items' => $this->presentConversations($top->concat($rows), $pinned),
            'has_more' => $more,
            'next_cursor' => $more && $last ? ((int) $last->last_message_at?->timestamp).':'.$last->id : null,
        ];
    }

    private function conversationQuery(Request $request)
    {
        $filter = $request->get('filter', 'all');

        $me = auth()->user();

        return WaConversation::query()
            ->select('wa_conversations.*')
            // نوع آخر رسالة لأيقونة القائمة (E16): استعلام فرعي على الفهرس [conversation_id, id]
            ->selectSub(fn ($q) => $q->from('wa_messages')->select('msg_type')
                ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')->orderByDesc('id')->limit(1), 'last_type')
            ->with(array_merge(['client:id,name,code', 'assignee:id,name'], \App\Services\WhatsApp\InboxFollowup::enabled() ? ['markedUnreadBy:id,name'] : []))
            // السياج: محادثات الإدارة، ثم الاختصاص والتوكيل (الشروط أدناه تضيّق ولا توسّع)
            ->visibleTo($me)
            // محادثة دُمجت في أخرى (E11): رسائلها انتقلت، فلا تظهر
            ->where('status', '!=', 'merged')
            ->when($request->topic, fn ($q, $t) => $q->whereExists(function ($sub) use ($t) {
                $sub->selectRaw(1)->from('wa_conversation_topics')
                    ->whereColumn('wa_conversation_topics.conversation_id', 'wa_conversations.id')
                    ->where('wa_conversation_topics.topic', $t);
            }))
            // تصنيف (E29): شرط AND بعد السياج، يضيّق ولا يوسّع
            ->when(\App\Services\WhatsApp\LabelService::enabled() && $request->integer('label') > 0, fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw(1)
                ->from('wa_conversation_labels')->whereColumn('wa_conversation_labels.conversation_id', 'wa_conversations.id')
                ->where('wa_conversation_labels.label_id', $request->integer('label'))))
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
            // المحظورة (E31) لا تنتظر أحداً، ولها تبويبها لمن يملك whatsapp.block
            ->when($filter === 'attention', fn ($q) => \App\Services\WhatsApp\InboxSummary::notBlocked($q->where('needs_attention', true)))
            ->when($filter === 'blocked', fn ($q) => self::canBlock() ? $q->whereNotNull('wa_conversations.blocked_at') : $q->whereRaw('1 = 0'))
            ->when($filter === 'bot', fn ($q) => $q->where('bot_enabled', true))
            ->when($filter === 'human', fn ($q) => $q->where('bot_enabled', false))
            // الإغلاق والتأجيل والتثبيت (E17): «الكل» لا يُظهر المغلقة والمؤجلة إلا ما يحتاج تدخّلاً
            ->when(\App\Services\WhatsApp\InboxFollowup::enabled(), fn ($q) => \App\Services\WhatsApp\InboxFollowup::applyFilter($q, $filter, $me));
    }

    private function presentConversations($rows, array $pinned = []): array
    {
        $followup = \App\Services\WhatsApp\InboxFollowup::enabled();
        // محادثات أرقام الإدارة تُعلَّم في الصندوق — يخدمها المساعد الإداري لا بوت العملاء
        $adminPhones = rescue(fn () => \App\Models\WaAdminNumber::where('is_active', true)->pluck('phone')->flip()->all(), [], false);
        // التصنيفات وشارات النظام (E29): استعلام واحد لكل منهما للصفحة كلها، والشارات المالية بصلاحياتها
        $labelsOn = \App\Services\WhatsApp\LabelService::enabled();
        $labels = $labelsOn ? \App\Services\WhatsApp\LabelService::chipsFor($rows->pluck('id')->all()) : [];
        $badges = $labelsOn ? \App\Services\WhatsApp\ConversationBadges::forPage(collect($rows), auth()->user()) : [];

        return $rows->map(fn ($c) => [
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
            // للترتيب والدمج في الواجهة (E16): الاستطلاع يعيد الصفحة الأولى فقط
            'sort_ts' => (int) $c->last_message_at?->timestamp,
            'last_type' => $c->last_type,
            'window_open' => $c->isWindowOpen(),
            'window_left' => $c->windowMinutesLeft(),
            'is_admin' => (bool) $c->is_admin_chat || ($c->phone !== null && isset($adminPhones[$c->phone])),
            'blocked' => $c->isBlocked(),
            'assignee' => $c->assignee?->name,
            'assigned_to' => $c->assigned_to,
            'topics' => TopicRouter::topicsOf($c),
        ] + ($followup ? \App\Services\WhatsApp\InboxFollowup::state($c, in_array($c->id, $pinned, true)) : [])
          + ($labelsOn ? ['labels' => $labels[$c->id] ?? [], 'badges' => $badges[$c->id] ?? []] : []))->all();
    }

    /**
     * خيط المحادثة بمؤشر keyset على الفهرس [conversation_id, id]:
     *   بلا مؤشر: أحدث 100 · before=<id>: الأقدم منه · after=<id>&since=<وقت>: الجديد وتحديثات حالة الصادر
     *   (حتى limit، 200 افتراضياً، مع has_more_after) · around=<id> (E30): 50 قبلها والرسالة و49 بعدها للقفز إليها،
     *   مع has_more_before وhas_more_after وdetached (أحدث منها لم يُحمَّل) وanchor_id وlatest_id.
     * الفلاتر (E30): direction وsource وmsg_type وdate_from وdate_to على كل الأوضاع، والرد يحمل filtered.
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
            'around' => 'nullable|integer|min:1|prohibits:before,after',
            'since' => 'nullable|date',
            'limit' => 'nullable|integer|min:1',
        ] + \App\Services\WhatsApp\Search\MessageFilters::rules());
        $filtered = \App\Services\WhatsApp\Search\MessageFilters::present($v) !== [];
        // رسائل المحادثة بعد الفلاتر (كل الأوضاع)
        $scoped = function () use ($conversation, $v) {
            $q = $conversation->messages()->with('staff:id,name');
            \App\Services\WhatsApp\Search\MessageFilters::apply($q, $v);

            return $q;
        };
        $syncedAt = now()->subSeconds(5)->format('Y-m-d H:i:s');   // تداخل مقصود؛ تطبيق التحديثات بالمعرّف idempotent
        $limit = min((int) ($v['limit'] ?? 100), 100);
        // wamid وpayload للحقول الغنية (E33) في الخادم وحده، ولا يخرجان للواجهة
        $columns = ['id', 'direction', 'source', 'msg_type', 'body', 'status', 'error_message', 'staff_id', 'created_at', 'wamid', 'payload'];
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
        // حُذف من خوادم ميتا بعد القراءة (E32.12): بعد الهجرة 000032 وحدها
        $remoteDeleted = $e14 && \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'media_remote_deleted_at');
        if ($remoteDeleted) {
            $columns[] = 'media_remote_deleted_at';
        }
        $user = auth()->user();
        $media = fn ($m) => \App\Services\WhatsApp\Media\MediaPresenter::forThread($m, $user);
        // سبب الفشل وإجراؤه (E18، مع meta_error_v2): الرمز والفئة والإجراء للجميع، وfbtrace_id لصاحب whatsapp.settings
        $errorCols = [];
        if ($e11 && \App\Services\WhatsApp\MetaErrorCatalog::v2()) {
            $errorCols = ['meta_code', 'fbtrace_id', 'error_code'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'error_category')) {
                $errorCols[] = 'error_category';
            }
            array_push($columns, ...$errorCols);
        }
        $canSupport = $user->can('whatsapp.settings');
        // دفتر الإرسال (E19): عدد المحاولات وزر «انسخ إلى خانة الكتابة» لما فشل (مفتاحه محرَّر) أو بقي غير مؤكد بعد المنظّف
        $ledger = \App\Support\FeatureFlags::on('send_ledger') && \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'attempts');
        if ($ledger) {
            array_push($columns, 'attempts', 'payload');
        }
        $canResend = $ledger && $user->can('whatsapp.reply') && $conversation->isWindowOpen();
        // الترجمة عند الطلب (E29): المخزّنة وقابلية الترجمة ووسم الصادر المترجم، من الحمولة في الخادم وحده
        $translate = \App\Services\WhatsApp\MessageTranslator::enabled();
        if ($translate && !in_array('payload', $columns, true)) {
            $columns[] = 'payload';
        }
        $ledgerFields = function ($m) use ($ledger, $canResend): array {
            if (!$ledger || $m->direction !== 'out') {
                return [];
            }
            // الملف لا يُنسخ إلى خانة الكتابة (E32): يُرفق من جديد
            $resend = $canResend && !in_array($m->msg_type, \App\Services\WhatsApp\Outbound\OutboundMessage::MEDIA_TYPES, true)
                && (($m->status === 'failed' && data_get($m->payload, '_released_key'))
                || ($m->status === 'unknown' && data_get($m->payload, '_reaped')));

            return ['attempts' => (int) $m->attempts, 'can_resend' => (bool) $resend];
        };
        // حالات التسليم (E21، مع delivery_status_v2): وقت ميتا للحالة، و«فشل على أحد الأجهزة»، ورقم ميتا للفاشلة.
        // تُحسب من الأعمدة وpayload المحمّل، ولا يُعاد payload ولا details للواجهة
        $ticks = \App\Services\WhatsApp\StatusApplier::enabled();
        if ($ticks) {
            array_push($columns, 'status_at', 'meta_code', 'payload');
        }
        $delivery = function ($m) use ($ticks): array {
            if (!$ticks || $m->direction !== 'out') {
                return [];
            }

            return [
                // بتوقيت وقت الرسالة نفسه (at) في الفقاعة، فلا يبدو أن القراءة سبقت الإرسال أو تأخرت عنه
                'status_at' => $m->status_at?->format('H:i'),
                'multi_fail' => in_array($m->status, \App\Services\WhatsApp\DeliveryStatus::POSITIVE, true)
                    && collect((array) data_get($m->payload, 'statusHistory', []))->contains(fn ($h) => (data_get($h, 's') ?? data_get($h, 'status')) === 'failed'),
            ] + ($m->status === 'failed' && $m->meta_code !== null ? ['meta_code' => (int) $m->meta_code] : []);
        };
        // رسالة الموظف الصوتية (E34): المدة والتفريغ للزملاء من الحمولة في الخادم (لا حمولة خام)، والملف من مسار E14
        $voiceOn = \App\Support\FeatureFlags::on('staff_voice_notes');
        if ($voiceOn) {
            $columns[] = 'payload';
        }
        $voice = fn ($m): array => $voiceOn ? \App\Services\WhatsApp\Outbound\VoiceNote::threadFields($m) : [];
        $columns = array_values(array_unique($columns));
        $failure = function ($m) use ($errorCols, $canSupport): array {
            if ($errorCols === [] || $m->direction !== 'out' || !in_array($m->status, ['failed', 'unknown', 'blocked'], true)) {
                return [];
            }
            $meaning = \App\Services\WhatsApp\MetaErrorCatalog::forStored($m->meta_code, $m->error_code, $m->error_category ?? null);

            return array_filter([
                'error_code' => $m->error_code,
                'category' => $meaning['category'] ?? null,
                'action' => $meaning['action'] ?? null,
                'fbtrace_id' => $canSupport ? $m->fbtrace_id : null,
            ], fn ($v) => $v !== null);
        };

        $hasMore = false;
        $hasMoreAfter = null;
        $gap = false;
        $updates = [];

        if (isset($v['after'])) {
            // الاستطلاع حتى 200، ونزول الوضع المنفصل (E30) بصفحات أصغر حتى has_more_after=false
            $afterLimit = min((int) ($v['limit'] ?? 200), 200);
            $rows = $scoped()->where('id', '>', $v['after'])->orderBy('id')->limit($afterLimit + 1)->get($columns);
            $hasMoreAfter = $rows->count() > $afterLimit;
            $rows = $rows->take($afterLimit)->values();
            $gap = $hasMoreAfter;

            if (!empty($v['since'])) {
                // حالات الصادر، وحالة أرشفة المرفق الوارد (جارٍ الحفظ ← عرض) بلا إعادة تحميل
                $updates = $conversation->messages()
                    ->where(fn ($q) => $e14 ? $q->where('direction', 'out')->orWhereNotNull('media_status') : $q->where('direction', 'out'))
                    ->where('id', '<=', $v['after'])
                    ->where('updated_at', '>=', $v['since'])
                    ->orderByDesc('id')->limit(100)
                    ->get(array_values(array_unique(array_merge(['id', 'status', 'error_message', 'msg_type', 'direction', 'created_at'], $e14 ? ['media_mime', 'media_filename', 'media_status', 'media_size', 'media_id', 'media_error'] : [], $remoteDeleted ? ['media_remote_deleted_at'] : [], $errorCols, $ledger ? ['attempts', 'payload'] : [], $ticks ? ['status_at', 'meta_code', 'payload'] : [], $voiceOn ? ['source', 'body', 'payload'] : []))))
                    ->map(fn ($m) => ['id' => $m->id, 'status' => $m->status, 'error' => $m->error_message] + $failure($m) + $ledgerFields($m) + $delivery($m) + ($e14 && ($md = $media($m)) ? ['media' => $md] : [])
                        // التفريغ يصل بعد الإرسال: يُحدَّث النص وحقل الصوت في مكانهما (E34)
                        + (($vf = $voice($m)) ? $vf + ['body' => $m->body] : []))
                    ->all();
            }
        } elseif (isset($v['around'])) {
            // القفز إلى رسالة (E30): من المحادثة نفسها وإلا 404. الرسالة نفسها تظهر ولو لم تطابق الفلتر
            abort_unless($conversation->messages()->whereKey($v['around'])->exists(), 404);
            $older = $scoped()->where('id', '<', $v['around'])->orderByDesc('id')->limit(51)->get($columns);
            $newer = $scoped()->where('id', '>', $v['around'])->orderBy('id')->limit(50)->get($columns);
            $hasMore = $older->count() > 50;
            $hasMoreAfter = $newer->count() > 49;
            $rows = $older->take(50)->reverse()
                ->concat($conversation->messages()->with('staff:id,name')->whereKey($v['around'])->get($columns))
                ->concat($newer->take(49))->values();
        } else {
            $rows = $scoped()
                ->when(isset($v['before']), fn ($q) => $q->where('id', '<', $v['before']))
                ->orderByDesc('id')->limit($limit + 1)->get($columns);
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }

        // المقتبس من المحادثة نفسها وحدها: مقتطف 80 حرفاً
        $quoted = $e11 && ($ids = $rows->pluck('reply_to_wamid')->filter()->unique()->values()->all())
            ? $conversation->messages()->whereIn('wamid', $ids)->pluck('body', 'wamid')->map(fn ($b) => mb_substr(trim((string) $b), 0, 80))->all()
            : [];
        // E33: الاقتباس بمعرّفنا، والتفاعلات على أهدافها (صف التفاعل الذي هدفه في الصفحة لا يُعاد فقاعة)، والتصحيح والموقع والبطاقات
        $rich = \App\Services\WhatsApp\RichThread::prepare($conversation, $rows, $user);
        $rows = $rows->filter(fn ($m) => $rich->keep($m))->values();
        // E36: ما عُرض من خيارات وما اختاره العميل ورد نموذج التسعير، بلا معرّفات
        $interactive = \App\Services\WhatsApp\InteractiveThread::prepare($conversation, $rows, $user);

        // staff_id كان يُحفظ ولا يُرسل للواجهة، فلم يكن يظهر من أرسل الرسالة
        $msgs = $rows->map(fn ($m) => [
            'id' => $m->id,
            'direction' => $m->direction,
            'source' => $m->source,
            'type' => $m->msg_type,
            'body' => $interactive->body($m, $rich->body($m)),
            'status' => $m->status,
            'error' => $m->error_message,
            'sender' => $m->source === 'staff' ? ($m->staff?->name ?: 'موظف') : null,
            'at' => $m->created_at?->format('Y-m-d H:i'),
            'is_system' => $m->source === 'system' && $m->direction === 'in',
            'reply_to' => $m->reply_to_wamid ? ($quoted[$m->reply_to_wamid] ?? null) : null,
            // حالة المرفق وما يُسمح به (E14). لا مسار ولا رابط ولا معرّف ميتا
            'media' => $e14 ? $media($m) : ($m->media_mime ? ['mime' => $m->media_mime, 'name' => $m->media_filename, 'status' => null] : null),
        ] + $failure($m) + $ledgerFields($m) + $delivery($m) + ($translate ? \App\Services\WhatsApp\MessageTranslator::threadFields($m) : []) + $voice($m) + $rich->fields($m) + $interactive->fields($m))->values();

        $first = !isset($v['before']) && !isset($v['after']);

        $around = isset($v['around']);

        return response()->json(array_filter([
            'messages' => $msgs,
            'has_more' => $hasMore,
            // E30: النافذة حول رسالة، والنزول منها، وشريط «عرض مفلتر»
            'has_more_before' => $around ? $hasMore : null,
            'has_more_after' => $hasMoreAfter,
            'detached' => $around ? $hasMoreAfter : null,
            'anchor_id' => $around ? (int) $v['around'] : null,
            'latest_id' => $around ? (int) $conversation->messages()->max('id') : null,
            'filtered' => $filtered,
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
                // الحظر الرسمي (E31): رقم موظف لا يُحظر، والشريط الأحمر بمن حظر ومتى ولماذا
                'is_staff' => (bool) $conversation->user_id,
                'blocked' => $conversation->isBlocked(),
                'block' => $conversation->isBlocked() ? self::blockInfo($conversation) : null,
                // مصدر المحادثة (E38): «📷 من رمز» أو «📣 من إعلان» لمن يرى المحادثة أصلاً
                'entry' => \App\Services\WhatsApp\EntryRecorder::badge($conversation),
                // E33: الاقتباس والتفاعل والمعاينة والمشاركة
                'features' => \App\Services\WhatsApp\RichThread::features(),
            ] + (\App\Services\WhatsApp\LabelService::enabled() ? ['labels' => \App\Services\WhatsApp\LabelService::chipsFor([$conversation->id])[$conversation->id] ?? []] : [])
              + (\App\Services\WhatsApp\ReadReceipts::available() ? [
                // الشفافية (E35): ما أُبلغ العميل بقراءته (👁 على الوارد حتى هذا المعرّف)، والوضع، وتنبيه الموكَّل في on_open
                'read_receipt_upto_id' => (int) $conversation->read_receipt_upto_id ?: null,
                'receipts_mode' => \App\Services\WhatsApp\ReadReceipts::mode(),
                'receipts_open_notice' => \App\Services\WhatsApp\ReadReceipts::openNotice($conversation, $user),
            ] : []) + (\App\Services\WhatsApp\InboxFollowup::enabled() ? \App\Services\WhatsApp\InboxFollowup::state(
                $conversation,
                \App\Models\WaConversationPin::where('user_id', auth()->id())->where('conversation_id', $conversation->id)->exists(),
            ) : [])
              // زر «إعادة معالجة» للمدير وللعالقة وحدها (E17.5)
              + (auth()->user()->can('whatsapp.settings') && \App\Support\FeatureFlags::on('reprocess_button')
                ? ['stuck' => in_array($conversation->id, \App\Services\WhatsApp\StuckReplies::cachedIds(), true)] : []),
            'notes' => $first ? $conversation->notes()->orderByDesc('id')->limit(10)->pluck('note') : null,
        ], fn ($value) => $value !== null));
    }

    /** شريط المحادثة المحظورة: التاريخ ومن حظر والسبب (نص التفاصيل لمن يملك whatsapp.block وحده) */
    private static function blockInfo(WaConversation $conversation): ?array
    {
        $b = \App\Models\WaBlock::active()->with(['blocker:id,name', 'adminNumber:id,name'])
            ->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        if (!$b) {
            return ['since' => $conversation->blocked_at?->toIso8601String()];
        }

        return [
            'since' => $b->blocked_at?->toIso8601String(),
            'by' => $b->blocker?->name ?: ($b->adminNumber ? 'رقم الإدارة «'.$b->adminNumber->name.'»' : null),
            'reason_label' => \App\Services\WhatsApp\BlockService::reasonLabel($b->reason_code),
            'reason' => auth()->user()->can('whatsapp.block') ? $b->reason : null,
            'meta_state' => $b->meta_state,
        ];
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
            // البث اللحظي (E28): من كان يراها قبل الإزالة يُبلَّغ أيضاً، فتختفي من قائمة مختص الوسم المُزال
            $before = app(\App\Services\WhatsApp\Realtime\InboxBroadcaster::class)->enabled()
                ? \App\Services\WhatsApp\Realtime\InboxAudience::for($conversation) : [];
            TopicRouter::untag($conversation, $data['topic']);
            $conversation->touchRealtime('meta', $before);
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

        // الفتح الصريح يمحو «غير مقروء» الداخلي (E17) أيضاً
        $conversation->forceFill(['unread_count' => 0] + (\App\Services\WhatsApp\InboxFollowup::available() ? ['marked_unread_at' => null, 'marked_unread_by' => null] : []))->save();

        // الوضع on_open (E35): إيصال قراءة لميتا بعد الاستجابة، للموكَّل أو لغير الموكلة، ومرة في الدقيقة. الاستطلاع لا يمر من هنا
        \App\Services\WhatsApp\ReadReceipts::afterOpen($conversation, auth()->user());

        return response()->json(['ok' => true]);
    }

    /** ردّ الموظف — يوقف البوت مؤقتاً تلقائياً داخل بوّابة الإرسال */
    public function send(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.reply'), 403);

        $data = $request->validate(['body' => 'required|string|max:4000', 'ack_warnings' => 'sometimes|boolean',
            // E33: رسالة بعينها من المحادثة نفسها (معرّفنا لا wamid)، ومعاينة الرابط بخانة صريحة
            'reply_to_id' => 'nullable|integer|min:1', 'preview_url' => 'sometimes|boolean']);

        $opts = [];
        if (!empty($data['reply_to_id'])) {
            if (!\App\Support\FeatureFlags::on('wa_reply_quote')) {
                return response()->json(['ok' => false, 'code' => 'FEATURE_OFF', 'error' => 'الرد المقتبس غير مفعّل.'], 422);
            }
            $target = $conversation->messages()->whereKey($data['reply_to_id'])->first();
            if (!WhatsAppChannel::canQuote($conversation, $target)) {
                return response()->json(['ok' => false, 'code' => 'BAD_REPLY_TARGET', 'error' => 'لا يمكن الاقتباس من هذه الرسالة.'], 422);
            }
            $opts['reply_to'] = $target;
        }
        $previewRefused = null;
        if ($request->boolean('preview_url') && \App\Support\FeatureFlags::on('wa_link_preview')) {
            // القرار النهائي في dispatch؛ هنا السبب للواجهة إن رُفضت المعاينة (تُرسل الرسالة بلا معاينة)
            $check = \App\Services\WhatsApp\LinkPreviewPolicy::check($data['body']);
            $opts['preview_url'] = $check['allowed'];
            $previewRefused = $check['allowed'] ? null : $check['reason'];
        }

        // متغير رد جاهز لم يُملأ (E17.7): لا يصل {{…}} إلى عميل أبداً، ولو تجاوز أحد الواجهة. يعمل بلا علم
        if ($unfilled = \App\Services\WhatsApp\QuickReplyText::unfilled($data['body'])) {
            return response()->json(['ok' => false, 'code' => 'UNFILLED_VARIABLE',
                'error' => 'في الرسالة متغير لم يُملأ: {{'.$unfilled[0].'}} — أكمله قبل الإرسال'], 422);
        }

        // بيانات حساسة في رد الموظف (E15.9): تحذير يؤكده، لا منع. التأكيد يُدقَّق بالفاعل
        $warnings = \App\Services\WhatsApp\Outbound\OutboundPolicy::evaluate($conversation, ['text' => ['body' => $data['body']]], $data['body'], 'staff')->warnings;
        if ($warnings && !$request->boolean('ack_warnings')) {
            return response()->json([
                'ok' => false, 'code' => 'POLICY_WARN', 'reasons' => $warnings,
                'error' => 'الرسالة تحوي ما يشبه '.\App\Services\WhatsApp\Outbound\OutboundPolicy::describe($warnings)
                    .'. سياسة واتساب تمنع طلب هذه الأرقام أو مشاركتها. أرسل رغم ذلك؟',
            ], 409);
        }

        $res = WhatsAppChannel::sendText($conversation, $data['body'], 'staff', auth()->id(), sendKey: self::staffSendKey($request), opts: $opts);
        if ($warnings && $res['ok'] && empty($res['replayed'])) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaPolicyOverride, 'wa_conversation', $conversation->id, null, ['reasons' => $warnings]);
        }

        return self::sendResponse($res + ($previewRefused !== null ? ['preview_refused_reason' => $previewRefused] : []));
    }

    /**
     * رد الإرسال. مع connection_resilience (E28): النتيجة المجهولة (مهلة أو انقطاع بعد بدء الإرسال، والمسار القديم أيضاً)
     * 202 OUTCOME_UNKNOWN «قد تكون أُرسلت»، والرفض الصريح 422 كما هو، وتعارض المفتاح 409 كما في E19.
     */
    private static function sendResponse(array $res): \Illuminate\Http\JsonResponse
    {
        if (empty($res['conflict']) && \App\Services\WhatsApp\SendOutcome::unknown($res) && \App\Services\WhatsApp\SendOutcome::enabled()) {
            return response()->json(\App\Services\WhatsApp\SendOutcome::body($res), 202);
        }

        return response()->json($res, self::sendStatus($res));
    }

    /**
     * مفتاح منع التكرار لرسالة الموظف (E19): Idempotency-Key (UUID) من المنشئ، ثابت لإعادة المسودة نفسها.
     * ملف JS قديم بلا ترويسة: مفتاح جديد ويُعدّ (يظهر في whatsapp:doctor).
     */
    public static function staffSendKey(Request $request): string
    {
        $key = \App\Services\WhatsApp\SendKey::staff((string) $request->header('Idempotency-Key', ''));
        if ($key === null) {
            $day = 'wa:idem:missing:'.now()->format('Ymd');
            \Illuminate\Support\Facades\Cache::add($day, 0, now()->addDays(10));
            \Illuminate\Support\Facades\Cache::increment($day);
            $key = \App\Services\WhatsApp\SendKey::staff((string) \Illuminate\Support\Str::uuid());
        }

        return $key;
    }

    /**
     * أُرسلت أو أُعيدت (replayed) 200 · غير مؤكدة من هذا الإرسال 202 · المفتاح نفسه قيد الإرسال أو غير مؤكد 409، ولنص آخر 422 ·
     * مهلة المسار القديم 503 · سائر الفشل 422 بالفئة والإجراء. عام لمسار الرد المترجم (E29)
     */
    public static function sendStatus(array $res): int
    {
        return match (true) {
            (bool) $res['ok'] => 200,
            ($res['code'] ?? null) === 'IDEMPOTENCY_MISMATCH' => 422,
            !empty($res['conflict']) => 409,
            !empty($res['unknown']) && isset($res['row_id']) => 202,
            ($res['code'] ?? null) === 'HTTP_TIMEOUT' => 503,
            default => 422,
        };
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

        $res = WhatsAppChannel::sendContactRequest($conversation, self::CONTACT_REQUEST_BODY, 'staff', auth()->id(), self::staffSendKey(request()));

        return self::sendResponse($res);
    }

    private const CONTACT_REQUEST_BODY = 'لنكمل خدمتك، شارك رقم هاتفك بالضغط على الزر أدناه.';

    /**
     * تشغيل البوت في المحادثة أو إيقافه بقيمة صريحة (E21): enabled يُكتب كما هو، فالنقرتان بالقيمة نفسها تعطيان النتيجة
     * نفسها. تبويب قديم بلا enabled يقلب القيمة (سطر wa.toggle_bot.legacy) لإصدار واحد، ثم يصير enabled إلزامياً.
     */
    public function toggleBot(Request $request, WaConversation $conversation)
    {
        abort_unless(auth()->user()->can('whatsapp.bot_toggle'), 403);
        $data = $request->validate(['enabled' => 'sometimes|boolean']);

        if (array_key_exists('enabled', $data)) {
            $enabled = (bool) $data['enabled'];
        } else {
            $enabled = !$conversation->bot_enabled;
            \App\Services\WhatsApp\WaLog::info('wa.toggle_bot.legacy', ['conv_id' => $conversation->id]);
        }

        $conversation->update([
            'bot_enabled' => $enabled,
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

    /**
     * «افحص الآن» في بطاقة صحة القناة: الفحصان معاً، ويُرجع الملخص المحفوظ فقط. فحص بدأه إنسان (E22): يمسح
     * TOKEN_INVALID إن عاد الرمز يعمل، ولا يرفع BLOCKED (الرفع بزره وسببه)
     */
    public function metaCheck()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $health = app(\App\Services\WhatsApp\MetaHealth::class);
        $health->credentials();
        $health->channel(humanInitiated: true);

        return response()->json([
            'meta' => \App\Services\WhatsApp\MetaHealth::summary(WaBotSetting::current()->fresh()),
            'channelHealth' => \App\Services\WhatsApp\ChannelHealth::available() ? \App\Services\WhatsApp\ChannelHealth::panel() : null,
        ]);
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
            // مصادر المحادثات (E38): أعداد فقط، خلف entry_tracking
            'entries' => \App\Support\FeatureFlags::on('entry_tracking') ? \App\Services\WhatsApp\BotAnalytics::entries() : null,
        ]);
    }

    public function analyticsData(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $days = (int) $request->integer('entries_days', 30);
        // فلتر فترة «مصادر المحادثات» وحده: بلا حساب الاستهلاك والجودة
        if ($request->query('only') === 'entries') {
            return response()->json(['entries' => \App\Support\FeatureFlags::on('entry_tracking') ? \App\Services\WhatsApp\BotAnalytics::entries($days) : null]);
        }

        [$usage, $quality] = $this->maskAdminChats(\App\Services\WhatsApp\BotAnalytics::usage(), \App\Services\WhatsApp\BotAnalytics::quality());

        return response()->json(['usage' => $usage, 'quality' => $quality,
            'entries' => \App\Support\FeatureFlags::on('entry_tracking') ? \App\Services\WhatsApp\BotAnalytics::entries($days) : null]);
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

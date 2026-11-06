<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageStored;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * طبقة الإنبوكس: خزّن أولاً ثمّ قرّر من يردّ. الوارد يصل من OpenWa\EventRouter بعد التحويل إلى InboundMessage.
 * البوت مستهلك لهذه الطبقة لا مالكها — إطفاؤه لا يوقف مركز المحادثات.
 * التفصيل: docs/whatsapp/inbox.md
 */
class WhatsAppInbox
{
    /**
     * رسالة بلا رقم ولا معرّف مرسل (لا from ولا from_user_id): لا يُعرف لمن تُحفظ. لا تضيع بصمت:
     * سجل بلا نص ولا اسم، وتنبيه للمدير مرة في الساعة، وعدّاد يومي.
     */
    private static function noIdentity(array $msg): void
    {
        WaLog::warning('webhook.inbound.no_identity', ['wamid' => data_get($msg, 'id'), 'type' => data_get($msg, 'type')]);
        $day = 'wa:noidentity:count:' . now()->format('Ymd');
        \Illuminate\Support\Facades\Cache::add($day, 0, now()->addDays(2));
        \Illuminate\Support\Facades\Cache::increment($day);

        if (\Illuminate\Support\Facades\Cache::add('wa:noidentity:alert:' . now()->format('YmdH'), 1, now()->addHour())) {
            self::notifyAdmins('⚠️ رسالة واتساب بلا مرسل', 'وصلت رسالة بلا رقم ولا معرّف مرسل فلم تُحفظ. التفاصيل في السجل.', '/whatsapp/inbox', 'RB-04');
        }
    }

    /**
     * وارد واحد بعد التحويل (OpenWa\InboundParser). $msg: الحمولة كما تُحفظ في payload (بلا base64).
     *
     * @return int|null معرّف المحادثة إن كانت الرسالة تستحق ردّ البوت
     */
    public static function storeInbound(InboundMessage $in, array $msg): ?int
    {
        $wamid = $in->wamid;

        if (!$wamid || ($in->fromPhone === null && $in->lid === null && $in->fromUserId === null)) {
            self::noIdentity($msg);

            return null;
        }

        // منع التكرار: البوابة تعيد التوصيلة عند المهلة، والاسترجاع بعد الانقطاع يعيد ما وصل
        if ($existing = self::existing($in)) {
            return self::pendingDuplicate($existing);
        }

        try {
            [$conv, $after, $stored] = DB::transaction(fn () => self::storeInboundRows($in, $msg));
        } catch (UniqueConstraintViolationException) {
            // نسختان من الإشعار نفسه في وقت واحد: الأخرى خزّنته
            $existing = self::existing($in);

            return $existing ? self::pendingDuplicate($existing) : null;
        }
        if ($conv === null) {
            return null;
        }

        // «حذف بياناتي» (E13): طلب مسجّل حتمياً، مستقلاً عن البوت ومن يمسك المحادثة. رقم الإدارة لا يُعدّ طالباً
        if ($in->type === 'text' && HandoffGuard::wantsErasure($in->body) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            ErasureRequests::receive($conv);
        }

        // وارد من محظور (E31): لا يُفترض رسمياً. مخزَّن كي لا يضيع، ولا يصل البوت، والحظر يصير mismatch في صفحة المزامنة
        if ($conv->isBlocked()) {
            BlockService::inboundWhileBlocked($conv);

            return null;
        }

        // رسالة لا يعرضها واتساب ويب (استطلاع، محجوبة عن الأجهزة المرتبطة): البوت لا يرد على نص لا يراه، فتصل موظفاً بسببها
        if ($in->type === 'unsupported') {
            ReplyState::markUnserved($conv, 'unsupported');

            return null;
        }

        // طلب أو سؤال عن منتج من كتالوج واتساب (C043): لا كتالوج عندنا ولا يرى البوت المنتج، فيصل موظفاً بسببه
        if ($in->type === 'order') {
            ReplyState::markUnserved($conv, 'catalog');

            return null;
        }

        // بعد الاعتماد: نداء HTTP قد يطول لا يُمسك قفل الكتابة في القاعدة
        if ($after === 'consent:no') {
            Media\MediaConsent::refused($conv);

            return null;
        }
        if ($after === 'consent:yes') {
            return null;
        }
        if ($after === 'optout') {
            WhatsAppChannel::sendText($conv, 'تم إيقاف الرسائل التسويقية. يمكنك مراسلتنا في أي وقت لأي استفسار.', 'system', sendKey: SendKey::optout((string) $in->wamid));

            return null;
        }

        return $conv->id;
    }

    /** الرسالة نفسها إن خُزّنت: بالمعرّف على الرقم نفسه */
    private static function existing(InboundMessage $in): ?WaMessage
    {
        return WaMessage::where('wamid', (string) $in->wamid)
            ->when($in->channelId !== null, fn ($q) => $q->where(fn ($w) => $w->where('channel_id', $in->channelId)->orWhereNull('channel_id')))
            ->first();
    }

    /**
     * مكرر من ميتا لوارد ما زال بلا رد: جدولته فشلت بعد التخزين (أو أُعيد بعد 503)، فيُعاد تمريره.
     * آمن: الرمز الأحدث يُبطل أي وظيفة منتظرة، والعلامة تمنع الرد المزدوج.
     */
    private static function pendingDuplicate(WaMessage $m): ?int
    {
        $pending = $m->direction === 'in' && !in_array($m->msg_type, ['reaction', 'unsupported'], true) && ReplyState::available()
            && $m->id > (int) WaConversation::whereKey($m->conversation_id)->value('bot_answered_in_id');

        return $pending ? (int) $m->conversation_id : null;
    }

    /**
     * صفوف الوارد كلها في معاملة واحدة: المحادثة والرسالة والنافذة، أو لا شيء.
     * @return array{0: ?WaConversation, 1: false|string, 2: ?WaMessage} المحادثة إن استحقت المتابعة، وما يُنفَّذ بعد الاعتماد (optout | consent:yes | consent:no)، والرسالة المخزّنة
     */
    private static function storeInboundRows(InboundMessage $in, array $msg): array
    {
        $conv = ContactIdentity::resolve($in);
        // أول مصدر للمحادثة (إعلان انقر للواتساب) يبقى
        if ($in->referral && !$conv->referral) {
            $conv->forceFill(['referral' => $in->referral])->save();
        }
        $wamid = (string) $in->wamid;
        $columns = self::newColumns($in);

        // التفاعلات (reaction) مسار منفصل: تُخزَّن ولا تفتح نافذة ولا تُمرَّر للبوت
        if ($in->isReaction()) {
            $reaction = WaMessage::create([
                'conversation_id' => $conv->id, 'wamid' => $wamid, 'direction' => 'in',
                'source' => 'customer', 'msg_type' => 'reaction',
                'body' => $in->body, 'payload' => $msg + ($in->reactionTo ? ['_reaction_to' => $in->reactionTo] : []),
            ] + $columns);
            EventBus::emit(new WaMessageStored($reaction->id, $conv->id, 'reaction'));
            // E33: شارة على الرسالة الأصلية بمقارنة طابع ميتا (لا تغيّر النافذة ولا العدّاد ولا المعاينة)، وإلا يبقى يتيماً
            ReactionProjector::applyInbound($conv, $in, $reaction->id);

            return [null, false, null];
        }

        $body = $in->body;
        $buttonId = $in->buttonId;
        // لا أزرار في واتساب ويب: «2» جواباً على رسالة أزرار النظام (موافقة الملفات، الإيقاف) هو الزر نفسه.
        // خيارات البوت (ch:…) يحلها ChoiceResolver::resolve أدناه بفحوصها
        if ($buttonId === null && $in->type === 'text' && ($picked = ChoiceResolver::numbered($conv, $in)) && !preg_match(ChoiceResolver::PATTERN, $picked[0])) {
            $buttonId = $picked[0];
        }
        $type = $in->type;
        if ($type === 'unsupported') {
            WaLog::notice('webhook.inbound.unsupported', ['wamid' => $wamid, 'type' => $in->unsupportedType, 'channel_id' => $in->channelId]);
        }
        \Illuminate\Support\Facades\Context::push('wamid_in', $wamid);

        // وقت الرسالة عند واتساب (لا وقت وصولها إلينا): عليه تُبنى بوابة الحداثة. لا يتجاوز «الآن»
        $ts = $in->timestamp ?: (int) data_get($msg, 'timestamp', 0);
        $metaAt = $ts > 0 ? min(\Illuminate\Support\Carbon::createFromTimestamp($ts, config('app.timezone')), now()) : now();

        // نقرة على خيار أرسله البوت (E36): تُحسم قبل الحفظ (المحادثة وcontext.id والحداثة والصلاحية)، والنتيجة مع الرسالة
        $choice = ChoiceResolver::resolve($conv, $in);

        $message = WaMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => $wamid,
            'direction' => 'in',
            'source' => 'customer',
            'msg_type' => $type,
            'body' => $body,
            'payload' => $msg + ($buttonId ? ['_button_id' => $buttonId] : []) + ($choice ? ['_choice' => $choice] : []),
        ] + $columns + (self::hasMetaAt() ? ['meta_at' => $metaAt] : []));
        // بعد اعتماد المعاملة وحدها (E21)؛ تكرار wamid يرمي قبل هنا فلا حدث له
        EventBus::emit(new WaMessageStored($message->id, $conv->id, (string) $type));
        // أرشفة المرفق (E14) بعد الاعتماد، خلف العلم media_archive
        Media\MediaIntake::onInbound($message, $conv, $msg);
        // رد العميل يلغي متابعاته المعلقة (quote.followup وlead.followup وكل نوع cancel_on_reply)
        System\Hooks::customerReplied($conv);

        // النافذة والمعاينة لا تتراجع: رسالة أقدم أعادت ميتا إرسالها لا تكتبهما
        WaConversation::whereKey($conv->id)
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<', $metaAt))
            ->update([
                'last_inbound_at' => $metaAt,
                'last_message_at' => $metaAt,
                'last_message_preview' => mb_substr(trim((string) $body), 0, 160),
            ]);
        // أي وارد (لا التفاعل ولا رسالة النظام، فهما يعودان قبل هنا) يعيد فتح المغلقة ويمسح التأجيل (E17).
        // ومن محظور (E31) لا يزيد غير المقروء، والعلامة تتقدّم فلا يجيب عنه البوت ولا المُصالِح لاحقاً
        $blocked = $conv->isBlocked();
        WaConversation::whereKey($conv->id)->update(($blocked ? [] : ['unread_count' => DB::raw('unread_count + 1')]) + ['status' => 'open'] + InboxFollowup::reopenColumns());
        $conv->refresh();
        if ($blocked) {
            ReplyState::advance($conv, $message->id);
        }

        // زرّا إشعار حفظ الملفات (E14): اختيار مسجّل لا يصل للبوت، والحذف بعد الاعتماد
        if ($choice = Media\MediaConsent::choice($buttonId)) {
            Media\MediaConsent::record($conv, $choice);
            ReplyState::advance($conv, $message->id);

            return [$conv, "consent:{$choice}", $message];
        }

        // إيقاف التسويق بنصّ مطابق
        // رقم الإدارة لا يُسجَّل رافضاً للتسويق: «إيقاف» منه تعني غالباً إيقاف البوت
        if (self::isOptOut($body, $buttonId) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            WaOptout::updateOrCreate(
                $conv->hasPhone() ? ['phone' => $conv->phone] : ['wa_user_id' => $conv->lid ?: $conv->wa_user_id],
                ['reason' => 'طلب العميل', 'created_at' => now()]
                    // مصدر الإيقاف (E21): زر optout أو كلمة إيقاف. بين النشر والهجرة لا عمود بعد
                    + ((self::$optoutSource ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_optouts', 'source')) ? ['source' => $buttonId === 'optout' ? 'button' : 'text'] : [])
            );
            // إشعار النظام هو الرد: لا يجيب البوت عنها لاحقاً (المُصالِح أو مكرر من ميتا)
            ReplyState::advance($conv, $message->id);

            return [$conv, 'optout', $message];
        }

        return [$conv, false, $message];
    }

    /** إيجاد/إنشاء محادثة برقم على رقم من أرقامنا وربطها بالعميل أو الموظف (الاختبارات ورسائل النظام؛ الويبهوك عبر resolve) */
    public static function conversationFor(string $waPhone, ?string $profileName = null, ?int $channelId = null): WaConversation
    {
        $channelId ??= \App\Models\WaChannel::query()->active()->orderBy('sort')->orderBy('id')->value('id');

        return ContactIdentity::resolveIdentity($waPhone, null, TextNormalizer::untrustedName($profileName), channelId: $channelId,
            jid: OpenWa\InboundParser::jidFor($waPhone));
    }

    /** الاقتباس والوسائط والإحالة ورقمنا المستقبِل */
    private static function newColumns(InboundMessage $in): array
    {
        return array_filter([
            'channel_id' => $in->channelId,
            'phone_number_id' => $in->phoneNumberId ?: null,
            'reply_to_wamid' => $in->replyToWamid,
            'referral' => $in->referral,
            'media_id' => $in->media['id'] ?? null,
            'media_mime' => $in->media['mime'] ?? null,
            'media_sha256' => $in->media['sha256'] ?? null,
            'media_filename' => $in->media['filename'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private static ?bool $optoutSource = null;

    private static ?bool $hasMetaAt = null;

    /** وقت الرسالة عند واتساب (meta_at) بعد الهجرة 2026_10_14_000012 */
    private static function hasMetaAt(): bool
    {
        return self::$hasMetaAt ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'meta_at');
    }

    private static function isOptOut(?string $body, ?string $buttonId): bool
    {
        if ($buttonId === 'optout') return true;
        $t = trim(mb_strtolower((string) $body));
        // «إلغاء» وحدها ليست هنا: من يكتبها يقصد غالباً إلغاء حجز، فتصل للبوت
        return in_array($t, ['stop', 'ايقاف', 'إيقاف', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'unsubscribe'], true);
    }

    /** $runbook: معرّف الكتيّب في docs/RUNBOOK.md (E25)، يُلحق بالنص بالعلم alert_runbook_refs وحده */
    public static function notifyAdmins(string $title, string $body, string $url = '/whatsapp/inbox', ?string $runbook = null): void
    {
        $body .= \App\Support\Ops\RunbookMap::suffix($runbook);
        try {
            $ids = \App\Models\User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->where('is_active', true)->pluck('id');
            foreach ($ids as $id) {
                \App\Services\NotificationService::send($id, $title, $body, [
                    'type' => 'whatsapp', 'icon' => '💬', 'action_url' => $url,
                ]);
            }
        } catch (\Throwable $e) {
            WaLog::error('notify.failed', ['exception' => $e]);
        }
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * طبقة الإنبوكس: خزّن أولاً ثمّ قرّر من يردّ.
 * البوت مستهلك لهذه الطبقة لا مالكها — إطفاؤه لا يوقف مركز المحادثات.
 */
class WhatsAppInbox
{
    /**
     * معالجة حمولة الويبهوك كاملة.
     * @param  array<int>  $touched  يُملأ أولاً بأول، فيجد المتحكم ما خُزّن قبل أي استثناء لاحق في الحمولة
     * @return array<int> معرّفات المحادثات التي وصلتها رسالة عميل تنتظر رداً (للتمرير للبوت)
     */
    public static function handleWebhook(array $payload, array &$touched = []): array
    {
        // للتطبيق أكثر من رقم، والويبهوك واحد للتطبيق كلّه — نعالج رقمنا وحده
        $settings = WaBotSetting::current();
        $ownId = (string) $settings->phoneNumberId();

        foreach (data_get($payload, 'entry', []) as $entry) {
            $entryId = (string) data_get($entry, 'id', '');

            foreach (data_get($entry, 'changes', []) as $change) {
                $field = data_get($change, 'field');
                $value = data_get($change, 'value', []);

                if ($field !== 'messages') {
                    // صحّة الحساب / حالة القوالب → قفل الإرسال احتياطاً، إن كان الحدث لرقمنا أو حسابنا
                    self::handleAccountEvent((string) $field, (array) $value, $entryId, $settings);
                    continue;
                }

                // حمولة بلا phone_number_id لا يُعرف رقمها، فلا تُعامل كأنها لنا
                $to = (string) data_get($value, 'metadata.phone_number_id', '');
                if ($ownId !== '' && $to !== $ownId) {
                    if ($to === '') {
                        WaLog::warning('webhook.messages.no_phone_number_id', ['entry' => $entryId]);
                    }
                    continue;
                }
                if ($ownId !== '') {
                    self::learnOwnAccount($settings, $entryId, (string) data_get($value, 'metadata.display_phone_number', ''));
                }

                // مطابقة كل رسالة بمدخلها: بالمعرّف (BSUID) ثم بالرقم (ContactIdentity::contactFor)
                $contacts = (array) data_get($value, 'contacts', []);

                // حالات التسليم
                foreach (data_get($value, 'statuses', []) as $st) {
                    self::handleStatus($st);
                }

                // الرسائل الواردة
                foreach (data_get($value, 'messages', []) as $msg) {
                    $convId = self::storeInbound($msg, $contacts);
                    if ($convId) {
                        $touched[] = $convId;
                    }
                }
            }
        }

        return array_values(array_unique($touched));
    }

    private static ?bool $hasMetaAt = null;

    private static function hasMetaAt(): bool
    {
        return self::$hasMetaAt ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'meta_at');
    }

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
            self::notifyAdmins('⚠️ رسالة واتساب بلا مرسل', 'وصلت رسالة بلا رقم ولا معرّف مرسل فلم تُحفظ. التفاصيل في السجل.', '/whatsapp/inbox');
        }
    }

    /** @return int|null معرّف المحادثة إن كانت الرسالة تستحق ردّ البوت */
    private static function storeInbound(array $msg, array $contacts): ?int
    {
        $wamid = data_get($msg, 'id');
        $from = data_get($msg, 'from');
        // عميل باسم مستخدم بلا تواصل حديث: from محذوف، والمعرّف الخاص بنشاطنا (BSUID) في from_user_id
        $userId = filled(data_get($msg, 'from_user_id')) ? (string) data_get($msg, 'from_user_id') : null;
        $type = data_get($msg, 'type', 'text');

        if (!$wamid || (ContactIdentity::phoneFrom($from) === null && $userId === null)) {
            self::noIdentity($msg);

            return null;
        }

        // منع التكرار — ميتا تعيد الإرسال عند عدم الردّ بـ200
        if ($existing = WaMessage::where('wamid', $wamid)->first()) {
            return self::pendingDuplicate($existing);
        }

        try {
            [$conv, $optOut] = DB::transaction(fn () => self::storeInboundRows($msg, $contacts, (string) $wamid, is_string($from) ? $from : null, $userId, (string) $type));
        } catch (UniqueConstraintViolationException) {
            // نسختان من الإشعار نفسه في وقت واحد: الأخرى خزّنته
            $existing = WaMessage::where('wamid', $wamid)->first();

            return $existing ? self::pendingDuplicate($existing) : null;
        }
        if ($conv === null) {
            return null;
        }

        // بعد الاعتماد: نداء HTTP قد يطول لا يُمسك قفل الكتابة في القاعدة
        if ($optOut) {
            WhatsAppChannel::sendText($conv, 'تم إيقاف الرسائل التسويقية. يمكنك مراسلتنا في أي وقت لأي استفسار.', 'system');

            return null;
        }

        // بلا رقم: لا يرد البوت إلا في الوضع full، فالمحادثة «تدخّل» لموظف (ويرد بالمعرّف من الوضع staff)
        if (!$conv->hasPhone() && !IdentityMode::botServesWithoutPhone()) {
            ReplyState::markUnserved($conv, 'no_phone');

            return null;
        }

        return $conv->id;
    }

    /**
     * مكرر من ميتا لوارد ما زال بلا رد: جدولته فشلت بعد التخزين (أو أُعيد بعد 503)، فيُعاد تمريره.
     * آمن: الرمز الأحدث يُبطل أي وظيفة منتظرة، والعلامة تمنع الرد المزدوج.
     */
    private static function pendingDuplicate(WaMessage $m): ?int
    {
        $pending = $m->direction === 'in' && $m->msg_type !== 'reaction' && ReplyState::available()
            && $m->id > (int) WaConversation::whereKey($m->conversation_id)->value('bot_answered_in_id');

        return $pending ? (int) $m->conversation_id : null;
    }

    /**
     * صفوف الوارد كلها في معاملة واحدة: المحادثة والرسالة والنافذة، أو لا شيء.
     * @return array{0: ?WaConversation, 1: bool} المحادثة إن استحقت المتابعة، وهل طلب إيقاف التسويق
     */
    private static function storeInboundRows(array $msg, array $contacts, string $wamid, ?string $from, ?string $userId, string $type): array
    {
        $conv = ContactIdentity::resolve($from, $userId, ContactIdentity::contactFor($contacts, $from, $userId));

        // التفاعلات (reaction) مسار منفصل: تُخزَّن ولا تفتح نافذة ولا تُمرَّر للبوت
        if ($type === 'reaction') {
            WaMessage::create([
                'conversation_id' => $conv->id, 'wamid' => $wamid, 'direction' => 'in',
                'source' => 'customer', 'msg_type' => 'reaction',
                'body' => data_get($msg, 'reaction.emoji', '👍'), 'payload' => $msg,
            ]);

            return [null, false];
        }

        [$body, $buttonId] = self::extract($msg, $type);
        \Illuminate\Support\Facades\Context::push('wamid_in', $wamid);

        // وقت ميتا للرسالة (لا وقت وصولها إلينا): عليه تُبنى النافذة وبوابة الحداثة. لا يتجاوز «الآن»
        $ts = (int) data_get($msg, 'timestamp', 0);
        $metaAt = $ts > 0 ? min(\Illuminate\Support\Carbon::createFromTimestamp($ts, config('app.timezone')), now()) : now();

        $message = WaMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => $wamid,
            'direction' => 'in',
            'source' => 'customer',
            'msg_type' => $type,
            'body' => $body,
            'payload' => $msg + ($buttonId ? ['_button_id' => $buttonId] : []),
        ] + (self::hasMetaAt() ? ['meta_at' => $metaAt] : []));

        // النافذة والمعاينة لا تتراجع: رسالة أقدم أعادت ميتا إرسالها لا تكتبهما
        WaConversation::whereKey($conv->id)
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<', $metaAt))
            ->update([
                'last_inbound_at' => $metaAt,
                'last_message_at' => $metaAt,
                'last_message_preview' => mb_substr(trim((string) $body), 0, 160),
            ]);
        WaConversation::whereKey($conv->id)->update(['unread_count' => DB::raw('unread_count + 1'), 'status' => 'open']);
        $conv->refresh();

        // إيقاف التسويق بنصّ مطابق
        // رقم الإدارة لا يُسجَّل رافضاً للتسويق: «إيقاف» منه تعني غالباً إيقاف البوت
        if (self::isOptOut($body, $buttonId) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            WaOptout::updateOrCreate(
                $conv->hasPhone() ? ['phone' => $conv->phone] : ['wa_user_id' => $conv->wa_user_id],
                ['reason' => 'طلب العميل', 'created_at' => now()]
            );
            // إشعار النظام هو الرد: لا يجيب البوت عنها لاحقاً (المُصالِح أو مكرر من ميتا)
            ReplyState::advance($conv, $message->id);

            return [$conv, true];
        }

        return [$conv, false];
    }

    /** إيجاد/إنشاء محادثة برقم وربطها بالعميل أو الموظف (MessageReplayer والاختبارات؛ الويبهوك عبر ContactIdentity) */
    public static function conversationFor(string $waPhone, ?string $profileName = null): WaConversation
    {
        return ContactIdentity::resolve($waPhone, null, $profileName ? ['profile' => ['name' => $profileName]] : []);
    }

    /** استخراج النص ومعرّف الزر إن وجد */
    private static function extract(array $msg, string $type): array
    {
        return match ($type) {
            'text' => [data_get($msg, 'text.body', ''), null],
            'interactive' => [
                data_get($msg, 'interactive.button_reply.title')
                    ?? data_get($msg, 'interactive.list_reply.title', ''),
                // التقاط الاثنين — القوائم ليست أزراراً
                data_get($msg, 'interactive.button_reply.id')
                    ?? data_get($msg, 'interactive.list_reply.id'),
            ],
            'button' => [data_get($msg, 'button.text', ''), data_get($msg, 'button.payload')],
            'image' => ['📷 صورة' . (data_get($msg, 'image.caption') ? ': ' . data_get($msg, 'image.caption') : ''), null],
            'audio' => ['🎤 رسالة صوتية', null],
            'video' => ['🎬 مقطع فيديو', null],
            'document' => ['📄 ملف: ' . data_get($msg, 'document.filename', ''), null],
            'location' => ['📍 موقع', null],
            'sticker' => ['🏷️ ملصق', null],
            default => ['[' . $type . ']', null],
        };
    }

    private static function isOptOut(?string $body, ?string $buttonId): bool
    {
        if ($buttonId === 'optout') return true;
        $t = trim(mb_strtolower((string) $body));
        // «إلغاء» وحدها ليست هنا: من يكتبها يقصد غالباً إلغاء حجز، فتصل للبوت
        return in_array($t, ['stop', 'ايقاف', 'إيقاف', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'unsubscribe'], true);
    }

    /** حالات التسليم: sent / delivered / read / failed */
    private static function handleStatus(array $st): void
    {
        $wamid = data_get($st, 'id');
        $status = data_get($st, 'status');
        if (!$wamid || !$status) return;

        $m = WaMessage::where('wamid', $wamid)->first();
        if (!$m) return;

        $payload = $m->payload ?? [];
        $payload['statusHistory'][] = ['status' => $status, 'at' => now()->toIso8601String()];

        // ميتا توصي بالبناء على code لا على title: الرمز مع نص عربي من الجدول، أو عنوان ميتا لما لا يعرفه
        $code = is_numeric(data_get($st, 'errors.0.code')) ? (int) data_get($st, 'errors.0.code') : null;
        $error = $code !== null ? MetaErrorCatalog::message($code, (string) data_get($st, 'errors.0.title', '')) : '';

        // الحالات تتقدّم ولا تتراجع (ميتا لا تضمن الترتيب): الأدنى يُضاف إلى السجل وحده، وfailed نهائية
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'played' => 4];
        $advances = $status === 'failed'
            ? $m->status !== 'failed'
            : $m->status !== 'failed' && ($rank[$status] ?? 0) > ($rank[$m->status] ?? 0);

        $update = ['payload' => $payload];
        if ($advances) {
            $update['status'] = $status;
            $update['error_message'] = $error !== '' ? $error : $m->error_message;
            if ($code !== null && \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'meta_code')) {
                $update['meta_code'] = $code;
            }
        }
        $m->update($update);

        if ($code !== null) {
            WaLog::warning('wa.status.failed', ['message_id' => $m->id, 'conv_id' => $m->conversation_id, 'meta_code' => $code]);
            MetaErrorCatalog::react($code);
        }

        // رد آلي لم يصل: المحادثة تحتاج موظفاً
        if ($advances && $status === 'failed' && $m->direction === 'out' && in_array($m->source, ['bot', 'system'], true)
            && ($conv = WaConversation::find($m->conversation_id)) && ReplyState::available()) {
            ReplyState::markUnserved($conv, 'send_failed');
        }
    }

    /**
     * حمولة messages لرقمنا تعرّفنا بحساب الأعمال (entry.id) والرقم المعروض، لنميّز بهما أحداث الحساب.
     * يُحفظ عند التغيّر فقط.
     */
    private static ?bool $canLearn = null;

    private static function learnOwnAccount(WaBotSetting $settings, string $entryId, string $displayPhone): void
    {
        // بين نشر الكود وتشغيل الهجرة: لا عمودين بعد، والرسالة يجب أن تُخزَّن رغم ذلك
        self::$canLearn ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_bot_settings', ['wa_waba_id', 'wa_display_phone']);
        if (!self::$canLearn) {
            return;
        }

        $learned = array_filter([
            'wa_waba_id' => $entryId,
            'wa_display_phone' => PhoneNormalizer::digits($displayPhone),
        ], fn ($v) => $v !== '');

        $settings->forceFill($learned);
        if ($settings->isDirty()) {
            $settings->save();
        }
    }

    /**
     * أحداث صحة الحساب: الحدث السلبي وحده يقفل الإرسال (أحداث عادية تصل عند ربط الحساب أو توثيقه تُسجَّل فقط).
     * الويبهوك واحد للتطبيق، فحدث رقم آخر أو حساب آخر يُتجاهل حين نعرف رقمنا وحسابنا.
     * ما لم نعرفهما بعد (لم تصل رسالة لرقمنا)، يبقى الإيقاف الاحتياطي.
     */
    private static function handleAccountEvent(string $field, array $value, string $entryId, WaBotSetting $settings): void
    {
        $critical = ['account_update', 'phone_number_quality_update'];
        if (!in_array($field, $critical, true)) return;

        $ours = (string) $settings->wa_display_phone;
        $theirs = PhoneNormalizer::digits((string) data_get($value, 'display_phone_number', ''));
        $otherNumber = $field === 'phone_number_quality_update' && $ours !== '' && $theirs !== '' && $theirs !== $ours;
        $otherAccount = $field === 'account_update' && (string) $settings->wa_waba_id !== '' && $entryId !== '' && $entryId !== (string) $settings->wa_waba_id;
        if ($otherNumber || $otherAccount) {
            WaLog::warning('account.event.other_number', ['field' => $field, 'event' => data_get($value, 'event'), 'entry' => $entryId]);
            return;
        }

        $event = data_get($value, 'event') ?: data_get($value, 'current_limit') ?: $field;
        // الحقل والحدث فقط: الحمولة فيها أرقام معروضة وبيانات حساب
        WaLog::warning('account.event', ['field' => $field, 'event' => (string) $event, 'entry' => $entryId]);

        $bad = ['FLAGGED', 'DOWNGRADE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_DELETED'];
        $violation = data_get($value, 'violation_info') || data_get($value, 'ban_info');
        if (in_array(strtoupper((string) $event), $bad, true) || $violation) {
            WaBotSetting::current()->update(['wa_suspended' => true]);
            self::notifyAdmins('⛔ إيقاف إرسال الواتساب', "وصل حدث «{$event}» من ميتا، فأُوقف كل الإرسال تلقائياً. راجع صحّة الحساب قبل إعادة التشغيل.");
        }
    }

    public static function notifyAdmins(string $title, string $body, string $url = '/whatsapp/inbox'): void
    {
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

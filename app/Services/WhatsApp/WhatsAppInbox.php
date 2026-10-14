<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\AuditActor;
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

                // تغيّر معرّف العميل (إن وصل حقلاً): هوية لا حدث حساب، فلا يمس الإيقاف أبداً
                if ($field === 'user_id_update') {
                    $to = (string) data_get($value, 'metadata.phone_number_id', '');
                    if ($ownId === '' || $to === $ownId) {
                        ContactIdentity::applyUserIdUpdate((array) $value);
                    }
                    continue;
                }
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

                // أخطاء على مستوى الحمولة: الرمز والعنوان فقط
                foreach ((array) data_get($value, 'errors', []) as $error) {
                    WaLog::warning('webhook.value_error', ['meta_code' => data_get($error, 'code'), 'reason' => mb_substr((string) data_get($error, 'title'), 0, 120)]);
                }

                // حالات التسليم
                foreach (data_get($value, 'statuses', []) as $st) {
                    self::handleStatus($st);
                }

                // الرسائل الواردة
                foreach (data_get($value, 'messages', []) as $msg) {
                    $convId = self::storeInbound((array) $msg, $contacts, $to);
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
    private static function storeInbound(array $msg, array $contacts, string $pnid = ''): ?int
    {
        // عميل باسم مستخدم بلا تواصل حديث: from محذوف، والمعرّف الخاص بنشاطنا (BSUID) في from_user_id
        $in = InboundMessage::fromWebhook($msg, ContactIdentity::contactFor($contacts, data_get($msg, 'from'), data_get($msg, 'from_user_id')), $pnid);
        $wamid = $in->wamid;

        if (!$wamid || ($in->fromPhone === null && $in->fromUserId === null)) {
            self::noIdentity($msg);

            return null;
        }

        // منع التكرار — ميتا تعيد الإرسال عند عدم الردّ بـ200
        if ($existing = WaMessage::where('wamid', $wamid)->first()) {
            return self::pendingDuplicate($existing);
        }

        // رسالة نظام (غيّر العميل رقمه أو تغيّر معرّفه): تحديث هوية في محادثته، لا وارد يرد عليه البوت
        if ($in->isSystem()) {
            try {
                DB::transaction(fn () => ContactIdentity::applySystemChange($in));
            } catch (UniqueConstraintViolationException) {
                // مكرر من ميتا وصل معاً
            }

            return null;
        }

        try {
            [$conv, $after] = DB::transaction(fn () => self::storeInboundRows($in, $msg));
        } catch (UniqueConstraintViolationException) {
            // نسختان من الإشعار نفسه في وقت واحد: الأخرى خزّنته
            $existing = WaMessage::where('wamid', $wamid)->first();

            return $existing ? self::pendingDuplicate($existing) : null;
        }
        if ($conv === null) {
            return null;
        }

        // «حذف بياناتي» (E13): طلب مسجّل حتمياً، مستقلاً عن البوت ومن يمسك المحادثة. رقم الإدارة لا يُعدّ طالباً
        if ($in->type === 'text' && HandoffGuard::wantsErasure($in->body) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            ErasureRequests::receive($conv);
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
     * @return array{0: ?WaConversation, 1: false|string} المحادثة إن استحقت المتابعة، وما يُنفَّذ بعد الاعتماد (optout | consent:yes | consent:no)
     */
    private static function storeInboundRows(InboundMessage $in, array $msg): array
    {
        $conv = ContactIdentity::resolve($in);
        // رقم شاركه العميل بزر «طلب الرقم» يملأ المحادثة (بطاقة شخص ثالث لا)
        ContactIdentity::acceptSharedPhone($conv, $in);
        // أول مصدر للمحادثة (إعلان انقر للواتساب) يبقى
        if ($in->referral && !$conv->referral) {
            $conv->forceFill(['referral' => $in->referral])->save();
        }
        $wamid = (string) $in->wamid;
        $columns = self::newColumns($in);

        // التفاعلات (reaction) مسار منفصل: تُخزَّن ولا تفتح نافذة ولا تُمرَّر للبوت
        if ($in->isReaction()) {
            WaMessage::create([
                'conversation_id' => $conv->id, 'wamid' => $wamid, 'direction' => 'in',
                'source' => 'customer', 'msg_type' => 'reaction',
                'body' => $in->body, 'payload' => $msg + ($in->reactionTo ? ['_reaction_to' => $in->reactionTo] : []),
            ] + $columns);

            return [null, false];
        }

        $body = $in->body;
        $buttonId = $in->buttonId;
        $type = $in->type;
        if ($type === 'unsupported') {
            WaLog::warning('webhook.inbound.unsupported', ['wamid' => $wamid, 'meta_code' => 131051, 'type' => $in->unsupportedType]);
        }
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
        ] + $columns + (self::hasMetaAt() ? ['meta_at' => $metaAt] : []));
        // أرشفة المرفق (E14) بعد الاعتماد، خلف العلم media_archive
        Media\MediaIntake::onInbound($message, $conv, $msg);

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

        // زرّا إشعار حفظ الملفات (E14): اختيار مسجّل لا يصل للبوت، والحذف بعد الاعتماد
        if ($choice = Media\MediaConsent::choice($buttonId)) {
            Media\MediaConsent::record($conv, $choice);
            ReplyState::advance($conv, $message->id);

            return [$conv, "consent:{$choice}"];
        }

        // إيقاف التسويق بنصّ مطابق
        // رقم الإدارة لا يُسجَّل رافضاً للتسويق: «إيقاف» منه تعني غالباً إيقاف البوت
        if (self::isOptOut($body, $buttonId) && !\App\Models\WaAdminNumber::forPhone($conv->phone)) {
            WaOptout::updateOrCreate(
                $conv->hasPhone() ? ['phone' => $conv->phone] : ['wa_user_id' => $conv->wa_user_id],
                ['reason' => 'طلب العميل', 'created_at' => now()]
            );
            // إشعار النظام هو الرد: لا يجيب البوت عنها لاحقاً (المُصالِح أو مكرر من ميتا)
            ReplyState::advance($conv, $message->id);

            return [$conv, 'optout'];
        }

        return [$conv, false];
    }

    /** إيجاد/إنشاء محادثة برقم وربطها بالعميل أو الموظف (الاختبارات؛ الويبهوك عبر ContactIdentity::resolve) */
    public static function conversationFor(string $waPhone, ?string $profileName = null): WaConversation
    {
        return ContactIdentity::resolveIdentity($waPhone, null, TextNormalizer::untrustedName($profileName));
    }

    /** @var bool|null أعمدة E11 في wa_messages (بين النشر والهجرة لا توجد، والرسالة تُخزَّن رغم ذلك) */
    private static ?bool $hasE11Columns = null;

    /** الاقتباس والوسائط والإحالة ورقمنا المستقبِل */
    private static function newColumns(InboundMessage $in): array
    {
        self::$hasE11Columns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['reply_to_wamid', 'media_id', 'phone_number_id']);
        if (!self::$hasE11Columns) {
            return [];
        }

        return array_filter([
            'phone_number_id' => $in->phoneNumberId ?: null,
            'reply_to_wamid' => $in->replyToWamid,
            'referral' => $in->referral,
            'media_id' => $in->media['id'] ?? null,
            'media_mime' => $in->media['mime'] ?? null,
            'media_sha256' => $in->media['sha256'] ?? null,
            'media_filename' => $in->media['filename'] ?? null,
        ], fn ($v) => $v !== null);
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
            // رمزنا الداخلي من الجدول (رقم ميتا في meta_code)
            if ($code !== null && (self::$hasE11Columns ??= \Illuminate\Support\Facades\Schema::hasColumns('wa_messages', ['reply_to_wamid', 'media_id', 'phone_number_id']))) {
                $update['error_code'] = mb_substr(MetaErrorCatalog::codeFor($code), 0, 32);
            }
        }
        $m->update($update);

        // حالة فيها الرقم والمعرّف معاً تملأ معرّف محادثة رقمها معروف
        if ($m->direction === 'out' && filled(data_get($st, 'recipient_user_id')) && ($c = WaConversation::find($m->conversation_id))) {
            ContactIdentity::fillFromStatus($st, $c);
        }

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
            // معرفة تشغيلية لا تعديل مدير: بلا رفع النسخة وبلا تدقيق
            SettingsWriter::apply($settings->getDirty(), null, null, bumpVersion: false, audit: 'none');
            $settings->syncOriginal();
        }
    }

    /**
     * أحداث صحة الحساب: الحدث السلبي وحده يقفل الإرسال (أحداث عادية تصل عند ربط الحساب أو توثيقه تُسجَّل فقط).
     * الويبهوك واحد للتطبيق، فحدث رقم آخر أو حساب آخر يُتجاهل حين نعرف رقمنا وحسابنا.
     * ما لم نعرفهما بعد (لم تصل رسالة لرقمنا)، يبقى الإيقاف الاحتياطي.
     * وكل حقل لنا صف تدقيق دائم بفاعل meta (E09)؛ فشل كتابته لا يقطع بقية الحمولة.
     */
    private static function handleAccountEvent(string $field, array $value, string $entryId, WaBotSetting $settings): void
    {
        $ours = (string) $settings->wa_display_phone;
        $theirs = PhoneNormalizer::digits((string) data_get($value, 'display_phone_number', ''));
        $otherNumber = $field === 'phone_number_quality_update' && $ours !== '' && $theirs !== '' && $theirs !== $ours;
        $otherAccount = $field !== 'phone_number_quality_update' && (string) $settings->wa_waba_id !== '' && $entryId !== '' && $entryId !== (string) $settings->wa_waba_id;
        if ($otherNumber || $otherAccount) {
            WaLog::warning('account.event.other_number', ['field' => $field, 'event' => data_get($value, 'event'), 'entry' => $entryId]);
            return;
        }

        $event = strtoupper((string) (data_get($value, 'event') ?: data_get($value, 'alert_type') ?: data_get($value, 'decision') ?: $field));
        $critical = in_array($field, ['account_update', 'phone_number_quality_update'], true);
        if ($critical) {
            // الحقل والحدث فقط: الحمولة فيها أرقام معروضة وبيانات حساب
            WaLog::warning('account.event', ['field' => $field, 'event' => $event, 'entry' => $entryId]);
        }

        [$action, $severity] = self::accountAudit($field, $value, $event);
        try {
            AuditActor::as('meta', $field, fn () => AuditLog::safe($action, 'wa_account', null, $event, self::accountSnapshot($field, $value), $severity));
        } catch (\Throwable) {
            // safe لا يرمي؛ الحارس لما قبله (السياق) كي لا يضيع ما بعده في الحمولة
        }

        if (!$critical) {
            return;
        }

        $bad = ['FLAGGED', 'DOWNGRADE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_DELETED'];
        $violation = data_get($value, 'violation_info') || data_get($value, 'ban_info');
        if (in_array($event, $bad, true) || $violation) {
            if (!WaBotSetting::current()->wa_suspended) {
                // يرفع النسخة: صفحة إعدادات فُتحت قبله لا تلغيه بحفظها (409). «من أوقف؟» له جواب دائماً (alwaysOn)،
                // وفشل كتابة التدقيق لا يُسقط الإيقاف ولا الويبهوك (safe)
                AuditActor::as('meta', $field, fn () => SettingsWriter::apply([
                    'wa_suspended' => true, 'wa_suspended_at' => now(), 'wa_suspended_by' => null,
                    'wa_suspended_source' => 'meta_event', 'wa_suspended_reason' => $event,
                ], null, AuditAction::WaSuspendAuto, ['field' => $field, 'event' => $event], audit: 'safe', label: $event));
            }
            self::notifyAdmins('⛔ إيقاف إرسال الواتساب', "وصل حدث «{$event}» من ميتا، فأُوقف كل الإرسال تلقائياً. راجع صحّة الحساب قبل إعادة التشغيل.");
        } elseif ($event === 'ACCOUNT_OFFBOARDED') {
            // فصل الحساب عن الشريك: تنبيه فقط، والإيقاف التلقائي له قرار مالك (E09.9)
            self::notifyAdmins('⚠️ فُصل حساب واتساب للأعمال', 'وصل حدث ACCOUNT_OFFBOARDED من ميتا. راجع ربط الحساب قبل أن يتوقف الإرسال.');
        }
    }

    /** @return array{0: AuditAction, 1: AuditSeverity} */
    private static function accountAudit(string $field, array $value, string $event): array
    {
        return match ($field) {
            'account_update' => [AuditAction::WaAccountUpdate,
                in_array($event, ['ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_DELETED', 'ACCOUNT_OFFBOARDED'], true)
                || data_get($value, 'violation_info') || data_get($value, 'ban_info') || data_get($value, 'restriction_info')
                    ? AuditSeverity::Error : AuditSeverity::Info],
            'account_alerts' => [AuditAction::WaAccountAlert, match (strtoupper((string) data_get($value, 'alert_severity'))) {
                'CRITICAL' => AuditSeverity::Error,
                'WARNING' => AuditSeverity::Warn,
                default => AuditSeverity::Info,
            }],
            'security' => [AuditAction::WaSecurity, AuditSeverity::Warn],
            'phone_number_quality_update' => [AuditAction::WaQualityUpdate,
                in_array($event, ['FLAGGED', 'DOWNGRADE'], true) ? AuditSeverity::Error : AuditSeverity::Info],
            'account_review_update' => [AuditAction::WaReviewUpdate,
                strtoupper((string) data_get($value, 'decision')) === 'REJECTED' ? AuditSeverity::Error : AuditSeverity::Info],
            'business_capability_update' => [AuditAction::WaCapabilityUpdate, AuditSeverity::Info],
            default => [AuditAction::WaWebhookOther, AuditSeverity::Info],
        };
    }

    /** الحمولة كما وصلت بعد الحجب (AuditRedactor عند الكتابة)، بحد 8KB. تفضيلات العملاء فيها wa_id: أعداد فقط */
    private static function accountSnapshot(string $field, array $value): array
    {
        if ($field === 'user_preferences') {
            $prefs = (array) data_get($value, 'user_preferences', []);

            return ['count' => count($prefs), 'by_value' => array_count_values(array_map(fn ($p) => (string) data_get($p, 'value', '?'), $prefs))];
        }
        if ($field === 'phone_number_quality_update') {
            // الاسم الأحدث للحد اليومي، وcurrent_limit إن وصل بالشكل القديم
            $value['limit'] = data_get($value, 'max_daily_conversations_per_business') ?? data_get($value, 'current_limit');
        }

        $bytes = strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE));

        return $bytes <= 8192 ? $value : ['truncated' => true, 'bytes' => $bytes, 'keys' => array_slice(array_keys($value), 0, 40)];
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

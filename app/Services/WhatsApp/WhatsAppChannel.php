<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageSent;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OpenWa\MapperException;
use App\Services\WhatsApp\OpenWa\OpenWaError;
use App\Services\WhatsApp\OpenWa\OpenWaResponse;
use App\Services\WhatsApp\OpenWa\PayloadMapper;
use App\Services\WhatsApp\Sending\FailureBreaker;
use App\Services\WhatsApp\Transport\ChannelTransport;

/**
 * بوّابة الإرسال الوحيدة إلى واتساب (2026-11-06: عبر بوابة OpenWA) — كل صادر (بوت/موظف/نظام) يمرّ من هنا.
 *
 * المحادثة لرقم من أرقامنا (wa_conversations.channel_id)، والرد يخرج من رقمها دائماً بمفتاحه المحصور بجلسته.
 * الحمولة الداخلية بشكل Cloud API كما يبنيها OutboundMessage (تفحصها OutboundPolicy وتُحفظ في السجل)، ويحوّلها
 * OpenWa\PayloadMapper إلى نداء البوابة عند الإرسال وحده.
 *
 * الأقفال بالترتيب (gate):
 *   0) الموظف يرى المحادثة (لرسائل الموظف وحدها)
 *   1) للمحادثة رقم فعّال (NO_CHANNEL)
 *   2) الإيقاف الطارئ العام (wa_suspended)، ثم إيقاف الرقم (paused_until؛ علامة التقييد من واتساب)
 *   3) الرقم متصل فعلاً (CHANNEL_NOT_READY)، وقاطع إخفاقاته مغلق
 *   4) المحظور: لا شيء يُرسل له
 *   5) الوجهة: jid المحادثة (أو هاتفها @c.us)
 * لا نافذة 24 ساعة ولا سقف هنا: الإرسال الاستباقي يمر بـ wa_outbox وSendGovernor (حدود كل رقم ونوع)، والردود مسار سريع.
 *
 * دفتر الإرسال (E19) دائماً: صف queued قبل النداء، ثم sent بمعرّف البوابة أو failed (المفتاح محرَّر) أو unknown (محجوز).
 * Idempotency-Key = nj-m-{رقم الصف}، فإعادة المحاولة بعد مهلة لا تكرر الرسالة عند البوابة.
 * إشعار القراءة ومؤشر الكتابة ليسا رسالة: markRead خارج الدفتر، بالأقفال 1–3.
 * التفصيل: docs/whatsapp/channel.md وdocs/whatsapp/openwa.md
 */
class WhatsAppChannel
{
    public const ERR_NOT_CONFIGURED = 'CHANNEL_NOT_CONFIGURED';
    public const ERR_NO_CHANNEL = 'NO_CHANNEL';
    public const ERR_NOT_READY = 'CHANNEL_NOT_READY';
    public const ERR_PAUSED = 'CHANNEL_PAUSED';
    public const ERR_SUSPENDED = 'SENDING_SUSPENDED';
    public const ERR_OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const ERR_UNSUPPORTED = 'UNSUPPORTED';
    public const ERR_TOO_LARGE = 'TOO_LARGE';
    public const ERR_NO_RECIPIENT = 'NO_RECIPIENT';
    public const ERR_BLOCKED = 'BLOCKED';
    public const ERR_CHANNEL_BLOCKED = 'CHANNEL_BLOCKED';
    public const ERR_MEDIA_INTEGRITY = 'MEDIA_INTEGRITY';
    public const ERR_GROUP_STAFF_ONLY = 'GROUP_STAFF_ONLY';

    /** نص الزر المعروض في الصندوق (الجسم المرسل للعميل غيره) */
    public const CONTACT_REQUEST_LABEL = '📱 طلب مشاركة رقم الهاتف';

    /** أنواع الصوت التي نفرّغها؛ غيرها لا يُنزَّل */
    private const AUDIO_MIME = ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'];

    private static function transport(): ChannelTransport
    {
        return app(ChannelTransport::class);
    }

    /** رقم المحادثة الفعّال، أو null (محادثة رقم ميتا القديم أو رقم حُذف) */
    public static function channelFor(WaConversation $conv): ?WaChannel
    {
        // محادثة بلا رقم (أُنشئت قبل الأرقام أو يدوياً) ونُسك فيه رقم فعّال واحد: تُنسب إليه. مع رقمين لا تخمين
        if (!$conv->channel_id && $conv->exists) {
            $only = WaChannel::query()->active()->limit(2)->get();
            if ($only->count() !== 1) {
                return null;
            }
            try {
                $conv->forceFill(['channel_id' => $only[0]->id])->saveQuietly();
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // للعميل محادثة على هذا الرقم أصلاً: لا تُنسب هذه إليه
                $conv->channel_id = null;

                return null;
            }
            $conv->setRelation('channel', $only[0]);
        }
        if (!$conv->channel_id) {
            return null;
        }
        $ch = $conv->relationLoaded('channel') ? $conv->channel : WaChannel::find($conv->channel_id);

        return $ch && $ch->is_active ? $ch : null;
    }

    // ==================== الوسائط الواردة ====================

    /**
     * تنزيل وسيط رسالة واردة من البوابة: GET /messages/{chatId}/{id}/media. المعرّف هو معرّف الرسالة (media_id = wamid)،
     * فيُعرف رقمها ومحادثتها من صفها. الحجم مقيّد بعد التنزيل (البوابة تعيد الملف كاملاً)، والنوع من ترويسة الرد.
     *
     * @return array{ok: bool, code: ?string, error: ?string, bytes?: string, mime?: string, host?: string, sha256_ok?: ?bool}
     */
    public static function fetchMedia(string $mediaId, string $kind = 'audio', int $maxBytes = 15_000_000, ?int $messageId = null): array
    {
        $row = $messageId ? WaMessage::find($messageId) : null;
        $row ??= $mediaId !== '' ? WaMessage::where('media_id', $mediaId)->orWhere('wamid', $mediaId)->orderByDesc('id')->first() : null;
        $conv = $row?->conversation;
        $ch = $row?->channel_id ? WaChannel::find($row->channel_id) : ($conv ? self::channelFor($conv) : null);
        if (!$row || !$conv || !$ch) {
            return self::mediaFail(self::ERR_NO_CHANNEL, 'لا رقم واتساب لهذه الرسالة.');
        }
        $chat = (string) (data_get($row->payload, 'chatId') ?: $conv->jid ?: ($conv->wa_phone ? $conv->wa_phone.'@c.us' : ''));
        if ($chat === '' || !$row->wamid) {
            return self::mediaFail(self::ERR_NO_RECIPIENT, 'لا معرّف محادثة لهذه الرسالة.');
        }

        $res = self::transport()->call($ch, 'GET', '/messages/'.rawurlencode($chat).'/'.rawurlencode((string) $row->wamid).'/media', null, 'send');
        if (!$res->ok) {
            $code = $res->status() === 404 ? 'MEDIA_EXPIRED' : ($res->error?->code ?? 'GW_ERROR');
            WaLog::warning('wa.media_failed', ['message_id' => $row->id, 'error_code' => $code, 'http' => $res->status(), 'channel_id' => $ch->id]);

            return self::mediaFail($code, 'تعذّر تنزيل الوسيط من الهاتف.');
        }
        $bytes = $res->body();
        if (strlen($bytes) > $maxBytes) {
            return self::mediaFail(self::ERR_TOO_LARGE, 'الملف أكبر من الحد المسموح.');
        }
        if ($bytes === '') {
            return self::mediaFail('MEDIA_EXPIRED', 'الوسيط لم يعد متاحاً على الهاتف.');
        }
        $mime = strtolower(trim(explode(';', (string) ($res->header('Content-Type') ?: $row->media_mime ?: 'application/octet-stream'))[0]));
        if ($kind === 'audio' && !in_array($mime, self::AUDIO_MIME, true)) {
            return self::mediaFail(self::ERR_UNSUPPORTED, 'نوع الملف الصوتي غير مدعوم.');
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'bytes' => $bytes, 'mime' => $mime, 'host' => 'openwa', 'sha256_ok' => null];
    }

    /**
     * الغلاف القديم: null عند أي فشل.
     *
     * @return array{bytes: string, mime: string, host: string}|null
     */
    public static function downloadMedia(string $mediaId, int $maxBytes = 15_000_000, string $kind = 'audio'): ?array
    {
        if ($mediaId === '') {
            return null;
        }
        $media = self::fetchMedia($mediaId, $kind, $maxBytes);

        return $media['ok'] ? ['bytes' => $media['bytes'], 'mime' => $media['mime'], 'host' => $media['host']] : null;
    }

    /** لا نسخة للوسائط عند طرف ثالث بعد الإرسال (البوابة لا تحتفظ بما أُرسل): لا شيء يُحذف */
    public static function deleteMedia(string $mediaId): ?bool
    {
        return null;
    }

    private static function mediaFail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }

    // ==================== الأغلفة ====================

    /**
     * إرسال رسالة نصية (غلاف رقيق فوق send()، E32).
     * $opts (E33): reply_to (WaMessage من المحادثة نفسها)، وpreview_url (طلب يقرره dispatch: للموظف وحده بالعلم والسياسة)،
     * وmeta (يُحفظ في payload._meta ولا يُرسل)، وtype_key (نوع رسالة النظام للعدّ في الحدود).
     *
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendText(WaConversation $conv, string $text, string $source = 'bot', ?int $staffId = null, string $context = 'reply', ?string $sendKey = null, array $opts = []): array
    {
        return self::send($conv, Outbound\OutboundMessage::text($text, (bool) ($opts['preview_url'] ?? false)), $source, $staffId,
            ['context' => $context, 'send_key' => $sendKey] + array_intersect_key($opts, array_flip(['reply_to', 'meta', 'type_key'])));
    }

    /**
     * صورة برابط https (بطاقة عرض مثلاً) مع تعليق اختياري. الرابط على APP_URL/storage يُقرأ من القرص مباشرة
     * (PayloadMapper)، وغيره تجلبه البوابة.
     *
     * @return array{ok:bool, error:?string, code:?string, message_id:?string}
     */
    public static function sendImage(WaConversation $conv, string $url, ?string $caption = null, string $source = 'bot', ?int $staffId = null, ?string $sendKey = null, array $opts = []): array
    {
        if (!str_starts_with($url, 'https://')) {
            return self::failLocal('BAD_MEDIA_URL', 'رابط الصورة يجب أن يكون HTTPS.');
        }

        return self::send($conv, Outbound\OutboundMessage::image(caption: $caption, link: $url), $source, $staffId,
            ['send_key' => $sendKey, 'display' => $caption ?: '📷 صورة'] + array_intersect_key($opts, array_flip(['reply_to', 'meta', 'type_key'])));
    }

    // ==================== الخيارات (بديل الأزرار والقوائم) ====================

    /**
     * أزرار رد (حتى 3): لا أزرار في واتساب ويب، فتُرسل نصاً بخيارات مرقمة (PayloadMapper) ويحل ChoiceResolver الرد
     * بالرقم أو بالعنوان. الحمولة الداخلية تبقى interactive فيعرض الصندوق الخيارات ويحفظ wa_choice_sets المعرّفات.
     *
     * @param  array<array{id: string, title: string}>  $buttons
     */
    public static function sendButtons(WaConversation $conv, string $body, array $buttons, string $source = 'bot', ?int $staffId = null, array $opts = []): array
    {
        $m = Outbound\InteractiveTypes::buttons($body, $buttons, $opts);

        return $m ? self::send($conv, $m, $source, $staffId, self::interactiveOpts($opts))
            : self::failLocal('BAD_INTERACTIVE', 'رسالة تفاعلية بلا خيارات صالحة.');
    }

    /**
     * قائمة: نص بخيارات مرقمة عبر الأقسام (حتى 10 صفوف في المجموع).
     *
     * @param  array<array{title?: string, rows: array}>|array<array{id: string, title: string, description?: string}>  $sections
     */
    public static function sendList(WaConversation $conv, string $body, string $buttonLabel, array $sections, string $source = 'bot', ?int $staffId = null, array $opts = []): array
    {
        $m = Outbound\InteractiveTypes::list($body, $buttonLabel, $sections, $opts);

        return $m ? self::send($conv, $m, $source, $staffId, self::interactiveOpts($opts))
            : self::failLocal('BAD_INTERACTIVE', 'رسالة تفاعلية بلا خيارات صالحة.');
    }

    private static function interactiveOpts(array $opts): array
    {
        return array_intersect_key($opts, array_flip(['send_key', 'reply_to', 'meta', 'context', 'type_key']));
    }

    /**
     * طلب رقم العميل: لا زر «مشاركة الرقم» في واتساب ويب، فيُرسل النص مع سطر يطلب كتابة الرقم أو إرسال جهة الاتصال.
     * محادثة المرسل خلف معرّف خصوصية (@lid) يُعرف رقمها حين يكتبه أو حين يحله whatsapp:resolve-lids.
     */
    public static function sendContactRequest(WaConversation $conv, string $body, string $source = 'staff', ?int $staffId = null, ?string $sendKey = null): array
    {
        $text = trim(mb_substr($body, 0, 1024))."\n\n".'اكتب رقم هاتفك هنا من فضلك.';

        return self::send($conv, Outbound\OutboundMessage::text($text), $source, $staffId, ['send_key' => $sendKey, 'display' => self::CONTACT_REQUEST_LABEL]);
    }

    // ==================== الرسائل الغنية: التفاعل والموقع والبطاقات (E33) ====================

    /**
     * تفاعل الموظف بإيموجي على رسالة العميل (C008). الموظف دائماً، ولا يستدعيها البوت ولا المساعد الإداري
     * (NoBotReactionsTest). as_reply=false: إقرار باستلام لا رد، فلا يُسكت البوت ولا يمس شارة التدخّل. '' يزيل التفاعل.
     */
    public static function sendReaction(WaConversation $conv, WaMessage $target, string $emoji, int $staffId, ?string $sendKey = null): array
    {
        if (!\App\Support\FeatureFlags::on('wa_staff_reactions')) {
            return self::failLocal('FEATURE_OFF', 'تفاعل الموظف غير مفعّل.');
        }
        $emoji = trim($emoji);
        if ($emoji !== '' && !in_array($emoji, (array) config('whatsapp.staff_reactions', []), true)) {
            return self::failLocal('BAD_REACTION', 'هذا التفاعل غير مسموح.');
        }
        if ((int) $target->conversation_id !== (int) $conv->id || $target->direction !== 'in' || $target->source !== 'customer'
            || in_array($target->msg_type, ['reaction', 'system'], true) || blank($target->wamid)) {
            return self::failLocal('BAD_REACTION_TARGET', 'لا يمكن التفاعل مع هذه الرسالة.');
        }
        $days = (int) config('whatsapp.reaction_max_days', 30);
        if ($target->created_at === null || $target->created_at->lt(now()->subDays($days))) {
            return self::failLocal('REACTION_TOO_OLD', "لا يمكن التفاعل مع رسالة أقدم من {$days} يوماً");
        }

        $res = self::send($conv, Outbound\RichTypes::reaction((string) $target->wamid, $emoji), 'staff', $staffId,
            ['send_key' => $sendKey, 'as_reply' => false]);
        if (!$res['ok']) {
            return $res;
        }
        ReactionProjector::applyOutbound($target, $emoji, $staffId);
        \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaReactionSend, 'wa_message', $target->id, null,
            ['conversation_id' => $conv->id, 'removed' => $emoji === '']);

        return $res;
    }

    /** دبوس موقع معتمد (C006): الإحداثيات من صف wa_places وحده، وOutboundPolicy تطابق الحمولة مع الصف */
    public static function sendLocation(WaConversation $conv, \App\Models\WaPlace $place, string $source, ?int $staffId = null, array $opts = []): array
    {
        if (!\App\Support\FeatureFlags::on('wa_share_cards')) {
            return self::failLocal('FEATURE_OFF', 'إرسال الموقع غير مفعّل.');
        }
        if (!\App\Models\WaPlace::active()->whereKey($place->id)->exists()) {
            return self::failLocal('PLACE_INACTIVE', 'هذا الموقع غير فعّال.');
        }

        return self::send($conv, Outbound\RichTypes::location($place), $source, $staffId, [
            'send_key' => $opts['send_key'] ?? null,
            'reply_to' => $opts['reply_to'] ?? null,
            'meta' => ['place_id' => $place->id] + (array) ($opts['meta'] ?? []),
        ]);
    }

    /**
     * بطاقات اتصال معتمدة (C007): من 1 إلى 3 من wa_contact_cards الفعّالة. واتساب ويب يرسل بطاقة لكل رسالة، فتُرسل
     * الأولى بهذا النداء، وكل بطاقة بعدها رسالة مستقلة بمفتاح مشتق.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\WaContactCard>  $cards
     */
    public static function sendContacts(WaConversation $conv, \Illuminate\Support\Collection $cards, string $source, ?int $staffId = null, array $opts = []): array
    {
        if (!\App\Support\FeatureFlags::on('wa_share_cards')) {
            return self::failLocal('FEATURE_OFF', 'إرسال البطاقات غير مفعّل.');
        }
        $cards = $cards->values();
        if ($cards->isEmpty() || $cards->count() > \App\Models\WaContactCard::MAX_PER_MESSAGE) {
            return self::failLocal('BAD_CONTACTS', 'اختر من بطاقة إلى '.\App\Models\WaContactCard::MAX_PER_MESSAGE.' بطاقات.');
        }
        $ids = $cards->map(fn ($c) => $c instanceof \App\Models\WaContactCard ? (int) $c->id : 0)->all();
        if (\App\Models\WaContactCard::active()->whereIn('id', $ids)->count() !== count(array_unique($ids)) || count(array_unique($ids)) !== count($ids)) {
            return self::failLocal('CARD_INACTIVE', 'بطاقة غير فعّالة.');
        }
        $first = null;
        foreach ($cards as $i => $card) {
            $key = isset($opts['send_key']) && $opts['send_key'] !== null ? $opts['send_key'].($i > 0 ? ':'.$i : '') : null;
            $res = self::send($conv, Outbound\RichTypes::contacts(collect([$card])), $source, $staffId, [
                'send_key' => $key !== null ? mb_substr($key, 0, 64) : null,
                'reply_to' => $i === 0 ? ($opts['reply_to'] ?? null) : null,
                'meta' => ['card_ids' => [$ids[$i]]] + (array) ($opts['meta'] ?? []),
            ]);
            $first ??= $res;
            if (!$res['ok']) {
                return $res;
            }
        }
        if ($first['ok'] && empty($first['replayed'])) {
            \App\Models\AuditLog::safe(\App\Enums\AuditAction::WaShareCard, 'wa_conversation', $conv->id, null, ['card_ids' => $ids, 'source' => $source]);
        }

        return $first;
    }

    // ==================== إشعار القراءة ومؤشر الكتابة (E35) ====================

    /**
     * إشعار قراءة (العلامتان الزرقاوان) حتى أحدث وارد، ومعه «يكتب…» إن طُلب: POST /chats/read ثم /chats/typing.
     * ليس رسالة: لا صف في wa_messages ولا دفتر ولا سياسة. يحترم الأقفال 1–3 (الرقم والإيقاف والاتصال). لا يرمي،
     * والفشل سطر info وعدّاد يومي (ReadReceipts::failed) بلا تنبيه.
     *
     * @return array{ok: bool, code: ?string}
     */
    public static function markRead(WaConversation $conv, bool $typing = false, ?int $uptoMessageId = null): array
    {
        try {
            if (ReadReceipts::mode() === 'off') {
                return ['ok' => false, 'code' => 'DISABLED'];
            }
            $ch = self::channelFor($conv);
            if (!$ch) {
                return ['ok' => false, 'code' => self::ERR_NO_CHANNEL];
            }
            if (WaBotSetting::current()->sendingSuspended()) {
                return ['ok' => false, 'code' => self::ERR_SUSPENDED];
            }
            if ($ch->isPaused()) {
                return ['ok' => false, 'code' => self::ERR_PAUSED];
            }
            if (!$ch->isReady()) {
                return ['ok' => false, 'code' => self::ERR_NOT_READY];
            }
            if ($conv->status === 'merged') {
                return ['ok' => false, 'code' => 'MERGED'];
            }
            $chat = self::chatId($conv);
            $target = ReadReceipts::target($conv, $uptoMessageId);
            if (!$target || $chat === null) {
                return ['ok' => false, 'code' => 'NO_TARGET'];
            }
            if (!$typing && $target->id <= ReadReceipts::upto($conv)) {
                return ['ok' => false, 'code' => 'ALREADY'];
            }

            $res = self::transport()->call($ch, 'POST', '/chats/read', ['chatId' => $chat], 'receipt');
            if ($res->ok && $typing && ReadReceipts::typingEnabled()) {
                self::transport()->call($ch, 'POST', '/chats/typing', ['chatId' => $chat, 'state' => 'typing'], 'receipt');
            }
        } catch (\Throwable $e) {
            ReadReceipts::failed($conv, 'ERROR');

            return ['ok' => false, 'code' => 'ERROR'];
        }

        if (!$res->ok) {
            ReadReceipts::failed($conv, (string) $res->error?->code, null, null, $res->status());

            return ['ok' => false, 'code' => $res->error?->code];
        }
        ReadReceipts::advance($conv, (int) $target->id);

        return ['ok' => true, 'code' => null];
    }

    /** «يكتب…» وحده (بين ملفات الصوت الطويلة، ReadReceipts::renewTyping) */
    public static function typing(WaConversation $conv): bool
    {
        $ch = self::channelFor($conv);
        $chat = self::chatId($conv);
        if (!$ch || !$ch->isReady() || $chat === null || WaBotSetting::current()->sendingSuspended()) {
            return false;
        }

        return self::transport()->call($ch, 'POST', '/chats/typing', ['chatId' => $chat, 'state' => 'typing'], 'receipt')->ok;
    }

    // ==================== المغلف الموحّد والملفات (E32) ====================

    /**
     * المغلف الموحّد: كل صادر يمر هنا ثم dispatch() بأقفاله والسياسة والدفتر. الاقتباس من المحادثة نفسها وحدها.
     *
     * @param  array{context?: string, send_key?: ?string, media?: ?array, display?: string, row?: ?WaMessage, reply_to?: ?WaMessage, meta?: array, as_reply?: bool, type_key?: ?string}  $opts
     *   media: {mime, sha256, size, filename, path} للنسخة الخاصة على wa_media · row: صف محجوز بـ reserve() يُكمَل هو نفسه
     *   as_reply=false (تفاعل الموظف): لا يمس حقول «رد الموظف» · type_key: نوع رسالة النظام (wa_message_types) للعدّ
     * @return array{ok: bool, error: ?string, code: ?string, message_id: ?string}
     */
    public static function send(WaConversation $conv, Outbound\OutboundMessage $m, string $source = 'staff', ?int $staffId = null, array $opts = []): array
    {
        if ($fail = $m->validate()) {
            if (isset($opts['row'])) {
                self::failReserved($opts['row'], $fail);
            }

            return self::failLocal($fail['code'], $fail['error']);
        }
        if (($opts['reply_to'] ?? null) !== null) {
            if (!self::canQuote($conv, $opts['reply_to'])) {
                return self::failLocal('BAD_REPLY_TARGET', 'لا يمكن الاقتباس من هذه الرسالة.');
            }
            $reply = (string) $opts['reply_to']->wamid;
        } else {
            $reply = self::quotable($conv, $m->replyToWamid);
        }
        if ($m->type === 'reaction') {
            $reply = null;
        }

        return self::dispatch($conv, $m->payload($reply), $opts['display'] ?? $m->displayBody(), $m->type, $source, $staffId,
            $opts['context'] ?? 'reply', $opts['send_key'] ?? null, self::envelopeColumns($m, $opts['media'] ?? null, $reply), $opts['row'] ?? null,
            (array) ($opts['meta'] ?? []), (bool) ($opts['as_reply'] ?? true), $opts['type_key'] ?? null);
    }

    /** يُقتبس (E33): صف من المحادثة نفسها، أُرسل أو وصل بمعرّف، وليس تفاعلاً */
    public static function canQuote(WaConversation $conv, mixed $message): bool
    {
        return $message instanceof WaMessage && (int) $message->conversation_id === (int) $conv->id
            && filled($message->wamid) && $message->msg_type !== 'reaction';
    }

    /**
     * رسالة صوتية من الموظف (E34): audio بعلم voice (ptt). staff ثابت هنا، والبوت والنظام لا يرسلان صوتاً أبداً.
     *
     * @param  array{seconds?: float|int, send_key?: ?string, media?: ?array, row?: ?WaMessage}  $opts
     */
    public static function sendVoice(WaConversation $conv, string $mediaId, int $staffId, array $opts = []): array
    {
        return self::send($conv, Outbound\VoiceNote::message($mediaId, (float) ($opts['seconds'] ?? 0)), 'staff', $staffId,
            array_intersect_key($opts, array_flip(['send_key', 'media', 'row'])));
    }

    /** الأقفال كما في dispatch بلا إرسال: قبل حجز صف ملف، وفي الوظيفة المؤجلة قبل الإرسال. null: مسموح */
    public static function preflight(WaConversation $conv, string $source = 'staff', ?int $staffId = null): ?array
    {
        return self::gate($conv, WaBotSetting::current(), $source, $staffId)[0];
    }

    /**
     * حجز صف queued لملف يُرسل لاحقاً في الطابور (E32.7): الأقفال كلها، ثم الصف بالمفتاح والنسخة الخاصة.
     * المفتاح نفسه مرة ثانية لا يحجز ثانياً: replay يعيد حالة الصف الأول. رد الموظف يوقف البوت فوراً.
     *
     * @param  array{send_key?: ?string, media?: ?array, display?: string, payload?: array}  $opts
     */
    public static function reserve(WaConversation $conv, Outbound\OutboundMessage $m, string $source, ?int $staffId, array $opts = []): array
    {
        if ($fail = $m->validate(deferred: true)) {
            return self::failLocal($fail['code'], $fail['error']);
        }
        if (!$m->isMedia()) {
            return self::failLocal(self::ERR_UNSUPPORTED, 'الإرسال المؤجل للملفات وحدها.');
        }
        if ($source !== 'staff' && Outbound\VoiceNote::isVoicePayload($m->payload())) {
            return self::voiceRefused($conv, $source);
        }
        $s = WaBotSetting::current();
        [$fail, , $ch] = self::gate($conv, $s, $source, $staffId);
        if ($fail !== null) {
            return $fail;
        }

        $reply = self::quotable($conv, $m->replyToWamid);
        $display = $opts['display'] ?? $m->displayBody();
        $columns = self::envelopeColumns($m, $opts['media'] ?? null, $reply);
        $sendKey = $opts['send_key'] ?? null;
        $hash = self::reqHash($conv, $m->type, $display, $columns);
        try {
            $row = WaMessage::create($columns + [
                'conversation_id' => $conv->id, 'channel_id' => $ch->id, 'direction' => 'out', 'source' => $source, 'msg_type' => $m->type, 'body' => $display,
                'payload' => ['_req_hash' => $hash, '_deferred' => 'media', '_caption' => $m->caption] + ($opts['payload'] ?? []),
                'status' => WaMessage::STATUS_QUEUED, 'staff_id' => $staffId,
                'send_key' => $sendKey !== null ? mb_substr($sendKey, 0, 64) : null, 'attempts' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return self::replay((string) $sendKey, $hash);
        }
        WaLog::info('wa.send.reserved', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $m->type]);
        if ($source === 'staff') {
            $conv->update(['bot_paused_until' => now()->addMinutes(max(1, (int) $s->pause_minutes))]);
        }

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => null, 'meta_code' => null,
            'status' => WaMessage::STATUS_QUEUED, 'row_id' => $row->id, 'replayed' => false];
    }

    /**
     * «رفع» ملف قبل إرساله: البوابة تأخذ الملف base64 مع الرسالة نفسها، فلا رفع حقيقي. المعرّف «local:{مسار}» على قرص
     * wa_media الخاص: الملف نفسه إن كان عليه (نسخة الصندوق الخاصة)، وإلا نسخة في openwa-staging/ تبقى 30 يوماً
     * (whatsapp:media-prune). يمر بالأقفال أولاً فلا يبقى ملف لمحادثة موقوفة.
     *
     * @return array{ok: bool, media_id: ?string, code: ?string, error: ?string}
     */
    public static function uploadMedia(WaConversation $conv, string $path, string $mime, string $filename, string $source = 'staff', ?int $staffId = null): array
    {
        if ($fail = self::preflight($conv, $source, $staffId)) {
            return $fail + ['media_id' => null];
        }
        clearstatcache(true, $path);
        if (!is_file($path) || (int) filesize($path) <= 0) {
            return self::failLocal('MEDIA_FILE_MISSING', MetaError::local('MEDIA_FILE_MISSING')->staffMessage) + ['media_id' => null];
        }
        $disk = Media\MediaArchiver::disk();
        $root = rtrim(str_replace('\\', '/', (string) rescue(fn () => $disk->path(''), '', false)), '/');
        $norm = str_replace('\\', '/', (string) realpath($path));
        if ($root !== '' && str_starts_with($norm, $root.'/')) {
            return ['ok' => true, 'media_id' => 'local:'.substr($norm, strlen($root) + 1), 'code' => null, 'error' => null];
        }
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) ?: (explode('/', $mime)[1] ?? 'bin');
        $rel = 'openwa-staging/'.now()->format('Y/m').'/'.\Illuminate\Support\Str::uuid7().'.'.preg_replace('/[^a-z0-9]/', '', $ext);
        try {
            $stream = fopen($path, 'rb');
            $disk->writeStream($rel, $stream);
        } catch (\Throwable $e) {
            WaLog::error('wa.media.stage_failed', ['conv_id' => $conv->id, 'type' => class_basename($e)]);

            return self::failLocal('MEDIA_SEND_FAILED', MetaError::local('MEDIA_SEND_FAILED')->staffMessage) + ['media_id' => null];
        } finally {
            isset($stream) && is_resource($stream) && fclose($stream);
        }

        return ['ok' => true, 'media_id' => 'local:'.$rel, 'code' => null, 'error' => null];
    }

    /** مهلة الإرسال بملف: 30 ثانية + ثانية لكل 256KB، بحد يبقي وظيفة الطابور (120 ثانية) فوقها */
    public static function uploadTimeout(int $bytes): int
    {
        return (int) min((int) config('whatsapp.outbound.upload_timeout_max', 85), 30 + intdiv(max(0, $bytes), 262144));
    }

    /**
     * حسم صف محجوز لم يُرسل (E32): قفل، أو ملف مرفوض أو مفقود، أو تأخر الطابور. failed والمفتاح محرَّر، بشرط أن يبقى
     * queued ولم تبدأ محاولة إرسال (attempts=0)، فلا يكتب فوق ما حسمه غيره.
     */
    public static function failReserved(WaMessage $row, array $fail): bool
    {
        $code = mb_substr((string) ($fail['code'] ?? 'MEDIA_SEND_FAILED'), 0, 32);
        $meaning = MetaErrorCatalog::byCode($code);
        $current = WaMessage::find($row->id);
        if (!$current || $current->status !== WaMessage::STATUS_QUEUED || (int) $current->attempts !== 0) {
            return false;
        }
        $key = $current->send_key;
        $payload = ['_meta_error' => ['code' => $code]] + ($key ? ['_released_key' => $key] : []) + (array) ($current->payload ?? []);
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->where('attempts', 0)->update([
            'status' => WaMessage::STATUS_FAILED, 'send_key' => null, 'error_code' => $code, 'error_category' => $meaning['category'] ?? null,
            'error_message' => mb_substr((string) ($fail['error'] ?? MetaError::local($code)->staffMessage), 0, 500),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        if ($moved > 0) {
            WaLog::warning('wa.send_failed', ['conv_id' => $row->conversation_id, 'message_id' => $row->id, 'source' => $row->source, 'type' => $row->msg_type,
                'error_code' => $code, 'category' => $meaning['category'] ?? null, 'attempt' => 0, 'status' => 'failed']);
        }

        return $moved > 0;
    }

    /**
     * إعادة إرسال صف فاشل في مكانه (صفحة الإخفاقات): الصف نفسه يعود queued بالمفتاح المحرَّر (payload._released_key)،
     * ثم dispatch بأقفاله والسياسة والدفتر. Idempotency-Key جديد للبوابة (nj-m-{id}-r{n}). لا صف جديد.
     *
     * @return array نتيجة dispatch، أو REDRIVE_UNSUPPORTED / REDRIVE_STALE / REDRIVE_KEY_TAKEN
     */
    public static function redrive(WaMessage $row, ?int $byUserId = null): array
    {
        $payload = (array) ($row->payload ?? []);
        $key = $payload['_released_key'] ?? null;
        $type = $payload['type'] ?? null;
        if (!is_string($key) || $key === '' || !is_string($type) || !isset($payload[$type])) {
            return self::fail('REDRIVE_UNSUPPORTED', 'لا يمكن إعادة هذه الرسالة من هنا — أعد إرسالها من المحادثة.');
        }
        $graph = array_diff_key(
            array_filter($payload, fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
            array_flip(['messaging_product', 'to', 'recipient', 'recipient_type', 'statusHistory', 'biz_opaque_callback_data']),
        );
        $previous = (array) ($payload['_redrive'] ?? []);
        $requeued = [
            '_redrive' => ['n' => (int) ($previous['n'] ?? 0) + 1, 'prev_attempts' => (int) $row->attempts, 'by' => $byUserId, 'at' => now()->toIso8601String()],
        ] + array_diff_key($payload, array_flip(['_released_key', '_meta_error', '_reaped', '_stale']));
        try {
            $moved = WaMessage::whereKey($row->id)->where('direction', 'out')->where('status', WaMessage::STATUS_FAILED)->whereNull('send_key')->update([
                'status' => WaMessage::STATUS_QUEUED, 'send_key' => mb_substr($key, 0, 64), 'attempts' => 0,
                'error_message' => null, 'error_code' => null, 'error_category' => null, 'meta_code' => null, 'fbtrace_id' => null,
                'payload' => json_encode($requeued, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return self::fail('REDRIVE_KEY_TAKEN', 'أُرسلت نسخة أخرى من هذه الرسالة — افتح المحادثة.') + ['conflict' => true];
        }
        if ($moved === 0) {
            return self::fail('REDRIVE_STALE', 'تغيّرت حالة الرسالة — حدّث الصفحة.') + ['conflict' => true];
        }
        $fresh = WaMessage::find($row->id);
        WaLog::info('wa.send.redrive', ['conv_id' => $fresh->conversation_id, 'message_id' => $fresh->id, 'source' => $fresh->source, 'type' => $fresh->msg_type]);

        return self::dispatch($fresh->conversation, $graph, (string) $fresh->body, (string) $fresh->msg_type, (string) $fresh->source,
            $fresh->staff_id !== null ? (int) $fresh->staff_id : null, 'reply', $key, [], $fresh);
    }

    /** الاقتباس من المحادثة نفسها وحدها: معرّف من محادثة أخرى أو مجهول يُسقط بسطر سجل */
    private static function quotable(WaConversation $conv, ?string $wamid): ?string
    {
        if ($wamid === null || $wamid === '') {
            return null;
        }
        if (WaMessage::where('conversation_id', $conv->id)->where('wamid', $wamid)->exists()) {
            return $wamid;
        }
        WaLog::notice('wa.reply_to.dropped', ['conv_id' => $conv->id]);

        return null;
    }

    /** أعمدة الصادر من المغلف: الاقتباس، والنسخة الخاصة على wa_media بحالة stored */
    private static function envelopeColumns(Outbound\OutboundMessage $m, ?array $media, ?string $reply): array
    {
        $out = $reply !== null ? ['reply_to_wamid' => $reply] : [];
        if (!$m->isMedia()) {
            return $out;
        }
        $media = (array) $media;
        $path = isset($media['path']) && $media['path'] !== '' ? (string) $media['path'] : null;

        return $out + array_filter([
            'media_id' => $m->mediaId,
            'media_mime' => $media['mime'] ?? null,
            'media_sha256' => $media['sha256'] ?? null,
            'media_size' => isset($media['size']) ? (int) $media['size'] : null,
            'media_filename' => isset($media['filename']) ? mb_substr((string) $media['filename'], 0, 255) : $m->filename,
            'media_path' => $path,
            'media_status' => $path !== null ? 'stored' : null,
            'media_archived_at' => $path !== null ? now() : null,
        ], fn ($v) => $v !== null);
    }

    /** بصمة الطلب لمفتاح الموظف: المحادثة والنوع والنص، وبصمة الملف للوسائط */
    private static function reqHash(WaConversation $conv, string $msgType, string $displayBody, array $columns = []): string
    {
        return hash('sha256', $conv->id.'|'.$msgType.'|'.$displayBody.(isset($columns['media_sha256']) ? '|'.$columns['media_sha256'] : ''));
    }

    // ==================== الحظر (E31) ====================

    /** هدف الحظر على البوابة: jid المحادثة */
    public static function blockTarget(WaConversation $c): ?array
    {
        $chat = self::chatId($c);

        return $chat !== null ? ['jid' => $chat] : null;
    }

    /**
     * POST /contacts/{jid}/block على رقم المحادثة. القفلان 1 و2. لا إعادة تلقائية لأي رفض. يُستدعى من BlockService وحده.
     *
     * @return array{ok: bool, code: ?string, error: ?string, meta_code: ?int}
     */
    public static function blockUsers(WaConversation $c): array
    {
        return self::blockCall('POST', $c);
    }

    /** DELETE /contacts/{jid}/block. من محادثة أو صف حظر (رقمه ومحادثته) */
    public static function unblockUsers(WaConversation|\App\Models\WaBlock|array $x): array
    {
        $conv = match (true) {
            $x instanceof WaConversation => $x,
            $x instanceof \App\Models\WaBlock => $x->conversation_id ? WaConversation::find($x->conversation_id) : null,
            default => isset($x['conversation_id']) ? WaConversation::find($x['conversation_id']) : null,
        };
        if ($conv === null) {
            return self::fail(self::ERR_NO_RECIPIENT, 'لا محادثة لهذا الحظر — فكّه من هاتف الرقم.');
        }

        return self::blockCall('DELETE', $conv);
    }

    /**
     * المحظورون على كل رقم (GET /contacts/blocked) للمزامنة القرائية وحدها.
     *
     * @return array{ok: bool, code: ?string, error: ?string, data: list<array{phone?: string, jid?: string, channel_id?: int}>, next_after: ?string}
     */
    public static function listBlocked(?string $after = null): array
    {
        $rows = [];
        foreach (WaChannel::query()->active()->get() as $ch) {
            if (!$ch->isReady()) {
                continue;
            }
            $res = self::transport()->call($ch, 'GET', '/contacts/blocked');
            if (!$res->ok) {
                return self::fail((string) $res->error?->code, (string) $res->error?->toMetaError()->staffMessage) + ['data' => [], 'next_after' => null];
            }
            $list = $res->json('data', $res->json());
            foreach ((array) $list as $u) {
                $jid = is_string($u) ? $u : (string) data_get($u, 'id', data_get($u, 'jid', ''));
                $digits = OpenWa\InboundParser::digits($jid) ?: preg_replace('/\D/', '', (string) data_get($u, 'number', '')) ?: '';
                if ($jid !== '' || $digits !== '') {
                    $rows[] = array_filter(['phone' => $digits ?: null, 'jid' => $jid ?: null, 'channel_id' => (int) $ch->id]);
                }
            }
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'data' => $rows, 'next_after' => null];
    }

    private static function blockCall(string $method, WaConversation $c): array
    {
        $ch = self::channelFor($c);
        if (!$ch) {
            return self::fail(self::ERR_NO_CHANNEL, 'لا رقم واتساب لهذه المحادثة.');
        }
        if (WaBotSetting::current()->sendingSuspended()) {
            return self::fail(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا حظر ولا فك حتى يُرفع.');
        }
        if (!$ch->isReady()) {
            return self::fail(self::ERR_NOT_READY, MetaError::local(self::ERR_NOT_READY)->staffMessage);
        }
        $chat = self::chatId($c);
        if ($chat === null) {
            return self::fail(self::ERR_NO_RECIPIENT, 'لا معرّف محادثة لهذا العميل.');
        }
        $op = $method === 'POST' ? 'block' : 'unblock';
        $res = self::transport()->call($ch, $method, '/contacts/'.rawurlencode($chat).'/block');
        if ($res->ok) {
            WaLog::info('wa.block.ok', ['conv_id' => $c->id, 'type' => $op, 'channel_id' => $ch->id]);

            return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => null, 'meta_code' => null];
        }
        $err = $res->error?->toMetaError() ?? MetaError::gateway('GW_ERROR');
        WaLog::warning('wa.block_failed', ['conv_id' => $c->id, 'type' => $op, 'error_code' => $err->code, 'http' => $res->status()]);

        return self::fail($err->code, $err->staffMessage) + ['category' => $err->category];
    }

    // ==================== المحرّك ====================

    /** معرّف المحادثة على البوابة: jid المخزّن، وإلا الهاتف @c.us */
    public static function chatId(WaConversation $conv): ?string
    {
        if (filled($conv->jid)) {
            return (string) $conv->jid;
        }
        $digits = PhoneNormalizer::digits((string) ($conv->wa_phone ?: $conv->phone));

        return $digits !== '' ? $digits.'@c.us' : null;
    }

    /**
     * @param  array  $columns  أعمدة المغلف: الاقتباس وmedia_* للصادر
     * @param  WaMessage|null  $reserved  صف حجزه reserve() (ملف مؤجل): يُكمَل هو نفسه بالمفتاح نفسه بدل صف جديد
     */
    private static function dispatch(
        WaConversation $conv,
        array $payload,
        string $displayBody,
        string $msgType,
        string $source,
        ?int $staffId = null,
        string $context = 'reply',
        ?string $sendKey = null,
        array $columns = [],
        ?WaMessage $reserved = null,
        array $meta = [],
        bool $asReply = true,
        ?string $typeKey = null
    ): array {
        // الرسالة الصوتية (E34) من الموظف وحده: لا يرسل البوت ولا النظام صوتاً أياً كان المسار
        if ($source !== 'staff' && Outbound\VoiceNote::isVoicePayload($payload)) {
            if ($reserved !== null) {
                self::failReserved($reserved, ['code' => self::ERR_UNSUPPORTED, 'error' => 'الرسائل الصوتية من الموظف وحده.']);
            }

            return self::voiceRefused($conv, $source);
        }
        $s = WaBotSetting::current();

        [$fail, $chat, $ch] = self::gate($conv, $s, $source, $staffId);
        if ($fail !== null) {
            if ($reserved !== null) {
                self::failReserved($reserved, $fail);
            }

            return $fail;
        }
        $payload = ['messaging_product' => 'whatsapp', 'to' => $chat] + $payload;

        // معاينة الرابط (E33): هنا وحدها تُقرَّر، لرد الموظف بالعلم wa_link_preview ولرابط عام تقبله LinkPreviewPolicy
        if (isset($payload['text']) && is_array($payload['text']) && array_key_exists('preview_url', $payload['text'])) {
            $payload['text']['preview_url'] = $payload['text']['preview_url'] === true && $source === 'staff'
                && \App\Support\FeatureFlags::on('wa_link_preview') && LinkPreviewPolicy::allows((string) ($payload['text']['body'] ?? ''));
        }

        // الدبوس والبطاقة (E33): من الجدولين المعتمدين حرفياً، قبل أي HTTP
        if ($why = Outbound\OutboundPolicy::structured($payload, $meta)) {
            WaLog::warning('wa.policy', ['conv_id' => $conv->id, 'source' => $source, 'policy_reason' => $why, 'type' => $msgType]);

            return self::failLocal('POLICY_'.strtoupper($why), 'رُفضت الرسالة: '.Outbound\OutboundPolicy::describe([$why]).'.');
        }

        // سياسة المحتوى الصادر (E15.9). القرار يُحفظ مع الرسالة (payload._policy) ولا يُرسل
        $decision = Outbound\OutboundPolicy::evaluate($conv, $payload, $displayBody, $source, $context);
        $stored = $payload;
        if ($meta !== []) {
            $stored['_meta'] = $meta;
        }
        if ($decision->reasons || $decision->warnings) {
            $enforce = $source !== 'staff' && Outbound\OutboundPolicy::enforced();
            WaLog::warning('wa.policy', ['conv_id' => $conv->id, 'source' => $source, 'policy_reason' => implode(',', $decision->reasons ?: $decision->warnings)]);
            if ($enforce && $decision->blocks()) {
                if ($reserved !== null) {
                    self::failReserved($reserved, ['code' => 'POLICY_BLOCKED', 'error' => 'حُجبت الرسالة قبل الإرسال بسياسة المحتوى الصادر.']);
                }

                return self::blockByPolicy($conv, $ch, $decision, $payload, $displayBody, $msgType, $source, $staffId, $reserved ? [] : $columns);
            }
            $stored['_policy'] = ['mode' => $enforce ? 'enforce' : 'log', 'context' => $context] + $decision->toArray();
        }

        return self::ledger($conv, $ch, $s, $payload, $stored, $displayBody, $msgType, $source, $staffId, $sendKey, $columns, $reserved, $asReply, $typeKey);
    }

    /**
     * الأقفال بالترتيب (رأس الملف). المسار السريع للردود والإرسال الاستباقي معاً: حدود الأنواع في SendGovernor قبل هنا.
     *
     * @return array{0: ?array, 1: ?string, 2: ?WaChannel} [نتيجة الفشل، jid الوجهة، الرقم]
     */
    private static function gate(WaConversation $conv, WaBotSetting $s, string $source, ?int $staffId): array
    {
        if ($source === 'staff') {
            $staff = $staffId ? \App\Models\User::find($staffId) : null;
            if (!$staff || !TopicRouter::canSee($conv, $staff)) {
                return [self::failLocal(self::ERR_OUT_OF_SCOPE, 'هذه المحادثة خارج نطاقك — لا يمكنك مراسلة هذا العميل.'), null, null];
            }
        }
        // المجموعة (GroupChats): رد الموظف وحده. لا بوت ولا رسائل نظام ولا إشعار ملفات ولا أي مصدر آلي
        if ($conv->isGroup() && $source !== 'staff') {
            return [self::failLocal(self::ERR_GROUP_STAFF_ONLY, 'المجموعات يرد عليها الموظفون وحدهم.'), null, null];
        }
        $ch = self::channelFor($conv);
        if ($ch === null) {
            return [self::failLocal(self::ERR_NO_CHANNEL, 'لا رقم واتساب مربوطاً لهذه المحادثة.'), null, null];
        }
        if ($s->sendingSuspended()) {
            return [self::failLocal(self::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ).'), null, $ch];
        }
        if ($ch->isPaused()) {
            return $ch->pause_reason === 'restriction'
                ? [self::failLocal('CHANNEL_RESTRICTED', MetaError::local('CHANNEL_RESTRICTED')->staffMessage), null, $ch]
                : [self::failLocal(self::ERR_PAUSED, 'رقم الواتساب «'.$ch->name.'» موقوف مؤقتاً.'), null, $ch];
        }
        if (!$ch->isReady()) {
            return [self::failLocal(self::ERR_NOT_READY, 'رقم الواتساب «'.$ch->name.'» غير متصل الآن، ولم تُرسل الرسالة.'), null, $ch];
        }
        if (FailureBreaker::open($ch)) {
            return [self::failLocal(self::ERR_NOT_READY, 'أُوقف الإرسال من «'.$ch->name.'» دقائق بعد إخفاقات متتالية.'), null, $ch];
        }
        // المحظور (E31): بلا نداء ولا صف
        if ($conv->isBlocked()) {
            return [self::failLocal(self::ERR_BLOCKED, 'هذا العميل محظور — فكّ الحظر أولاً من صفحة المحظورين.'), null, $ch];
        }
        $chat = self::chatId($conv);
        if ($chat === null) {
            return [self::failLocal(self::ERR_NO_RECIPIENT, 'لا رقم ولا معرّف محادثة لهذا العميل.'), null, $ch];
        }

        return [null, $chat, $ch];
    }

    /**
     * بعد قبول البوابة (أو مهلة رسالة الموظف): وقت المحادثة ومعاينتها، وردّ الموظف يوقف البوت مؤقتاً ويصفّر المؤشرات.
     * $asReply=false (تفاعل الموظف): إقرار لا رد، فلا يُسكت البوت ولا يمس المعاينة والعلامة.
     */
    private static function afterSent(WaConversation $conv, WaBotSetting $s, string $source, string $displayBody, bool $asReply = true): void
    {
        if (!$asReply) {
            return;
        }
        $update = [
            'last_outbound_at' => now(),
            'last_message_at' => now(),
            'last_message_preview' => mb_substr(trim($displayBody), 0, 160),
        ];
        if ($source === 'staff') {
            $update['bot_paused_until'] = now()->addMinutes(max(1, (int) $s->pause_minutes));
            $update['unread_count'] = 0;
            $update += InboxFollowup::available() ? ['marked_unread_at' => null, 'marked_unread_by' => null] : [];
            $update['needs_attention'] = false;
            if (ReplyState::available()) {
                $update['attention_reason'] = null;
            }
        }
        $conv->update($update);

        if ($source === 'staff') {
            ReplyState::advanceToNewest($conv);
        }
    }

    // ==================== دفتر الإرسال (E19) ====================

    /** مفتاح البوابة للصف: ASCII مرئي حتى 255 (Idempotency-Key)، ومختلف لكل إعادة من صفحة الإخفاقات */
    public static function idempotencyKey(WaMessage $row): string
    {
        $n = (int) data_get($row->payload, '_redrive.n', 0);

        return config('whatsapp.send.opaque_prefix', 'nj').'-m-'.$row->id.($n > 0 ? '-r'.$n : '');
    }

    /**
     * الصف queued يُكتب بعد كل الأقفال وقبل النداء، ثم يُحسم الصف نفسه: sent أو failed (المفتاح محرَّر) أو unknown (محجوز).
     * لا معاملة تلف نداء HTTP. حسم الحالة مشروط بـ status='queued' فلا يكتب فوق ما حسمه webhook.
     * الإعادة (العلم send_retry) لما لم يغادر البوابة وحده (تعذّر الاتصال، الرقم لم يجهز، حد البوابة)، بتأخير 4^X وإعادة
     * الأقفال قبل كل محاولة. لا إعادة بعد مهلة الطلب ولا بعد خطأ من المحرك: ربما خرجت.
     */
    private static function ledger(WaConversation $conv, WaChannel $ch, WaBotSetting $s, array $payload, array $stored, string $displayBody,
        string $msgType, string $source, ?int $staffId, ?string $sendKey, array $columns = [], ?WaMessage $reserved = null, bool $asReply = true,
        ?string $typeKey = null): array
    {
        $hash = self::reqHash($conv, $msgType, $displayBody, $columns);
        $run = ReplyRun::current();
        $extra = array_filter(['type_key' => $typeKey !== null && self::hasTypeKey() ? mb_substr($typeKey, 0, 40) : null]);
        if ($reserved !== null) {
            $row = $reserved;
            $keep = array_diff_key(array_filter((array) ($reserved->payload ?? []), fn ($k) => str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY), ['_caption' => 1]);
            $claimed = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->where('attempts', 0)->update($columns + $extra + [
                'payload' => json_encode($stored + $keep, JSON_UNESCAPED_UNICODE), 'body' => $displayBody, 'attempts' => 1, 'channel_id' => $ch->id,
            ]);
            if ($claimed === 0) {
                WaLog::warning('wa.send.reserved_lost', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType]);

                return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage)
                    + ['status' => 'queued', 'row_id' => $row->id, 'replayed' => false, 'conflict' => true];
            }
            $row->refresh();
            $columns = array_filter($row->only(['media_path', 'media_mime', 'media_filename']), fn ($v) => $v !== null) + $columns;
        } else {
            try {
                $row = WaMessage::create($columns + $extra + [
                    'conversation_id' => $conv->id, 'channel_id' => $ch->id, 'direction' => 'out', 'source' => $source, 'msg_type' => $msgType,
                    'body' => $displayBody, 'payload' => $stored + ['_req_hash' => $hash], 'status' => WaMessage::STATUS_QUEUED,
                    'staff_id' => $staffId, 'send_key' => $sendKey !== null ? mb_substr($sendKey, 0, 64) : null,
                    'answers_in_id' => $source !== 'staff' && $run?->uptoId ? $run->uptoId : null, 'attempts' => 0,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return self::replay((string) $sendKey, $hash);
            }
        }
        WaLog::info('wa.send.queued', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType, 'channel_id' => $ch->id]);

        $maxRetries = \App\Support\FeatureFlags::on('send_retry')
            ? (int) config($source === 'staff' ? 'whatsapp.send.retry_staff_max' : 'whatsapp.send.retry_system_max', 1) : 0;
        $retries = 0;
        $slept = 0.0;
        $quoteDropped = false;
        $chat = (string) $payload['to'];
        for ($attempt = 1; ; $attempt++) {
            WaMessage::whereKey($row->id)->update(['attempts' => $attempt]);
            try {
                [$path, $body] = PayloadMapper::map($payload, $chat, $columns);
            } catch (MapperException $e) {
                $err = MetaError::local($e->errorCode);

                return self::resolveFailed($row, $conv, $ch, $err, $sendKey, $source, $msgType, $attempt, null, $err->staffMessage);
            }
            $timeout = isset($body['base64']) ? 'send' : 'default';
            $res = self::transport()->call($ch, 'POST', $path, $body, $timeout, ['Idempotency-Key' => self::idempotencyKey($row)]);
            unset($body);

            if ($res->ok) {
                return self::resolveAccepted($row, $conv, $ch, $s, $res, $source, $displayBody, $msgType, $attempt, $asReply);
            }
            $gw = $res->error ?? OpenWaError::local('GW_ERROR');

            // الرسالة المقتبسة لم تعد على الهاتف: الصف نفسه يُعاد مرة واحدة بلا اقتباس، بلا انتظار ولا من حصة الإعادات
            if ($gw->code === 'QUOTE_NOT_FOUND' && !$quoteDropped && isset($payload['context'])) {
                unset($payload['context']);
                $quoteDropped = true;
                $fresh = (array) (WaMessage::find($row->id)?->payload ?? []);
                unset($fresh['context']);
                WaMessage::whereKey($row->id)->update(['payload' => json_encode($fresh + ['_context_dropped' => true], JSON_UNESCAPED_UNICODE), 'reply_to_wamid' => null]);
                WaLog::info('wa.send.context_dropped', ['conv_id' => $conv->id, 'message_id' => $row->id]);

                continue;
            }

            $err = $gw->toMetaError();
            if (!$gw->notSent) {
                return self::resolveUnknown($row, $conv, $ch, $s, $err, $source, $displayBody, $msgType, $attempt, $res->status(), $asReply);
            }

            $delay = self::retryDelay($gw, $retries, $maxRetries, $slept, $source);
            if ($delay === null) {
                return self::resolveFailed($row, $conv, $ch, $err, $sendKey, $source, $msgType, $attempt, $res->status());
            }

            WaLog::info('wa.send.retry', ['message_id' => $row->id, 'attempt' => $attempt, 'error_code' => $gw->code, 'category' => $err->category]);
            \Illuminate\Support\Sleep::for((int) round($delay * 1000))->milliseconds();
            $slept += $delay;
            $retries++;

            // قبل كل محاولة: الأقفال كلها من القاعدة الآن، وللبوت هل ما زال الرد مناسباً
            $conv->refresh();
            $ch->refresh();
            $s = WaBotSetting::current();
            [$fail] = self::gate($conv, $s, $source, $staffId);
            $stale = $fail === null && $source !== 'staff' ? $run?->mayDeliver($conv) : null;
            if ($fail !== null || $stale !== null) {
                $local = MetaError::local($fail['code'] ?? 'REPLY_STALE');

                return self::resolveFailed($row, $conv, $ch, $local, $sendKey, $source, $msgType, $attempt, null, $fail['error'] ?? $local->staffMessage, array_filter(['_stale' => $stale]));
            }
        }
    }

    /** التأخير قبل المحاولة التالية (ثوانٍ)، أو null: لا إعادة. Retry-After من البوابة إن وصل */
    private static function retryDelay(OpenWaError $gw, int $retries, int $maxRetries, float $slept, string $source): ?float
    {
        if ($retries >= $maxRetries || !in_array($gw->code, ['GW_UNREACHABLE', 'CHANNEL_NOT_READY', 'GW_RATE', 'IDEMPOTENCY_IN_PROGRESS'], true)) {
            return null;
        }
        $delay = $gw->retryAfter !== null ? (float) $gw->retryAfter : (4 ** $retries) + random_int(0, 250) / 1000;
        if ($slept + $delay > (float) config('whatsapp.send.retry_max_sleep', 8)) {
            return null;
        }
        $run = ReplyRun::current();
        if ($run && $source !== 'staff' && $run->remaining() <= $delay + 30) {
            return null;
        }

        return $delay;
    }

    /** 2xx: sent بمعرّف البوابة، أو unknown إن لم يأتِ معرّف */
    private static function resolveAccepted(WaMessage $row, WaConversation $conv, WaChannel $ch, WaBotSetting $s, OpenWaResponse $res, string $source,
        string $displayBody, string $msgType, int $attempt, bool $asReply = true): array
    {
        $wamid = $res->json('messageId') ?? $res->json('id') ?? $res->json('message.id');
        if (!is_string($wamid) || $wamid === '') {
            return self::resolveUnknown($row, $conv, $ch, $s, MetaError::local('OUTCOME_UNKNOWN'), $source, $displayBody, $msgType, $attempt, $res->status(), $asReply);
        }
        $wamid = mb_substr($wamid, 0, 128);
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update(['status' => 'sent', 'wamid' => $wamid, 'sent_at' => now()]);
        if ($moved === 0) {
            // سبق الصدى أو الإيصال فرفع الحالة: يُكمَل المعرّف وحده
            WaMessage::whereKey($row->id)->whereNull('wamid')->update(['wamid' => $wamid, 'sent_at' => now()]);
        }
        WaLog::info('wa.send.ok', ['conv_id' => $conv->id, 'message_id' => $row->id, 'wamid' => $wamid, 'source' => $source, 'type' => $msgType, 'attempt' => $attempt, 'channel_id' => $ch->id]);
        self::afterWamid($row, $wamid, $source, $row->staff_id !== null ? (int) $row->staff_id : null);
        FailureBreaker::success($ch);
        self::afterSent($conv, $s, $source, $displayBody, $asReply);
        ReadReceipts::queueAfterReply($conv, ReadReceipts::replyUpto($conv, $source, $msgType, $row->id));

        return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $wamid, 'meta_code' => null,
            'status' => 'sent', 'row_id' => $row->id, 'replayed' => false];
    }

    /** رفض صريح، أو فشل مؤكد قبل الإرسال، أو قفل بعد إعادة الفحص: failed، والمفتاح محرَّر لإعادة مشروعة */
    private static function resolveFailed(WaMessage $row, WaConversation $conv, WaChannel $ch, MetaError $err, ?string $sendKey, string $source, string $msgType,
        int $attempt, ?int $http, ?string $message = null, array $extra = []): array
    {
        $shown = $message ?? $err->staffMessage;
        $payload = (array) (WaMessage::find($row->id)?->payload ?? []);
        $payload = $extra + ['_meta_error' => $err->forPayload()] + ($sendKey !== null ? ['_released_key' => $sendKey] : []) + $payload;
        $moved = WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update([
            'status' => WaMessage::STATUS_FAILED, 'send_key' => null, 'error_message' => $shown,
            'error_code' => mb_substr($err->code, 0, 32), 'error_category' => $err->category,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        if ($moved > 0) {
            self::emitFailed($row, $err, $source, $row->staff_id !== null ? (int) $row->staff_id : null);
        }
        WaLog::warning('wa.send_failed', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType,
            'error_code' => $err->code, 'category' => $err->category, 'http' => $http, 'attempt' => $attempt, 'status' => 'failed', 'channel_id' => $ch->id]);
        if ($err->type === 'openwa' && $err->scope === 'channel') {
            FailureBreaker::failure($ch, $err->code);
        }

        return self::failWith($err, $err->code, $shown) + ['status' => 'failed', 'row_id' => $row->id, 'replayed' => false];
    }

    /** لا نعرف هل وصلت: unknown والمفتاح محجوز (لا يكرره أحد). رسالة الموظف توقف البوت كأنها أُرسلت */
    private static function resolveUnknown(WaMessage $row, WaConversation $conv, WaChannel $ch, WaBotSetting $s, MetaError $err, string $source,
        string $displayBody, string $msgType, int $attempt, ?int $http, bool $asReply = true): array
    {
        WaMessage::whereKey($row->id)->where('status', WaMessage::STATUS_QUEUED)->update([
            'status' => WaMessage::STATUS_UNKNOWN, 'error_message' => $err->staffMessage, 'error_code' => mb_substr($err->code, 0, 32), 'error_category' => $err->category,
        ]);
        WaLog::warning('wa.send.unknown', ['conv_id' => $conv->id, 'message_id' => $row->id, 'source' => $source, 'type' => $msgType,
            'error_code' => $err->code, 'http' => $http, 'attempt' => $attempt, 'status' => 'unknown', 'channel_id' => $ch->id]);
        FailureBreaker::failure($ch, $err->code);
        if ($source === 'staff') {
            self::afterSent($conv, $s, $source, $displayBody, $asReply);
        }

        return self::failWith($err, $err->code, $err->staffMessage) + ['status' => 'unknown', 'unknown' => true, 'row_id' => $row->id, 'replayed' => false];
    }

    /**
     * المفتاح محجوز بصف سابق: أُرسل (يعود replayed بلا نداء)، أو قيد الإرسال، أو غير مؤكد. الصف failed لا يصطدم لأن مفتاحه حُرّر.
     * نص مختلف بمفتاح الموظف نفسه يُرفض (خلل في المنشئ). أما المفتاح الآلي فحالة صفه تحكم.
     */
    private static function replay(string $sendKey, string $hash): array
    {
        $existing = WaMessage::where('send_key', $sendKey)->first();
        if (!$existing) {
            return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage) + ['status' => 'queued', 'replayed' => false, 'conflict' => true];
        }
        if (str_starts_with($sendKey, 'staff:') && data_get($existing->payload, '_req_hash') !== $hash) {
            return self::failLocal('IDEMPOTENCY_MISMATCH', 'مفتاح الإرسال مستعمل لنص آخر — اضغط «إرسال» مرة أخرى.') + ['status' => 'mismatch', 'replayed' => false, 'conflict' => true];
        }
        WaLog::info('wa.send.replayed', ['conv_id' => $existing->conversation_id, 'message_id' => $existing->id, 'status' => $existing->status]);
        if (in_array($existing->status, ['sent', 'delivered', 'read', 'played'], true)) {
            return ['ok' => true, 'error' => null, 'code' => null, 'message_id' => $existing->wamid, 'meta_code' => null,
                'status' => 'replayed', 'row_id' => $existing->id, 'replayed' => true];
        }
        if ($existing->status === WaMessage::STATUS_QUEUED) {
            return self::failLocal('SEND_IN_PROGRESS', MetaError::local('SEND_IN_PROGRESS')->staffMessage) + ['status' => 'queued', 'row_id' => $existing->id, 'replayed' => false, 'conflict' => true];
        }

        return self::failLocal('OUTCOME_UNKNOWN', 'قد تكون الرسالة وصلت. تحقق من الخيط قبل إعادة الإرسال.')
            + ['status' => 'unknown', 'unknown' => true, 'row_id' => $existing->id, 'replayed' => false, 'conflict' => true];
    }

    /**
     * رسالة آلية حجبتها السياسة: لا نداء، والمحفوظ نص بديل وبصمة sha256 للأصل وحدها، والمحادثة «تحتاج تدخّلاً».
     */
    private static function blockByPolicy(WaConversation $conv, WaChannel $ch, Outbound\Decision $decision, array $payload, string $displayBody,
        string $msgType, string $source, ?int $staffId, array $columns = []): array
    {
        $label = Outbound\OutboundPolicy::describe($decision->reasons);
        $hash = hash('sha256', implode("\n", Outbound\OutboundPolicy::texts($payload, $displayBody)));
        WaMessage::create($columns + [
            'conversation_id' => $conv->id, 'channel_id' => $ch->id, 'direction' => 'out', 'source' => $source, 'msg_type' => $msgType,
            'body' => "[حُجبت رسالة آلية: {$label}]", 'status' => 'blocked',
            'error_message' => "حُجبت قبل الإرسال بسياسة المحتوى الصادر ({$label})، ولم تصل العميل.", 'staff_id' => $staffId,
            'payload' => ['_policy' => ['mode' => 'enforce', 'reasons' => $decision->reasons, 'sha256' => $hash]],
            'error_code' => mb_substr($decision->errorCode(), 0, 32), 'error_category' => MetaErrorCatalog::byCode($decision->errorCode())['category'] ?? null,
        ]);
        ReplyState::markUnserved($conv, 'policy_block');

        return self::failLocal('POLICY_BLOCKED', 'حُجبت الرسالة قبل الإرسال بسياسة المحتوى الصادر.');
    }

    // ==================== الأحداث الداخلية (E21) ====================

    /** بعد كتابة wamid على الصف: إيصال سبقه يُطبَّق الآن (OrphanStatus)، ثم WaMessageSent. لا يرمي */
    private static function afterWamid(?WaMessage $row, string $wamid, string $source, ?int $staffId): void
    {
        if ($row === null || $wamid === '') {
            return;
        }
        try {
            OrphanStatus::drain($wamid);
        } catch (\Throwable $e) {
            WaLog::error('wa.status.orphan_failed', ['message_id' => $row->id, 'type' => class_basename($e)]);
        }
        EventBus::emit(new WaMessageSent($row->id, (int) $row->conversation_id, $source, $staffId));
    }

    private static function emitFailed(?WaMessage $row, MetaError $err, string $source, ?int $staffId): void
    {
        if ($row !== null) {
            EventBus::emit(new WaMessageFailed($row->id, (int) $row->conversation_id, $source, $err->metaCode, $err->category, $err->scope, 'send', $staffId));
        }
    }

    private static ?bool $hasTypeKey = null;

    private static function hasTypeKey(): bool
    {
        return self::$hasTypeKey ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'type_key');
    }

    private static function voiceRefused(WaConversation $conv, string $source): array
    {
        WaLog::warning('wa.voice.refused', ['conv_id' => $conv->id, 'source' => $source]);

        return self::failLocal(self::ERR_UNSUPPORTED, 'الرسائل الصوتية من الموظف وحده.');
    }

    /** المستدعون يقرؤون ok وerror، والجديد يحمل الفئة والإجراء وقابلية الإعادة والنطاق */
    private static function failWith(MetaError $err, string $code, string $msg): array
    {
        return self::fail($code, $msg) + ['category' => $err->category, 'retryable' => $err->isRetryable(), 'action' => $err->action, 'scope' => $err->scope];
    }

    private static function failLocal(string $code, string $msg): array
    {
        return self::failWith(MetaError::local($code), $code, $msg);
    }

    private static function fail(string $code, string $msg, ?int $metaCode = null): array
    {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'message_id' => null, 'meta_code' => $metaCode];
    }
}

<?php

namespace App\Services\WhatsApp;

/**
 * رسالة واردة واحدة من الويبهوك بعد التطبيع (E11): الهوية والنص المعروض لكل نوع موثَّق، والاقتباس والوسائط
 * والإحالة. بدل WhatsAppInbox::extract الذي كان يحوّل contacts وsystem وunsupported وorder إلى «[type]».
 * لا يُكتب في السجل رقم ولا معرّف ولا نص: النوع وwamid فقط.
 */
final class InboundMessage
{
    /** معرّف ميتا الخاص بنشاطنا: «US.1349…». معرّف حساب الأعمال الأب (.ENT.) ليس عميلاً */
    public const BSUID = '/^[A-Z]{2}\.[A-Za-z0-9]{1,128}$/';

    private const MEDIA = ['image', 'video', 'document', 'audio', 'sticker'];

    /**
     * @param  array{id: ?string, mime: ?string, sha256: ?string, filename: ?string}|null  $media
     * @param  array{type: ?string, newPhone: ?string, newUserId: ?string}|null  $system
     */
    public function __construct(
        public readonly ?string $wamid,
        public readonly string $type,
        public readonly ?string $fromPhone,
        public readonly ?string $fromUserId,
        public readonly ?string $profileName,
        public readonly ?string $username,
        public readonly string $body,
        public readonly ?string $buttonId,
        public readonly ?string $replyToWamid,
        public readonly ?array $referral,
        public readonly ?array $media,
        public readonly ?string $reactionTo,
        public readonly ?array $system,
        public readonly ?string $unsupportedType,
        public readonly ?string $sharedPhone,
        public readonly ?string $sharedOrigin,
        public readonly string $phoneNumberId,
        public readonly int $timestamp,
        /** E33: إيموجي التفاعل ('' عند الإزالة، فميتا تحذف الحقل) */
        public readonly ?string $reactionEmoji = null,
        /**
         * E36: ردّ نموذج (nfm_reply): الاسم، وresponse_json مفكوكاً إن لم يتجاوز الحد، وflow_token، وحجمه بالبايت.
         * يحوّله FlowResponseHandler إلى طلب تسعير؛ لا يُكتب الرمز في body ولا في السجل.
         *
         * @var array{name: ?string, response: ?array, token: ?string, bytes: int}|null
         */
        public readonly ?array $flowReply = null,
        /** E36 (C043): رسالة تسأل عن منتج من كتالوج ميتا (context.referred_product). لا كتالوج عندنا: تصل موظفاً */
        public readonly bool $referredProduct = false,
    ) {}

    /** E33: تعديل وحذف أرقام Coexistence، إن وصلا صريحين، يُعاملان كغير مدعوم ولا يمسّان الرسالة الأصلية */
    private const AS_UNSUPPORTED = ['edit', 'revoke'];

    public static function fromWebhook(array $msg, ?array $contact, string $phoneNumberId): self
    {
        $type = (string) (data_get($msg, 'type') ?: 'text');
        $unsupportedType = $type === 'unsupported' ? self::str(data_get($msg, 'unsupported.type'), 30) : null;
        if (in_array($type, self::AS_UNSUPPORTED, true)) {
            [$type, $unsupportedType] = ['unsupported', $type];
        }
        // رد العميل على طلب إذن الاتصال (E39، العلم calls_webhook): نوع مستقل لا يصل البوت. بدون العلم يبقى interactive
        if ($type === 'interactive' && data_get($msg, 'interactive.type') === 'call_permission_reply' && CallEventHandler::enabled()) {
            $type = 'call_permission';
        }
        $from = data_get($msg, 'from');
        $userId = data_get($msg, 'from_user_id');
        [$body, $buttonId] = $type === 'unsupported' ? [self::unsupportedBody($msg, $unsupportedType), null] : self::body($msg, $type);
        $mediaNode = in_array($type, self::MEDIA, true) ? (array) data_get($msg, $type, []) : [];
        $shared = $type === 'contacts' ? self::sharedContact($msg) : [null, null];
        // E36 (C043): سؤال عن منتج من كتالوج ميتا، يُوسم في النص فيعرف الموظف السياق
        $referred = is_array(data_get($msg, 'context.referred_product'));
        if ($referred) {
            $body = '🛍️ [سؤال عن منتج من كتالوج واتساب] '.$body;
        }

        return new self(
            wamid: is_string(data_get($msg, 'id')) ? data_get($msg, 'id') : null,
            type: $type,
            fromPhone: is_string($from) && PhoneNormalizer::isPhoneLike($from) ? PhoneNormalizer::digits($from) : null,
            fromUserId: is_string($userId) && preg_match(self::BSUID, $userId) && !str_contains($userId, '.ENT.') ? $userId : null,
            profileName: TextNormalizer::untrustedName(data_get($contact, 'profile.name'), 120),
            username: TextNormalizer::untrustedName(data_get($contact, 'profile.username'), 100),
            body: $body,
            buttonId: $buttonId,
            replyToWamid: is_string(data_get($msg, 'context.id')) ? mb_substr(data_get($msg, 'context.id'), 0, 128) : null,
            referral: is_array(data_get($msg, 'referral')) ? data_get($msg, 'referral') : null,
            media: $mediaNode ? [
                'id' => self::str($mediaNode['id'] ?? null, 128),
                'mime' => self::str($mediaNode['mime_type'] ?? null, 100),
                'sha256' => self::str($mediaNode['sha256'] ?? null, 100),
                'filename' => TextNormalizer::untrustedName($mediaNode['filename'] ?? null, 255),
            ] : null,
            reactionTo: $type === 'reaction' ? self::str(data_get($msg, 'reaction.message_id'), 128) : null,
            system: $type === 'system' ? [
                'type' => self::str(data_get($msg, 'system.type'), 40),
                'newPhone' => PhoneNormalizer::isPhoneLike((string) (data_get($msg, 'system.wa_id') ?? data_get($msg, 'system.new_wa_id')))
                    ? PhoneNormalizer::digits((string) (data_get($msg, 'system.wa_id') ?? data_get($msg, 'system.new_wa_id'))) : null,
                'newUserId' => is_string(data_get($msg, 'system.user_id')) && preg_match(self::BSUID, data_get($msg, 'system.user_id')) ? data_get($msg, 'system.user_id') : null,
            ] : null,
            unsupportedType: $unsupportedType,
            sharedPhone: $shared[0],
            sharedOrigin: $shared[1],
            phoneNumberId: $phoneNumberId,
            timestamp: (int) data_get($msg, 'timestamp', 0),
            reactionEmoji: $type === 'reaction' ? (filled(data_get($msg, 'reaction.emoji')) ? mb_substr((string) data_get($msg, 'reaction.emoji'), 0, 16) : '') : null,
            flowReply: $type === 'interactive' ? self::flowReply($msg) : null,
            referredProduct: $referred,
        );
    }

    /**
     * صدى رسالة أرسلها الموظف من تطبيق الأعمال على الهاتف (E39، الحقل smb_message_echoes): العميل الطرف الآخر في to
     * (رقم، أو معرّف BSUID لمن يراسل باسم مستخدم)، وfrom رقمنا. النص بالمستخرج نفسه لكل الأنواع. fromPhone وfromUserId
     * هنا هوية العميل، فتطابق محادثته عبر ContactIdentity كالوارد.
     */
    public static function fromEcho(array $echo, string $phoneNumberId): self
    {
        $to = data_get($echo, 'to');
        $userId = data_get($echo, 'to_user_id') ?? data_get($echo, 'recipient_user_id');
        if (is_string($to) && preg_match(self::BSUID, $to)) {
            $userId ??= $to;
            $to = null;
        }
        $echo['from'] = $to;
        $echo['from_user_id'] = $userId;

        return self::fromWebhook($echo, null, $phoneNumberId);
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isReaction(): bool
    {
        return $this->type === 'reaction';
    }

    /** النص المعروض في الصندوق ومعرّف الزر إن وُجد */
    private static function body(array $msg, string $type): array
    {
        $caption = fn (string $node) => filled(data_get($msg, "{$node}.caption")) ? ': '.data_get($msg, "{$node}.caption") : '';

        return match ($type) {
            'text' => [(string) data_get($msg, 'text.body', ''), null],
            'interactive' => self::interactive($msg),
            'call_permission' => [self::callPermission($msg), null],
            'button' => [(string) data_get($msg, 'button.text', ''), self::str(data_get($msg, 'button.payload'), 256)],
            'image' => ['📷 صورة'.$caption('image'), null],
            'video' => ['🎬 مقطع فيديو'.$caption('video'), null],
            'document' => ['📄 ملف: '.(TextNormalizer::untrustedName(data_get($msg, 'document.filename'), 255) ?? '').$caption('document'), null],
            'sticker' => ['🏷️ ملصق', null],
            // كما هو: VoiceTranscriber يبحث عنه ويستبدله بالتفريغ
            'audio' => ['🎤 رسالة صوتية', null],
            'location' => [self::location($msg), null],
            'contacts' => [self::contacts($msg), null],
            'order' => ['🛒 طلب من الكتالوج: '.count((array) data_get($msg, 'order.product_items', [])).' أصناف'
                .(filled(data_get($msg, 'order.text')) ? ' — '.data_get($msg, 'order.text') : ''), null],
            'reaction' => [filled(data_get($msg, 'reaction.emoji')) ? (string) data_get($msg, 'reaction.emoji') : '↩️ أزال التفاعل', null],
            'system' => [self::system($msg), null],
            default => self::unknown($msg, $type),
        };
    }

    private static function interactive(array $msg): array
    {
        if ($reply = data_get($msg, 'interactive.button_reply') ?? data_get($msg, 'interactive.list_reply')) {
            return [(string) ($reply['title'] ?? ''), self::str($reply['id'] ?? null, 256)];
        }
        if ($form = data_get($msg, 'interactive.nfm_reply')) {
            $response = json_decode((string) ($form['response_json'] ?? ''), true);
            // flow_token رمز الربط بالمحادثة (E36): لا يُعرض في النص
            if (is_array($response)) {
                unset($response['flow_token']);
            }
            $summary = is_array($response) && $response !== [] ? mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 300) : '';

            return ['📝 نموذج: '.($form['name'] ?? '').($summary !== '' ? ' — '.$summary : ''), null];
        }

        return ['[interactive]', null];
    }

    /** «وافق العميل على أن نتصل به حتى …» بتوقيت عمّان، أو الرفض */
    private static function callPermission(array $msg): string
    {
        $reply = (array) data_get($msg, 'interactive.call_permission_reply', []);
        if (strtolower((string) ($reply['response'] ?? '')) !== 'accept') {
            return '📞 رفض العميل طلب الاتصال';
        }
        $exp = $reply['expiration_timestamp'] ?? null;
        $until = is_numeric($exp) && (int) $exp > 0
            ? ' حتى '.\Illuminate\Support\Carbon::createFromTimestamp((int) $exp)->setTimezone((string) config('whatsapp.calls.timezone', 'Asia/Amman'))->format('Y-m-d H:i')
            : '';

        return '📞 وافق العميل على أن نتصل به'.$until;
    }

    /** E36: ردّ النموذج مفكوكاً بحد (config whatsapp.interactive.flow_response_max_bytes)، أو null لغير nfm_reply */
    private static function flowReply(array $msg): ?array
    {
        $form = data_get($msg, 'interactive.nfm_reply');
        if (!is_array($form)) {
            return null;
        }
        $raw = is_string($form['response_json'] ?? null) ? $form['response_json'] : '';
        $bytes = strlen($raw);
        $response = $bytes > 0 && $bytes <= (int) config('whatsapp.interactive.flow_response_max_bytes', 4096) ? json_decode($raw, true, 8) : null;
        $token = is_array($response) && is_string($response['flow_token'] ?? null) ? mb_substr($response['flow_token'], 0, 64) : null;

        return ['name' => self::str($form['name'] ?? null, 64), 'response' => is_array($response) ? $response : null, 'token' => $token, 'bytes' => $bytes];
    }

    private static function location(array $msg): string
    {
        $loc = (array) data_get($msg, 'location', []);
        $place = implode('، ', array_filter([$loc['name'] ?? null, $loc['address'] ?? null]));
        $link = isset($loc['latitude'], $loc['longitude'])
            ? 'https://maps.google.com/?q='.(float) $loc['latitude'].','.(float) $loc['longitude']
            : (is_string($loc['url'] ?? null) && str_starts_with($loc['url'], 'https://') ? $loc['url'] : '');

        return trim('📍 '.($place !== '' ? $place.' ' : '').$link);
    }

    /**
     * بطاقة جهة اتصال بالاسم وحده (E33، C007): رقم الطرف الثالث لا يصل إلى مزوّد النموذج ولا معاينة المحادثة ولا إشعار
     * الجوال. الأرقام في payload وحدها، ولمن يملك whatsapp.reply.
     */
    private static function contacts(array $msg): string
    {
        $lines = [];
        foreach ((array) data_get($msg, 'contacts', []) as $card) {
            $lines[] = '👤 بطاقة جهة اتصال: '.(TextNormalizer::untrustedName(data_get($card, 'name.formatted_name'), 120) ?? '—');
        }

        return $lines ? implode("\n", $lines) : '👤 بطاقة جهة اتصال';
    }

    /**
     * نص عربي بحسب unsupported.type (E33): لا يرد عليه البوت، ويصل الموظف بشارة «رسالة غير مدعومة».
     * النوع المجهول يُعرض بمحارف لاتينية آمنة وحدها.
     */
    /** نص غير المدعوم (E33)، و131060 مع أعلام التعايش (E39): أول رسالة من عميل جديد لا يصل نصها إلا في تطبيق الأعمال */
    private static function unsupportedBody(array $msg, ?string $kind): string
    {
        if ((int) data_get($msg, 'errors.0.code') === 131060 && CoexistenceSync::active()) {
            return '⚠️ رسالة لم يصل نصها — افتح المحادثة في تطبيق الأعمال على هاتف الوكالة';
        }

        return self::unsupported($kind);
    }

    public static function unsupported(?string $kind): string
    {
        return match ($kind) {
            'edit' => '✏️ عدّل العميل رسالة سابقة، ولا يظهر النص الجديد في منصة ميتا — راجع المحادثة واطلب منه إعادة كتابتها',
            'revoke' => '🗑️ حذف العميل رسالة سابقة من جهازه، ويبقى نصها الأصلي هنا',
            'poll_creation', 'poll_update' => '📊 أرسل العميل استطلاعاً لا تعرضه منصة ميتا',
            'media_placeholder' => '⏳ وسائط لم يكتمل إرسالها من جهاز العميل',
            default => '⚠️ رسالة لا تدعمها منصة ميتا'.(($safe = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $kind))) ? " ({$safe})" : ''),
        };
    }

    /**
     * رقم شاركه العميل نفسه رداً على زر «طلب الرقم» (origin=contact_request)؛ بطاقة شخص ثالث (other) لا تمس الهوية.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function sharedContact(array $msg): array
    {
        $origin = self::str(data_get($msg, 'contacts.0.origin') ?? data_get($msg, 'origin'), 30);
        $waId = data_get($msg, 'contacts.0.phones.0.wa_id');

        return [$origin === 'contact_request' && is_string($waId) && PhoneNormalizer::isPhoneLike($waId) ? PhoneNormalizer::digits($waId) : null, $origin];
    }

    private static function system(array $msg): string
    {
        return match ((string) data_get($msg, 'system.type')) {
            'user_changed_number', 'customer_changed_number' => '🔄 غيّر العميل رقمه',
            'user_changed_user_id' => '🔄 تغيّر معرّف واتساب للعميل',
            default => '🔄 تحديث من واتساب',
        };
    }

    private static function unknown(array $msg, string $type): array
    {
        WaLog::warning('webhook.inbound.unknown_type', ['type' => mb_substr($type, 0, 30), 'wamid' => data_get($msg, 'id')]);

        return ['['.mb_substr($type, 0, 30).']', null];
    }

    private static function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}

<?php

namespace App\Services\WhatsApp;

/**
 * رسالة واردة واحدة بعد التطبيع (E11): الهوية والنص المعروض والاقتباس والوسائط والإحالة. يبنيها من حدث البوابة
 * OpenWa\InboundParser (ومحلّل ويبهوك ميتا أُزيل مع ميتا، 2026-11-06). لا يُكتب في السجل رقم ولا معرّف ولا نص.
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
         * E36: ردّ نموذج (nfm_reply) من ميتا: لا يصل من البوابة فيبقى null دائماً (معامل الإنشاء محفوظ لمن يبني الرسالة).
         *
         * @var array{name: ?string, response: ?array, token: ?string, bytes: int}|null
         */
        public readonly ?array $flowReply = null,
        /** E36 (C043): رسالة تسأل عن منتج من كتالوج ميتا (context.referred_product). لا كتالوج عندنا: تصل موظفاً */
        public readonly bool $referredProduct = false,
        /** OpenWA (2026-11-06): رقمنا الذي وصلت عليه، ومعرّف المحادثة على البوابة، ومعرّف الخصوصية إن كان المرسل خلفه */
        public readonly ?int $channelId = null,
        public readonly ?string $jid = null,
        public readonly ?string $lid = null,
        /** رسالة صوتية مسجّلة (ptt) لا ملف صوتي مرفق */
        public readonly bool $voice = false,
    ) {}

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function isReaction(): bool
    {
        return $this->type === 'reaction';
    }
}

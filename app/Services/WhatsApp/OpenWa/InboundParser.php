<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaChannel;
use App\Services\WhatsApp\GroupChats;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\TextNormalizer;

/**
 * IncomingMessage من OpenWA (message.received وmessage.sent) إلى InboundMessage الذي يفهمه بقية النظام، فيبقى
 * WhatsAppInbox::storeInbound والهوية والتخزين والبوت كما هي. جدول التحويل في docs/whatsapp/openwa.md.
 *
 * يُسقط (null): الحالات (status@broadcast) والقنوات (@newsletter) وقوائم البث، ورسائل النظام الداخلية (إشعارات
 * التشفير وتغيير رمز الأمان وغيرها)، وسجل المكالمات (أحداث call.* منفصلة)، والحذف (message.revoked منفصل).
 * المجموعات (‎…@g.us) تُقرأ بكاتب كل رسالة (GroupChats): للعرض ورد الموظف وحده.
 *
 * النوع: rawType (نوع whatsapp-web.js الأصلي، رقعة docker/openwa/apply-rawtype.cjs) ثم type. البوابة بلا الرقعة تقول
 * «unknown» لكل ما لا تعرفه: الفارغ منه إشعار داخلي فيُسقط، وما له نص يُعرض نصاً.
 *
 * fromPhone: senderPhone (RESOLVE_LID_TO_PHONE)، وإلا أرقام jid إن كان @c.us. lid: jid إن كان @lid.
 * للصادر من هاتفنا (fromMe) الطرف الآخر هو chatId/to، فتعود هوية العميل لا رقمنا.
 */
final class InboundParser
{
    /** أنواع داخلية لا يعرضها واتساب: لا محادثة لها */
    private const SYSTEM_TYPES = ['e2e_notification', 'notification', 'notification_template', 'protocol', 'gp2', 'ciphertext',
        'call_log', 'call', 'broadcast_notification', 'pinned_message', 'debug', 'keep_in_chat', 'revoked',
        // ألبوم الصور غلاف بلا محتوى (الصور تصل رسائل منفصلة)، والتفاعل له حدث message.reaction، وتعديل/حذف له حدثاه
        'album', 'reaction', 'message_edit', 'protocol_message', 'security', 'admin_notification', 'newsletter_notification'];

    private const TYPE = ['chat' => 'text', 'text' => 'text', 'image' => 'image', 'video' => 'video', 'audio' => 'audio', 'voice' => 'audio', 'ptt' => 'audio',
        'document' => 'document', 'sticker' => 'sticker', 'location' => 'location', 'vcard' => 'contacts', 'contact' => 'contacts', 'multi_vcard' => 'contacts',
        'order' => 'order', 'product' => 'order', 'poll' => 'unsupported', 'poll_creation' => 'unsupported', 'masked' => 'unsupported', 'unknown' => 'unsupported',
        // فيديو دائري، وردود الأزرار والقوائم والرسائل التفاعلية: محتواها نص
        'ptv' => 'video', 'buttons_response' => 'text', 'list_response' => 'text', 'template_button_reply' => 'text',
        'interactive' => 'text', 'buttons' => 'text', 'list' => 'text', 'template' => 'text', 'hsm' => 'text', 'groups_v4_invite' => 'text'];

    public static function parse(array $d, WaChannel $channel): ?InboundMessage
    {
        $fromMe = (bool) ($d['fromMe'] ?? false);
        $chat = (string) ($d['chatId'] ?? ($fromMe ? ($d['to'] ?? '') : ($d['from'] ?? '')));
        $group = GroupChats::isGroupJid($chat);
        if ((!$group && !self::isPerson($chat)) || !empty($d['isStatusBroadcast'])) {
            return null;
        }
        $raw = strtolower((string) ($d['rawType'] ?? $d['type'] ?? 'text'));
        if (in_array($raw, self::SYSTEM_TYPES, true)) {
            return null;
        }
        $hasText = trim((string) ($d['body'] ?? '')) !== '';
        if ($raw === 'unknown' && !isset($d['rawType'])) {
            // بوابة بلا رقعة rawtype: الفارغ إشعار داخلي (التشفير، رمز الأمان) يصل لكل محادثة عند الربط، وما له نص نص
            if (!$hasText) {
                return null;
            }
            $raw = 'chat';
        }
        // نوع أصلي لا نعرفه وله نص: يُعرض نصه بدل «لا يعرضها واتساب ويب»
        $type = self::TYPE[$raw] ?? ($hasText ? 'text' : 'unsupported');
        // نقرة زر أو صف قائمة (تصل في data.button من محرك Baileys وحده): اختيار لا نص
        if ($type === 'text' && filled(data_get($d, 'button.id'))) {
            $type = 'interactive';
        }
        $id = is_string($d['id'] ?? null) && $d['id'] !== '' ? mb_substr($d['id'], 0, 128) : null;

        $isLid = str_ends_with($chat, '@lid');
        // المجموعة: الهوية معرّفها وحده، والهاتف والاسم لكاتب الرسالة فيها (للعرض)
        $author = $group ? (string) ($d['author'] ?? ($fromMe ? '' : ($d['from'] ?? ''))) : '';
        $authorPhone = $group && !$fromMe ? (self::phone($d['senderPhone'] ?? null) ?? self::phone(self::digits($author))) : null;
        $phone = $fromMe || $group ? null : self::phone($d['senderPhone'] ?? null);
        $phone ??= $isLid || $group ? null : self::phone(explode('@', $chat)[0]);
        $body = self::body($type, $raw, $d);
        $media = in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true) ? [
            // معرّف الوسيط = معرّف الرسالة: يُجلب من GET /messages/{chatId}/{id}/media (WhatsAppChannel::fetchMedia)
            'id' => $id,
            'mime' => self::str(data_get($d, 'media.mimetype'), 100),
            'sha256' => null,
            'filename' => TextNormalizer::untrustedName(data_get($d, 'media.filename'), 255),
        ] : null;

        return new InboundMessage(
            wamid: $id,
            type: $type,
            fromPhone: $phone,
            fromUserId: null,
            profileName: $fromMe || $group ? null : TextNormalizer::untrustedName(data_get($d, 'contact.pushName') ?? data_get($d, 'contact.name') ?? ($d['pushName'] ?? null), 120),
            username: null,
            body: $body,
            buttonId: self::str(data_get($d, 'button.id'), 256),
            replyToWamid: self::str(data_get($d, 'quotedMessage.id'), 128),
            referral: null,
            media: $media,
            reactionTo: null,
            system: null,
            unsupportedType: $type === 'unsupported' ? mb_substr(preg_replace('/[^a-z0-9_]/', '', $raw) ?: 'unknown', 0, 30) : null,
            sharedPhone: null,
            sharedOrigin: null,
            phoneNumberId: '',
            timestamp: self::timestamp($d['timestamp'] ?? null),
            channelId: (int) $channel->id,
            jid: mb_substr($chat, 0, 120),
            lid: $group ? null : ($isLid ? mb_substr($chat, 0, 120) : (str_ends_with((string) ($d['from'] ?? ''), '@lid') && !$fromMe ? mb_substr((string) $d['from'], 0, 120) : null)),
            voice: in_array($raw, ['voice', 'ptt'], true),
            groupJid: $group ? mb_substr($chat, 0, 120) : null,
            author: $group && !$fromMe ? TextNormalizer::untrustedName(data_get($d, 'contact.pushName') ?? data_get($d, 'contact.name') ?? ($d['pushName'] ?? null), 120) : null,
            authorPhone: $authorPhone,
        );
    }

    /** محادثة فردية: @c.us أو @s.whatsapp.net أو @lid. لا مجموعات ولا بث ولا قنوات */
    public static function isPerson(string $jid): bool
    {
        return (bool) preg_match('/^[0-9]{5,20}(:\d+)?@(c\.us|s\.whatsapp\.net|lid)$/', $jid);
    }

    /** أرقام jid الفردي (@c.us)، أو '' لغيره */
    public static function digits(string $jid): string
    {
        return preg_match('/^(\d{5,20})(:\d+)?@(c\.us|s\.whatsapp\.net)$/', $jid, $m) ? $m[1] : '';
    }

    /** jid الإرسال لرقم: {أرقام}@c.us */
    public static function jidFor(string $phone): string
    {
        return PhoneNormalizer::digits($phone).'@c.us';
    }

    private static function body(string $type, string $raw, array $d): string
    {
        $text = (string) ($d['body'] ?? '');
        // البوابة قد ترسل الصورة المصغّرة (base64) في body بدل التعليق لرسائل الوسائط (درس hub/util.js)
        if ($type !== 'text' && mb_strlen($text) > 200 && !preg_match('/\s/', $text)) {
            $text = '';
        }
        $caption = trim($text) !== '' ? ': '.$text : '';

        return match ($type) {
            'text' => $text,
            'image' => '📷 صورة'.$caption,
            'video' => '🎬 مقطع فيديو'.$caption,
            'document' => '📄 ملف: '.(TextNormalizer::untrustedName(data_get($d, 'media.filename'), 255) ?? '').$caption,
            'sticker' => '🏷️ ملصق',
            // كما هو: VoiceTranscriber يبحث عنه ويستبدله بالتفريغ
            'audio' => '🎤 رسالة صوتية',
            'location' => self::location($d),
            'contacts' => self::contact($d),
            'order' => $raw === 'product' ? '🛍️ [سؤال عن منتج من كتالوج واتساب] '.(string) data_get($d, 'product.title', '') : '🛒 طلب من الكتالوج',
            'unsupported' => match ($raw) {
                'poll', 'poll_creation' => '📊 أرسل العميل استطلاعاً'.(filled(data_get($d, 'poll.name')) ? ': '.data_get($d, 'poll.name') : ''),
                'masked' => '🔒 رسالة لا يعرضها واتساب على الأجهزة المرتبطة — افتحها على هاتف الرقم',
                default => '⚠️ رسالة لا يعرضها واتساب ويب'.(($safe = preg_replace('/[^a-z0-9_]/', '', $raw)) ? " ({$safe})" : ''),
            },
            default => $text,
        };
    }

    private static function location(array $d): string
    {
        $loc = (array) ($d['location'] ?? []);
        $place = implode('، ', array_filter([$loc['description'] ?? null, $loc['address'] ?? null], 'is_string'));
        $link = isset($loc['latitude'], $loc['longitude']) ? 'https://maps.google.com/?q='.(float) $loc['latitude'].','.(float) $loc['longitude'] : '';

        return trim('📍 '.($place !== '' ? $place.' ' : '').$link);
    }

    /** بطاقة جهة اتصال بالاسم وحده (C007): رقم الطرف الثالث لا يصل للنموذج ولا للمعاينة */
    private static function contact(array $d): string
    {
        $vcard = (string) ($d['body'] ?? '');
        $name = preg_match('/^FN[;:][^\r\n]*?:?([^\r\n:]+)$/mi', $vcard, $m) ? trim($m[1]) : (string) data_get($d, 'contact.name', '');

        return '👤 بطاقة جهة اتصال: '.(TextNormalizer::untrustedName($name, 120) ?? '—');
    }

    private static function phone(mixed $value): ?string
    {
        $digits = is_scalar($value) ? (preg_replace('/\D/', '', (string) $value) ?? '') : '';

        return $digits !== '' && PhoneNormalizer::isPhoneLike($digits) ? PhoneNormalizer::digits($digits) : null;
    }

    private static function timestamp(mixed $ts): int
    {
        if (!is_numeric($ts)) {
            return 0;
        }
        $n = (int) $ts;

        return $n > 1_000_000_000_000 ? intdiv($n, 1000) : $n;
    }

    private static function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}

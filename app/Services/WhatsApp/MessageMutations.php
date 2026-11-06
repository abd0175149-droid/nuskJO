<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageStored;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OpenWa\InboundParser;
use Illuminate\Support\Facades\DB;

/**
 * أحداث البوابة التي تمس رسالة قائمة أو لا تحمل رسالة: الحذف والتعديل والتفاعل والمكالمة الفائتة.
 * الأصل لا يُمحى ولا يتغير نصه (دليل عند النزاع): علامة في حمولته (_revoked_at و_edited_at و_edited_body)، والصندوق يعرضها.
 */
final class MessageMutations
{
    public static function revoked(WaChannel $channel, array $data): void
    {
        $id = (string) ($data['revokedId'] ?? $data['messageId'] ?? $data['id'] ?? '');
        $row = self::row($channel, $id);
        if ($row === null) {
            return;
        }
        DB::transaction(function () use ($row) {
            $m = WaMessage::whereKey($row->id)->lockForUpdate()->first();
            $payload = (array) ($m?->payload ?? []);
            $payload['_revoked_at'] ??= now()->toIso8601String();
            $m?->forceFill(['payload' => $payload])->save();
        });
        $row->conversation?->touchRealtime('message');
    }

    public static function edited(WaChannel $channel, array $data): void
    {
        $row = self::row($channel, (string) ($data['messageId'] ?? $data['id'] ?? ''));
        $body = $data['body'] ?? $data['newBody'] ?? null;
        if ($row === null || !is_string($body)) {
            return;
        }
        DB::transaction(function () use ($row, $body) {
            $m = WaMessage::whereKey($row->id)->lockForUpdate()->first();
            $payload = (array) ($m?->payload ?? []);
            $payload['_edited_at'] = now()->toIso8601String();
            $payload['_edited_body'] = mb_substr($body, 0, 4096);
            $m?->forceFill(['payload' => $payload])->save();
        });
        $row->conversation?->touchRealtime('message');
    }

    /**
     * تفاعل العميل على رسالة (message.reaction): صف reaction بالمسار نفسه للوارد (WhatsAppInbox) فتظهر شارته على الأصل.
     * تفاعل من رقمنا (من الهاتف) لا يُخزَّن: الموظف يراه على الهاتف، ولا معنى له للبوت.
     */
    public static function reaction(WaChannel $channel, array $data, WebhookResult $result): void
    {
        $target = (string) ($data['messageId'] ?? '');
        $chat = (string) ($data['chatId'] ?? '');
        $sender = (string) ($data['senderId'] ?? '');
        if ($target === '' || !InboundParser::isPerson($chat) || ($channel->phone && InboundParser::digits($sender) === $channel->ownDigits())) {
            return;
        }
        $emoji = mb_substr(trim((string) ($data['reaction'] ?? '')), 0, 16);
        $isLid = str_ends_with($chat, '@lid');
        $in = new InboundMessage(
            wamid: 'r_'.substr(hash('sha256', $channel->id.'|'.$target.'|'.$sender.'|'.$emoji.'|'.($data['timestamp'] ?? '')), 0, 40),
            type: 'reaction',
            fromPhone: $isLid ? null : (InboundParser::digits($chat) ?: null),
            fromUserId: null,
            profileName: null,
            username: null,
            body: $emoji !== '' ? $emoji : '↩️ أزال التفاعل',
            buttonId: null,
            replyToWamid: null,
            referral: null,
            media: null,
            reactionTo: mb_substr($target, 0, 128),
            system: null,
            unsupportedType: null,
            sharedPhone: null,
            sharedOrigin: null,
            phoneNumberId: '',
            timestamp: is_numeric($data['timestamp'] ?? null) ? (int) $data['timestamp'] : now()->getTimestamp(),
            reactionEmoji: $emoji,
            channelId: (int) $channel->id,
            jid: $chat,
            lid: $isLid ? $chat : null,
        );
        WhatsAppInbox::storeInbound($in, ['_openwa' => true, 'reaction' => ['message_id' => $target, 'emoji' => $emoji]]);
    }

    /**
     * مكالمة فائتة على الرقم: سطر نظام في المحادثة، والمحادثة «تحتاج تدخّلاً» بسبب missed_call (الفلتر في الصندوق).
     * المكالمة لا يرد عليها البوت. call.received يُعدّ فقط (الرد على المكالمات من الهاتف).
     */
    public static function call(WaChannel $channel, string $event, array $data, WebhookResult $result): void
    {
        if ($event !== 'call.missed') {
            return;
        }
        $from = (string) ($data['from'] ?? $data['chatId'] ?? $data['peerJid'] ?? '');
        if (!InboundParser::isPerson($from)) {
            return;
        }
        $isLid = str_ends_with($from, '@lid');
        $phone = $isLid ? (InboundParser::digits((string) ($data['senderPhone'] ?? '')) ?: null) : (InboundParser::digits($from) ?: null);
        $callId = (string) ($data['id'] ?? $data['callId'] ?? hash('sha256', $from.'|'.($data['timestamp'] ?? now()->getTimestamp())));
        $wamid = 'call_'.substr(hash('sha256', $channel->id.'|'.$callId), 0, 40);
        if (WaMessage::where('wamid', $wamid)->exists()) {
            return;
        }
        $conv = DB::transaction(function () use ($channel, $from, $phone, $isLid, $wamid, $data) {
            $conv = ContactIdentity::resolveIdentity($phone, null, null, channelId: (int) $channel->id, jid: $from, lid: $isLid ? $from : null);
            $video = (bool) ($data['isVideo'] ?? $data['video'] ?? false);
            $m = WaMessage::create(['conversation_id' => $conv->id, 'channel_id' => $channel->id, 'wamid' => $wamid, 'direction' => 'in',
                'source' => 'system', 'msg_type' => 'system', 'body' => $video ? '📹 مكالمة فيديو فائتة' : '📞 مكالمة فائتة',
                'payload' => ['_openwa' => true, 'call' => ['missed' => true, 'video' => $video]]]);
            EventBus::emit(new WaMessageStored($m->id, $conv->id, 'system'));
            WaConversation::whereKey($conv->id)->update(['last_message_at' => now(), 'last_message_preview' => $m->body]);

            return $conv;
        });
        $conv->refresh()->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'missed_call'] : []))->save();
        $result->count('call.missed');
    }

    private static function row(WaChannel $channel, string $wamid): ?WaMessage
    {
        if ($wamid === '') {
            return null;
        }

        return WaMessage::where('wamid', $wamid)->where(fn ($q) => $q->where('channel_id', $channel->id)->orWhereNull('channel_id'))->first();
    }
}

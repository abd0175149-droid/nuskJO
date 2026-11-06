<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaChannel;
use App\Services\WhatsApp\ChoiceResolver;
use App\Services\WhatsApp\EchoHandler;
use App\Services\WhatsApp\MessageMutations;
use App\Services\WhatsApp\StatusApplier;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WebhookResult;
use App\Services\WhatsApp\WhatsAppInbox;

/**
 * يوزّع حدث OpenWA (بعد التوقيع والسياج) على معالجه. كل حدث معزول: استثناء يُسجَّل في النتيجة ولا يُسقط الرد بـ 200
 * (الصف يُعاد بأمر الإعادة). خريطة الأحداث في docs/whatsapp/openwa.md.
 */
final class EventRouter
{
    public static function route(WaChannel $channel, array $envelope, WebhookResult $result): void
    {
        $event = (string) ($envelope['event'] ?? '');
        $data = (array) ($envelope['data'] ?? []);
        $result->count($event !== '' ? $event : 'unknown');

        try {
            match (true) {
                $event === 'message.received' => self::received($channel, $data, $result),
                $event === 'message.sent' => EchoHandler::handleOpenWa($channel, $data, $result),
                $event === 'message.ack', $event === 'message.failed' => StatusApplier::applyOpenWa($channel, $data, $event === 'message.failed'),
                $event === 'message.revoked' => MessageMutations::revoked($channel, $data),
                $event === 'message.edited' => MessageMutations::edited($channel, $data),
                $event === 'message.reaction' => MessageMutations::reaction($channel, $data, $result),
                $event === 'poll.vote' => self::vote($channel, $data, $result),
                str_starts_with($event, 'session.') => ChannelState::handle($channel, $event, $data),
                $event === 'call.missed', $event === 'call.received' => MessageMutations::call($channel, $event, $data, $result),
                default => null,
            };
        } catch (\Throwable $e) {
            $ref = (string) ($data['id'] ?? $data['messageId'] ?? $event);
            $result->fail('event', mb_substr($ref, 0, 128), $e);
            WaLog::error('webhook.item_failed', ['field' => $event, 'channel_id' => $channel->id, 'exception' => $e]);
        }
    }

    private static function received(WaChannel $channel, array $data, WebhookResult $result): void
    {
        $in = InboundParser::parse($data, $channel);
        if ($in === null) {
            $result->count('ignored');

            return;
        }
        if (!$channel->last_inbound_at || $channel->last_inbound_at->lt(now()->subMinute())) {
            $channel->forceFill(['last_inbound_at' => now()])->save();
        }
        $convId = WhatsAppInbox::storeInbound($in, self::storable($data));
        if ($convId) {
            $result->touch($convId);
        }
    }

    /** صوت في استطلاع أرسلناه (بديل الأزرار، رقعة votes): يُعامل كرد بالخيار المختار */
    private static function vote(WaChannel $channel, array $data, WebhookResult $result): void
    {
        $convId = ChoiceResolver::fromPollVote($channel, $data);
        if ($convId) {
            $result->touch($convId);
        }
    }

    /** ما يُحفظ في wa_messages.payload: الحمولة بلا base64 الوسيط ولا الصورة المصغّرة */
    public static function storable(array $data): array
    {
        unset($data['media']['data'], $data['_data'], $data['rawData']);
        if (isset($data['body']) && is_string($data['body']) && strlen($data['body']) > 4096 && !preg_match('/\s/', $data['body'])) {
            $data['body'] = '';
        }

        return ['_openwa' => true] + $data;
    }
}

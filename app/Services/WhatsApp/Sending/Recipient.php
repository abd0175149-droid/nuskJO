<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Services\WhatsApp\ContactIdentity;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;

/**
 * (رقم من أرقامنا، هاتف العميل) إلى محادثة على ذلك الرقم: القائمة إن وُجدت، وإلا تُنشأ بعد التأكد أن الهاتف على واتساب
 * (GET /contacts/check/{digits})، وjid من البوابة (قد يكون @lid). نتيجة الفحص في الكاش يوماً.
 *
 * @return array{ok: bool, conversation?: WaConversation, code?: string, error?: string}
 */
final class Recipient
{
    public static function resolve(WaChannel $channel, string $phone): array
    {
        $canonical = ContactIdentity::phoneFrom(PhoneNormalizer::digits($phone));
        if ($canonical === null) {
            return ['ok' => false, 'code' => 'BAD_PHONE', 'error' => 'رقم العميل غير صالح.'];
        }
        $conv = WaConversation::where('channel_id', $channel->id)->where('phone', $canonical)->where('status', '!=', 'merged')->first();
        if ($conv !== null) {
            return ['ok' => true, 'conversation' => $conv];
        }
        $digits = PhoneNormalizer::digits($canonical);
        $check = cache()->remember("wa:check:{$channel->id}:{$digits}", now()->addDay(), function () use ($channel, $digits) {
            $res = app(ChannelTransport::class)->call($channel, 'GET', '/contacts/check/'.$digits);

            return $res->ok ? ['exists' => (bool) ($res->json('exists') ?? $res->json('numberExists') ?? false), 'jid' => (string) ($res->json('whatsappId') ?? $res->json('chatId') ?? '')]
                : ['error' => $res->error?->code ?? 'GW_ERROR'];
        });
        if (isset($check['error'])) {
            cache()->forget("wa:check:{$channel->id}:{$digits}");

            return ['ok' => false, 'code' => (string) $check['error'], 'error' => 'تعذّر التحقق من رقم العميل على واتساب الآن.', 'retry' => true];
        }
        if (!$check['exists']) {
            WaLog::info('wa.recipient.not_on_wa', ['channel_id' => $channel->id, 'phone_tail' => $digits]);

            return ['ok' => false, 'code' => 'RECIPIENT_NOT_ON_WA', 'error' => 'هذا الرقم ليس على واتساب.'];
        }
        $jid = $check['jid'] !== '' ? $check['jid'] : $digits.'@c.us';
        $conv = ContactIdentity::resolveIdentity($digits, null, null, channelId: (int) $channel->id, jid: $jid,
            lid: str_ends_with($jid, '@lid') ? $jid : null);

        return ['ok' => true, 'conversation' => $conv];
    }
}

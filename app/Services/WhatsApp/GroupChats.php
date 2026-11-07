<?php

namespace App\Services\WhatsApp;

use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Services\WhatsApp\Transport\ChannelTransport;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * محادثات مجموعات واتساب (2026-11-07، قرار المالك): تُستورد وتصل حية وتُعرض بأسماء كاتبيها، ويرد عليها الموظف من
 * الصندوق وحده. لا بوت (bot_enabled=false، وBotEngine يتخطاها، وWhatsAppChannel::gate يرفض كل مصدر غير staff)،
 * ولا رسائل نظام ولا إيقاف تسويق ولا طلب حذف ولا ربط بعميل ولا «تدخّل». المحادثة لكل رقم ومجموعة: jid ‎…@g.us.
 */
final class GroupChats
{
    /** معرّف مجموعة: ‎{أرقام}@g.us، والقديم ‎{منشئ}-{وقت}@g.us */
    public static function isGroupJid(string $jid): bool
    {
        return (bool) preg_match('/^[0-9]{5,40}(-[0-9]{5,20})?@g\.us$/', $jid);
    }

    /** محادثة المجموعة على الرقم، تُنشأ عند أول رسالة. الاسم يُحدَّث إن وصل */
    public static function resolve(int $channelId, string $jid, ?string $name = null): WaConversation
    {
        $jid = mb_substr($jid, 0, 120);
        $name = TextNormalizer::untrustedName($name, 120);
        $find = fn () => WaConversation::query()->where('channel_id', $channelId)->where('jid', $jid)->where('is_group', true)->first();

        $conv = $find();
        if ($conv === null) {
            try {
                $conv = WaConversation::forceCreate([
                    'channel_id' => $channelId, 'jid' => $jid, 'is_group' => true, 'display_name' => $name,
                    // لا بوت أبداً: الموظف وحده
                    'bot_enabled' => false, 'status' => 'open',
                ]);
            } catch (UniqueConstraintViolationException) {
                $conv = $find();
            }
            if ($conv !== null && $name === null) {
                $id = (int) $conv->id;
                dispatch(fn () => self::refreshName($id))->onQueue('wa-system')->afterCommit();
            }
        } elseif ($name !== null && $conv->display_name !== $name) {
            $conv->forceFill(['display_name' => $name])->save();
        }

        return $conv;
    }

    /** اسم المجموعة من البوابة (GET /groups/{id})، لمجموعة وصلت حية بلا اسم */
    public static function refreshName(int $convId): bool
    {
        $conv = WaConversation::find($convId);
        $channel = $conv?->channel_id ? WaChannel::find($conv->channel_id) : null;
        if (!$conv || !$conv->isGroup() || !$channel || !$channel->isReady()) {
            return false;
        }
        $res = app(ChannelTransport::class)->call($channel, 'GET', '/groups/'.rawurlencode((string) $conv->jid), null, 'receipt');
        $json = $res->ok ? (array) $res->json() : [];
        $name = TextNormalizer::untrustedName(collect(['subject', 'name', 'groupMetadata.subject', 'data.subject', 'data.name'])
            ->map(fn ($k) => data_get($json, $k))->first(fn ($v) => is_string($v) && trim($v) !== ''), 120);
        if ($name === null) {
            return false;
        }
        $conv->forceFill(['display_name' => $name])->save();

        return true;
    }

    /** مجموعات بلا اسم (whatsapp:resolve-lids كل 30 دقيقة) */
    public static function refreshMissingNames(int $limit = 50): int
    {
        $n = 0;
        foreach (WaConversation::query()->where('is_group', true)->whereNull('display_name')->orderByDesc('last_message_at')->limit($limit)->pluck('id') as $id) {
            $n += (int) self::refreshName((int) $id);
        }

        return $n;
    }
}

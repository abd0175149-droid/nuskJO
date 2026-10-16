<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Support\Facades\DB;

/**
 * التفاعل على رسالته (E33، C133): صف التفاعل يبقى مخزّناً (يمنع التكرار بـ wamid ويبقى دليلاً)، وتُسقط آخر حالة على
 * الرسالة الأصلية في payload.reactions {customer, business}. body الهدف لا يُمس أبداً.
 * الوارد بمقارنة طابع ميتا داخل معاملة: إعادة متأخرة من ميتا لا تعيد تفاعلاً أزاله العميل.
 */
final class ReactionProjector
{
    /** @return WaMessage|null الهدف إن وُجد في المحادثة نفسها، وإلا يبقى صف التفاعل يتيماً */
    public static function applyInbound(WaConversation $conv, InboundMessage $in, ?int $reactionRowId = null): ?WaMessage
    {
        if ($in->reactionTo === null || $in->reactionTo === '') {
            return null;
        }
        $emoji = self::emojiOf($in);

        return DB::transaction(function () use ($conv, $in, $emoji, $reactionRowId) {
            $target = WaMessage::where('conversation_id', $conv->id)->where('wamid', $in->reactionTo)
                ->where('msg_type', '!=', 'reaction')->lockForUpdate()->first();
            if (!$target) {
                return null;
            }
            $payload = (array) ($target->payload ?? []);
            $current = (int) data_get($payload, 'reactions.customer.ts', 0);
            // الطابع المساوي يُكتب (العميل بدّل تفاعله في الثانية نفسها، والأحدث وصولاً يغلب)، والأقدم يُهمل
            if ($in->timestamp > 0 && $current > 0 && $in->timestamp < $current) {
                return $target;
            }
            $reactions = (array) ($payload['reactions'] ?? []);
            if ($emoji === '') {
                // الإزالة تبقي الطابع: إعادة أقدم من ميتا بإيموجي لا تعيده
                $reactions['customer'] = ['emoji' => null, 'ts' => $in->timestamp, 'reaction_message_id' => $reactionRowId];
            } else {
                $reactions['customer'] = ['emoji' => $emoji, 'ts' => $in->timestamp, 'reaction_message_id' => $reactionRowId];
            }
            $payload['reactions'] = $reactions;
            $target->forceFill(['payload' => $payload])->save();

            return $target;
        });
    }

    /** تفاعل الموظف بعد قبول ميتا: business {emoji, staff_id, at}، أو يُحذف بالإزالة */
    public static function applyOutbound(WaMessage $target, string $emoji, int $staffId): void
    {
        DB::transaction(function () use ($target, $emoji, $staffId) {
            $row = WaMessage::whereKey($target->id)->lockForUpdate()->first();
            if (!$row) {
                return;
            }
            $payload = (array) ($row->payload ?? []);
            $reactions = (array) ($payload['reactions'] ?? []);
            if ($emoji === '') {
                unset($reactions['business']);
            } else {
                $reactions['business'] = ['emoji' => $emoji, 'staff_id' => $staffId, 'at' => now()->toIso8601String()];
            }
            if ($reactions === []) {
                unset($payload['reactions']);
            } else {
                $payload['reactions'] = $reactions;
            }
            $row->forceFill(['payload' => $payload])->save();
        });
    }

    /** للصندوق: {customer?, business?} بالإيموجي وحده (لا معرّف موظف ولا طابع) */
    public static function forThread(?array $payload): array
    {
        $out = [];
        if ($e = data_get($payload, 'reactions.customer.emoji')) {
            $out['customer'] = (string) $e;
        }
        if ($e = data_get($payload, 'reactions.business.emoji')) {
            $out['business'] = (string) $e;
        }

        return $out;
    }

    /** الإيموجي من ميتا: غيابه أو فراغه إزالة. حتى 16 محرفاً (إيموجي مركّب) */
    private static function emojiOf(InboundMessage $in): string
    {
        return mb_substr(trim((string) $in->reactionEmoji), 0, 16);
    }
}

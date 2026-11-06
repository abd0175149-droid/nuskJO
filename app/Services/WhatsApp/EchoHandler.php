<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageSent;
use App\Jobs\ResolveOpenWaEcho;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OpenWa\EventRouter;
use App\Services\WhatsApp\OpenWa\InboundParser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * صدى message.sent من البوابة (fromMe): كل رسالة خرجت من الرقم، سواء أرسلها نُسك عبر البوابة أو كتبها موظف من هاتف
 * الرقم نفسه (واتساب على الهاتف أو جهاز مرتبط آخر).
 *
 * 1) معرّفها في السجل (wamid) = إرسالنا: لا شيء (الحالة من message.ack).
 * 2) صف صادر لنا بلا معرّف بعد (queued/unknown) في المحادثة نفسها بالنص نفسه خلال 3 دقائق = إرسالنا وسبق الصدى رد
 *    البوابة: يُكتب المعرّف عليه.
 * 3) غير ذلك: إن كان الإرسال قيد الطيران (صف queued حديث) تُعاد المحاولة بعد 8 ثوانٍ مرة واحدة (ResolveOpenWaEcho)،
 *    وإلا فهو من الهاتف: صف صادر بالمصدر staff_phone، ويوقف البوت echo_pause_minutes كرد الموظف من الصندوق.
 * الأصداء لا تمر بـ WhatsAppChannel: أُرسلت فعلاً، فلا أقفال ولا سياسة ولا دفتر عليها.
 */
final class EchoHandler
{
    public static function handleOpenWa(WaChannel $channel, array $data, ?WebhookResult $result = null, bool $retried = false, bool $historical = false): void
    {
        $in = InboundParser::parse($data, $channel);
        if ($in === null || !$in->wamid) {
            return;
        }
        if (WaMessage::where('wamid', $in->wamid)->where(fn ($q) => $q->where('channel_id', $channel->id)->orWhereNull('channel_id'))->exists()) {
            return;
        }
        $conv = ContactIdentity::find($in->fromPhone ?? InboundParser::digits((string) $in->jid), $in->lid, (int) $channel->id)
            ?? WaConversation::where('channel_id', $channel->id)->where('jid', $in->jid)->where('status', '!=', 'merged')->first();

        if ($conv !== null && self::claimOwn($conv, $in, $historical ? 1440 : 3)) {
            return;
        }
        if (!$retried && $conv !== null && self::inFlight($conv)) {
            ResolveOpenWaEcho::dispatch($channel->id, EventRouter::storable($data))->delay(now()->addSeconds(8));

            return;
        }
        self::storeFromPhone($channel, $in, $data, $historical);
    }

    /**
     * إرسالنا الذي لم يُكتب معرّفه بعد: النص نفسه في المحادثة نفسها خلال 3 دقائق.
     * الاسترجاع بعد الانقطاع (historical) يبحث 24 ساعة: إرسال «غير مؤكد» قبل الانقطاع يعود بصداه بعده بساعات
     */
    private static function claimOwn(WaConversation $conv, InboundMessage $in, int $minutes = 3): bool
    {
        $row = WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->whereNull('wamid')
            ->whereIn('status', [WaMessage::STATUS_QUEUED, WaMessage::STATUS_UNKNOWN])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->when($in->type === 'text', fn ($q) => $q->where('body', $in->body), fn ($q) => $q->where('msg_type', $in->type === 'audio' ? 'audio' : $in->type))
            ->orderByDesc('id')->first();
        if ($row === null) {
            return false;
        }
        $moved = WaMessage::whereKey($row->id)->whereNull('wamid')->update(['wamid' => $in->wamid, 'status' => DB::raw("CASE WHEN status IN ('queued','unknown') THEN 'sent' ELSE status END"),
            'sent_at' => DB::raw("coalesce(sent_at, '".now()->format('Y-m-d H:i:s')."')")]);
        if ($moved > 0) {
            WaLog::info('wa.echo.claimed', ['conv_id' => $conv->id, 'message_id' => $row->id]);
            OrphanStatus::drain((string) $in->wamid);
        }

        return $moved > 0;
    }

    private static function inFlight(WaConversation $conv): bool
    {
        return WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->whereNull('wamid')
            ->where('status', WaMessage::STATUS_QUEUED)->where('created_at', '>=', now()->subMinutes(2))->exists();
    }

    private static function storeFromPhone(WaChannel $channel, InboundMessage $in, array $data, bool $historical = false): void
    {
        $at = $in->timestamp > 0 ? min(Carbon::createFromTimestamp($in->timestamp, config('app.timezone')), now()) : now();
        $holder = self::holderId($channel);
        try {
            [$conv, $message] = DB::transaction(function () use ($in, $data, $at, $holder, $channel, $historical) {
                // الموظف قد يبدأ المحادثة من الهاتف: تُنشأ إن لم توجد
                $conv = ContactIdentity::resolve($in);
                $message = WaMessage::create(array_filter([
                    'conversation_id' => $conv->id,
                    'channel_id' => $channel->id,
                    'wamid' => $in->wamid,
                    'direction' => 'out',
                    'source' => 'staff_phone',
                    'msg_type' => $in->type,
                    'body' => $in->body,
                    'payload' => EventRouter::storable($data) + ['_echo' => true],
                    'status' => 'sent',
                    'staff_id' => $holder,
                    'reply_to_wamid' => $in->replyToWamid,
                    'media_id' => $in->media['id'] ?? null,
                    'media_mime' => $in->media['mime'] ?? null,
                    'media_filename' => $in->media['filename'] ?? null,
                    'sent_at' => $at,
                ], fn ($v) => $v !== null));
                self::afterEcho($conv, $at, $in->body, $historical);
                Media\MediaIntake::onInbound($message, $conv, $data);

                return [$conv, $message];
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }
        EventBus::emit(new WaMessageSent($message->id, $conv->id, 'staff_phone', $holder));
        WaLog::info('wa.echo.phone', ['conv_id' => $conv->id, 'message_id' => $message->id, 'type' => $in->type, 'channel_id' => $channel->id]);
    }

    /** كرد الموظف من الصندوق: إيقاف البوت، وتصفير المؤشرات، والعلامة إلى أعلى وارد. المؤشرات رتيبة */
    private static function afterEcho(WaConversation $conv, Carbon $at, string $preview, bool $historical = false): void
    {
        $newer = $conv->last_message_at === null || $conv->last_message_at->lte($at);
        $conv->forceFill(array_filter([
            'last_outbound_at' => $conv->last_outbound_at === null || $conv->last_outbound_at->lt($at) ? $at : null,
            'last_message_at' => $newer ? $at : null,
            'last_message_preview' => $newer ? mb_substr(trim($preview), 0, 160) : null,
        ], fn ($v) => $v !== null) + ['unread_count' => 0]
            + (InboxFollowup::available() ? ['marked_unread_at' => null, 'marked_unread_by' => null] : []));
        // صدى مسترجع بعد انقطاع: الإيقاف من وقت كتابته لا من الآن، فرسالة هاتف من ساعات لا توقف البوت اليوم
        $until = $historical ? $at->copy()->addMinutes(self::echoPauseMinutes()) : self::echoPauseUntil();
        if ($until->isFuture()) {
            self::pause($conv, $until);
        } else {
            // ما سبق رد الهاتف أجاب عنه الموظف، وما وصل بعده (أثناء الانقطاع) يبقى للبوت وبوابة الحداثة
            $conv->save();
            ReplyState::advance($conv, (int) WaMessage::where('conversation_id', $conv->id)->where('direction', 'in')->where('created_at', '<=', $at)->max('id'));
        }
    }

    private static function echoPauseMinutes(): int
    {
        $s = WaBotSetting::current();

        return max(1, (int) ($s->echo_pause_minutes ?: $s->pause_minutes));
    }

    private static function echoPauseUntil(): Carbon
    {
        return now()->addMinutes(self::echoPauseMinutes());
    }

    /** الإيقاف لا يقصّر إيقافاً أطول قائماً، ويرفع «تدخّل»: موظف يتابع الآن */
    public static function pause(WaConversation $conv, Carbon $until): void
    {
        $current = $conv->bot_paused_until;
        $conv->forceFill([
            'bot_paused_until' => $current !== null && $current->gt($until) ? $current : $until,
            'needs_attention' => false,
        ] + (ReplyState::available() ? ['attention_reason' => null] : []))->save();
        ReplyState::advanceToNewest($conv);
    }

    /**
     * مرسل الصدى: الموظف الأساسي للرقم (wa_channel_users.is_primary) إن كان نشطاً ويرى كل المحادثات أو يخدم الرقم،
     * وإلا حامل جهاز التعايش القديم من الإعدادات، وإلا بلا مرسل.
     */
    public static function holderId(?WaChannel $channel = null): ?int
    {
        if ($channel !== null) {
            $primary = $channel->users()->wherePivot('is_primary', true)->where('is_active', true)->first();
            if ($primary !== null) {
                return (int) $primary->id;
            }
        }
        $id = (int) WaBotSetting::current()->coexistence_holder_user_id;
        if ($id <= 0) {
            return null;
        }
        $user = User::where('is_active', true)->find($id);

        return $user !== null && $user->can('whatsapp.view_all') ? $id : null;
    }
}

<?php

namespace App\Jobs;

use App\Models\WaChannel;
use App\Models\WaOutbox;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\Sending\Attachments;
use App\Services\WhatsApp\Sending\Recipient;
use App\Services\WhatsApp\Sending\SendGovernor;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * يرسل صفاً واحداً من wa_outbox حجزه wa:outbox-tick (status=sending بعقد lease): الرقم، ثم SendGovernor (الحدود)، ثم
 * المحادثة (Recipient)، ثم المرفق إن وُجد، ثم WhatsAppChannel::send بمفتاح الصف، ثم الحالة. لا يرمي: كل نتيجة تُكتب
 * في الصف، والعالق بعد انتهاء العقد يعيده التِك.
 */
class DispatchOutboxItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $outboxId)
    {
        $this->onQueue('wa-system');
    }

    public function handle(): void
    {
        $item = WaOutbox::find($this->outboxId);
        if (!$item || $item->status !== WaOutbox::SENDING) {
            return;
        }
        try {
            $this->send($item);
        } catch (\Throwable $e) {
            WaLog::error('wa.outbox.failed', ['outbox_id' => $item->id, 'exception' => $e]);
            $this->settle($item, ['status' => WaOutbox::DEFERRED, 'due_at' => now()->addMinutes(10), 'defer_reason' => 'خطأ داخلي، تُعاد المحاولة',
                'last_error' => mb_substr(class_basename($e), 0, 200)]);
        }
    }

    private function send(WaOutbox $item): void
    {
        $channel = $item->channel_id ? WaChannel::find($item->channel_id) : WaChannel::defaultFor(\App\Services\WhatsApp\System\Catalog::groupOf((string) $item->type_key));
        if ($channel === null || !$channel->is_active) {
            $this->settle($item, ['status' => WaOutbox::DEFERRED, 'due_at' => now()->addMinutes(15), 'defer_reason' => 'لا رقم واتساب جاهز']);

            return;
        }
        $decision = SendGovernor::decide($item, $channel);
        if ($decision->action === 'defer') {
            $this->settle($item, ['status' => WaOutbox::DEFERRED, 'due_at' => $decision->until, 'defer_reason' => $decision->reason, 'channel_id' => $channel->id]);

            return;
        }
        if ($decision->action !== 'allow') {
            $this->settle($item, ['status' => $decision->action === 'expire' ? WaOutbox::EXPIRED : WaOutbox::DENIED, 'defer_reason' => $decision->reason, 'channel_id' => $channel->id]);

            return;
        }

        $conv = $item->conversation_id ? $item->conversation : null;
        if ($conv === null || (int) $conv->channel_id !== (int) $channel->id) {
            $r = Recipient::resolve($channel, (string) $item->phone);
            if (!$r['ok']) {
                $this->settle($item, !empty($r['retry'])
                    ? ['status' => WaOutbox::DEFERRED, 'due_at' => now()->addMinutes(10), 'defer_reason' => $r['error'], 'channel_id' => $channel->id]
                    : ['status' => WaOutbox::FAILED, 'last_error' => $r['error'], 'channel_id' => $channel->id]);

                return;
            }
            $conv = $r['conversation'];
        }
        $cold = SendGovernor::isCold($item->setRelation('conversation', $conv)->forceFill(['conversation_id' => $conv->id]), $channel);

        $p = (array) $item->payload;
        // رسالة نظام: النص والمرفق من القالب والبيانات الآن، والمصدر الذي لم يعد ينطبق (سُدّد، تغيّر الموعد) يلغيها
        if (($p['kind'] ?? null) === 'system') {
            $r = \App\Services\WhatsApp\System\Renderer::render($item);
            if (!$r['ok']) {
                $this->settle($item, ['status' => WaOutbox::CANCELLED, 'defer_reason' => mb_substr((string) $r['reason'], 0, 160), 'channel_id' => $channel->id]);

                return;
            }
            $p = $r['attachment'] ? ['kind' => 'document', 'caption' => $r['text'], 'attachment' => $r['attachment']] : ['kind' => 'text', 'text' => $r['text']];
        }
        $message = $this->message($p);
        if ($message === null) {
            $this->settle($item, ['status' => WaOutbox::FAILED, 'last_error' => 'المرفق غير متاح', 'conversation_id' => $conv->id, 'channel_id' => $channel->id]);

            return;
        }
        [$m, $media] = $message;
        $res = WhatsAppChannel::send($conv, $m, 'system', null, [
            'send_key' => 'ob:'.$item->id, 'type_key' => $item->type_key, 'context' => 'system', 'media' => $media,
            'meta' => array_filter(['outbox_id' => $item->id, 'type_key' => $item->type_key, 'is_test' => $item->is_test ?: null]),
        ]);
        $row = isset($res['row_id']) ? (int) $res['row_id'] : null;
        if ($res['ok']) {
            $this->settle($item, ['status' => WaOutbox::SENT, 'sent_at' => now(), 'message_id' => $row, 'cold' => $cold,
                'conversation_id' => $conv->id, 'channel_id' => $channel->id, 'last_error' => null, 'defer_reason' => null]);
            WaLog::info('wa.outbox.sent', ['outbox_id' => $item->id, 'type_key' => $item->type_key, 'channel_id' => $channel->id]);

            return;
        }
        // الرقم لم يجهز أو قاطع أو إيقاف: يُؤجَّل ولا يُفشل
        $retry = in_array($res['code'] ?? '', ['CHANNEL_NOT_READY', 'SENDING_SUSPENDED', 'CHANNEL_PAUSED', 'GW_UNREACHABLE', 'GW_RATE', 'SEND_IN_PROGRESS'], true);
        $this->settle($item, $retry && $item->attempts < 6
            ? ['status' => WaOutbox::DEFERRED, 'due_at' => now()->addMinutes(5 * max(1, $item->attempts)), 'defer_reason' => (string) $res['error'], 'conversation_id' => $conv->id, 'channel_id' => $channel->id]
            : ['status' => ($res['unknown'] ?? false) ? WaOutbox::SENT : WaOutbox::FAILED, 'last_error' => mb_substr((string) $res['error'], 0, 500),
                'message_id' => $row, 'conversation_id' => $conv->id, 'channel_id' => $channel->id, 'sent_at' => ($res['unknown'] ?? false) ? now() : null]);
    }

    /** @return array{0: OutboundMessage, 1: ?array}|null */
    private function message(array $p): ?array
    {
        $kind = (string) ($p['kind'] ?? 'text');
        if ($kind === 'text') {
            return [OutboundMessage::text((string) ($p['text'] ?? '')), null];
        }
        $file = isset($p['attachment']) ? Attachments::make((array) $p['attachment']) : (isset($p['path']) ? ['path' => $p['path'], 'filename' => $p['filename'] ?? 'file', 'mime' => $p['mime'] ?? 'application/octet-stream'] : null);
        if ($file === null) {
            return null;
        }
        $id = 'local:'.$file['path'];
        $caption = isset($p['caption']) ? (string) $p['caption'] : (isset($p['text']) ? (string) $p['text'] : null);
        $m = str_starts_with((string) $file['mime'], 'image/') ? OutboundMessage::image($id, $caption) : OutboundMessage::document($id, (string) $file['filename'], $caption);

        return [$m, ['mime' => $file['mime'], 'sha256' => $file['sha256'] ?? null, 'size' => $file['size'] ?? null, 'filename' => $file['filename'], 'path' => $file['path']]];
    }

    private function settle(WaOutbox $item, array $update): void
    {
        WaOutbox::whereKey($item->id)->where('status', WaOutbox::SENDING)->update($update + ['lease_until' => null, 'updated_at' => now()]);
    }
}

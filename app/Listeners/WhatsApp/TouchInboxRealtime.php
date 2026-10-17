<?php

namespace App\Listeners\WhatsApp;

use App\Events\WhatsApp\ConversationHandedOff;
use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageSent;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Events\WhatsApp\WaMessageStored;
use App\Services\WhatsApp\Realtime\InboxBroadcaster;
use App\Services\WhatsApp\WaLog;

/**
 * من أحداث E21 إلى البث اللحظي للصندوق (E28): الوارد والصادر والفاشل «message»، والحالة «status»، والتحويل «meta».
 * ChannelHealthChanged لا يخص محادثة، فلا يصل قنوات المستخدمين (شريط الصندوق يقرأ الحالة عند الجلب).
 * علمه inbox_realtime وحده (يفحصه InboxBroadcaster)، لا wa_event_bus: الأحداث تُطلق دائماً. fail-open كمستمعي الناقل.
 * يُكتشف تلقائياً (docs/whatsapp/events.md)، فلا Event::listen يدوي له.
 */
final class TouchInboxRealtime
{
    public function handleStored(WaMessageStored $event): void
    {
        $this->touch($event, $event->conversationId, 'message');
    }

    public function handleSent(WaMessageSent $event): void
    {
        $this->touch($event, $event->conversationId, 'message');
    }

    /** الصف الفاشل يظهر في الخيط بعلامة ⚠️ */
    public function handleFailed(WaMessageFailed $event): void
    {
        $this->touch($event, $event->conversationId, 'message');
    }

    public function handleStatus(WaMessageStatusChanged $event): void
    {
        $this->touch($event, $event->conversationId, 'status');
    }

    public function handleHandedOff(ConversationHandedOff $event): void
    {
        $this->touch($event, $event->conversationId, 'meta');
    }

    private function touch(object $event, int $conversationId, string $kind): void
    {
        try {
            app(InboxBroadcaster::class)->touch($conversationId, $kind);
        } catch (\Throwable $e) {
            WaLog::error('wa.listener_failed', ['listener' => 'TouchInboxRealtime', 'event' => class_basename($event), 'type' => class_basename($e), 'conv_id' => $conversationId]);
        }
    }
}

<?php

namespace App\Listeners\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\HandoffReason;
use App\Events\WhatsApp\ConversationHandedOff;
use App\Models\AuditLog;
use App\Support\AuditActor;

/**
 * التدقيق من الناقل (E21): التحويل لموظف (WaHandoff، كان مباشراً في escalate). بالمعرّفات والرموز فقط، ويمر على
 * AuditRedactor. (تغيّر صحة قناة ميتا أُزيل معها؛ أحداث الأرقام في wa_channel_events)
 */
final class AuditWhatsAppEvents extends BusListener
{
    public function handleHandedOff(ConversationHandedOff $event): void
    {
        $this->guarded($event, fn () => AuditActor::as('bot', 'conv:'.$event->conversationId, fn () => AuditLog::safe(
            AuditAction::WaHandoff, 'wa_conversation', $event->conversationId, null, [
                'title' => (HandoffReason::tryFrom($event->reason) ?? HandoffReason::HandoffTool)->title(),
                'reason' => $event->reason, 'quote_request_id' => $event->quoteRequestId, 'assigned_to' => $event->assigneeId,
            ])));
    }
}

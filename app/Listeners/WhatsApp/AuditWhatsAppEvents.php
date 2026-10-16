<?php

namespace App\Listeners\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\HandoffReason;
use App\Events\WhatsApp\ChannelHealthChanged;
use App\Events\WhatsApp\ConversationHandedOff;
use App\Models\AuditLog;
use App\Support\AuditActor;

/**
 * التدقيق من الناقل (E21): التحويل لموظف (WaHandoff، كان مباشراً في escalate) وتغيّر صحة القناة (WaHealthChange).
 * بالمعرّفات والرموز فقط، ويمر الكل على AuditRedactor. صف WaSuspendAuto من الإيقاف الآلي يبقى كما هو حتى E22.
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

    /** الفاعل: المستخدم الحالي للإيقاف اليدوي، وميتا لما سواه */
    public function handleHealthChanged(ChannelHealthChanged $event): void
    {
        $write = fn () => AuditLog::safe(AuditAction::WaHealthChange, 'wa_channel', null, $event->to, [
            'from' => $event->from, 'to' => $event->to, 'source' => $event->source, 'reason_code' => $event->reasonCode,
        ]);
        $this->guarded($event, fn () => $event->source === 'manual' ? $write() : AuditActor::as('meta', $event->source, $write));
    }
}

<?php

namespace App\Listeners\WhatsApp;

use App\Enums\HandoffReason;
use App\Events\WhatsApp\ConversationHandedOff;
use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Services\WhatsApp\HandoffNotice;
use Illuminate\Support\Facades\Cache;

/**
 * إشعار التحويل للمدراء والموكَّل (منقول من BotTools::escalate، E21). تحويلان في التشغيل نفسه (حارس الادعاء بعد أداة
 * مثلاً) يعطيان إشعاراً واحداً خلال دقيقتين للمحادثة والطلب نفسيهما. نص دفع الجهاز عام (HandoffNotice).
 */
final class NotifyHandoffRecipients extends BusListener
{
    public function handle(ConversationHandedOff $event): void
    {
        $this->guarded($event, function () use ($event) {
            if (!Cache::add('wa:evt:handoff:'.$event->conversationId.':'.($event->quoteRequestId ?? 'x'), 1, now()->addSeconds(120))) {
                return;
            }
            $conv = WaConversation::find($event->conversationId);
            if (!$conv) {
                return;
            }
            $qr = $event->quoteRequestId ? QuoteRequest::find($event->quoteRequestId) : null;

            HandoffNotice::send($conv, $qr, HandoffReason::tryFrom($event->reason) ?? HandoffReason::HandoffTool, $event->assigneeId);
        });
    }
}

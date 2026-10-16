<?php

namespace App\Listeners\WhatsApp;

use App\Events\WhatsApp\WaMessageFailed;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\NotificationService;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Support\Facades\Cache;

/**
 * «لم تصل رسالتك» (E21): منقول من الإشعار المباشر الذي وضعته E18 T4 في مسار الحالة، وموسَّع للنظام. موضع قرار واحد:
 * مع wa_event_bus هذا المستمع وحده، وبدونه المسار المباشر وحده (StatusApplier::notifySenderDirect).
 *
 * - النطاق recipient أو message فقط (131047 و131026 و131056 و131050…). نطاق القناة (auth وintegrity) للمفاعل (E18) وE22.
 * - رسالة الموظف عبر الويبهوك وحده: فشلها المتزامن رآه المرسل في المنشئ فوراً.
 * - رسالة النظام عند فشلها المتزامن وحده: فشلها عبر الويبهوك يرفع «تدخّل» بإشعاره (markUnserved، E07). والبوت كذلك.
 * - المستلم: المرسل، وإلا الموكَّل، وإلا المدراء. مرة لكل رسالة (مفتاح E18 نفسه)، ومرة في 10 دقائق للمحادثة والرمز.
 */
final class NotifyDeliveryFailure extends BusListener
{
    public function handle(WaMessageFailed $event): void
    {
        $this->guarded($event, function () use ($event) {
            if (!in_array($event->scope, ['recipient', 'message'], true)) {
                return;
            }
            $staff = $event->source === 'staff' && $event->origin === 'webhook';
            $system = $event->source === 'system' && $event->origin === 'send';
            if (!$staff && !$system) {
                return;
            }
            if (!Cache::add("wa:delivery-failed:notify:{$event->messageId}", 1, now()->addDays(2))
                || !Cache::add('wa:failnote:conv:'.$event->conversationId.':'.($event->metaCode ?? 'x'), 1, now()->addMinutes(10))) {
                return;
            }
            $conv = WaConversation::find($event->conversationId);
            if (!$conv) {
                return;
            }

            $meaning = $event->metaCode !== null ? MetaErrorCatalog::classify($event->metaCode)
                : MetaErrorCatalog::byCode(WaMessage::whereKey($event->messageId)->value('error_code'));
            $reason = $meaning['message'] ?? 'لم تقبلها ميتا.';
            $action = $meaning['action'] ?? null;
            $title = $staff ? '⚠️ لم تصل رسالتك' : '⚠️ لم تصل رسالة النظام';
            $body = ($staff ? 'لم تصل رسالتك إلى ' : 'لم تصل رسالة النظام إلى ').$conv->noticeLabel().": {$reason}".($action ? " — {$action}" : '');
            $url = "/whatsapp/inbox?conv={$conv->id}";

            $to = $event->staffId ?? ($conv->assigned_to ? (int) $conv->assigned_to : null);
            $to !== null
                ? NotificationService::send($to, $title, $body, ['type' => 'whatsapp', 'icon' => '⚠️', 'action_url' => $url])
                : WhatsAppInbox::notifyAdmins($title, $body, $url);
        });
    }
}

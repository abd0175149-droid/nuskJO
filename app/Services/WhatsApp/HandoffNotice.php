<?php

namespace App\Services\WhatsApp;

use App\Enums\HandoffReason;
use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Services\NotificationService;

/**
 * إشعار التحويل للمدراء والموكَّل (منقول من BotTools::escalate كما هو): الجرس بالاسم بلا رقم ورقم الطلب، والتفاصيل
 * في صفحة الطلب. دفع الجهاز (FCM) بالعنوان الثابت وجسم «افتح النظام للتفاصيل» (E15.10، type=whatsapp)، فلا يمر
 * اسم العميل ولا رقم طلبه ولا مقتطف منه عبر طرف ثالث. يستدعيه escalate مباشرة والعلم wa_event_bus مطفأ، والمستمع
 * NotifyHandoffRecipients وهو مفعّل: موضع قرار واحد، فلا يصل إشعاران.
 */
final class HandoffNotice
{
    public static function send(WaConversation $conv, ?QuoteRequest $qr, HandoffReason $reason, ?int $assignee): void
    {
        $title = $reason->title();
        $who = $conv->noticeLabel();
        $body = $qr ? "{$who} — طلب {$qr->request_number}" : "{$who} — يحتاج تدخّلاً بشرياً.";
        $url = $qr ? '/quote-requests?status=all&search='.rawurlencode((string) $qr->request_number) : '/whatsapp/inbox?conv='.$conv->id;

        WhatsAppInbox::notifyAdmins($title, $body, $url);

        if ($assignee) {
            try {
                NotificationService::send($assignee, $title, $body, [
                    'type' => 'whatsapp', 'icon' => '💬', 'action_url' => $url,
                ]);
            } catch (\Throwable $e) {
                // الإشعار ليس حرجاً — الطلب محفوظ والشارة مرفوعة
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\WaConversation;
use App\Services\NotificationService;
use App\Services\WhatsApp\InboxFollowup;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Console\Command;

/**
 * المحادثات المؤجلة التي حان موعدها (E17): تعود «تدخّلاً» بسبب snooze_due، وإشعار واحد لمن أجّلها وإلا للموكَّل
 * (وإلا المدراء). النص اسم المحادثة بلا رقم ولا نص رسالة. لا تُمس المدموجة.
 */
class WakeSnoozedWhatsAppConversations extends Command
{
    protected $signature = 'whatsapp:snooze-wake';

    protected $description = 'إعادة المحادثات المؤجلة التي حان موعد متابعتها إلى «تدخّل»';

    public function handle(): int
    {
        if (!InboxFollowup::available()) {
            return self::SUCCESS;
        }

        $woken = 0;
        WaConversation::whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())
            ->where('status', '!=', 'merged')
            ->orderBy('id')
            ->each(function (WaConversation $c) use (&$woken) {
                // شرط الموعد في التحديث نفسه: تشغيلان متزامنان لا يشعران مرتين
                $claimed = WaConversation::whereKey($c->id)->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now())
                    ->update(['snoozed_until' => null, 'needs_attention' => true, 'attention_reason' => 'snooze_due']);
                if (!$claimed) {
                    return;
                }
                $woken++;
                // تحديث جماعي بلا حدث نموذج: البث اللحظي صراحةً (E28)
                $c->touchRealtime('meta');

                $title = '⏰ حان موعد متابعة';
                $body = '⏰ حان موعد متابعة '.$c->noticeLabel();
                $url = '/whatsapp/inbox?conv='.$c->id;
                $to = $c->snoozed_by ?: $c->assigned_to;
                if ($to) {
                    NotificationService::send((int) $to, $title, $body, ['type' => 'whatsapp', 'icon' => '⏰', 'action_url' => $url]);
                } else {
                    WhatsAppInbox::notifyAdmins($title, $body, $url);
                }
            });

        $this->info("أُعيدت {$woken} محادثة مؤجلة");

        return self::SUCCESS;
    }
}

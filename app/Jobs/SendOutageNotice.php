<?php

namespace App\Jobs;

use App\Models\WaConversation;
use App\Services\NotificationService;
use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * رسالة تحويل لعميل لم يصله رد (E12): بعد وظيفة رد فشلت نهائياً (failed())، أو إعادة واحدة لرسالة تحويل
 * خنقتها ميتا. ترسل فقط إن كانت آخر رسالة من العميل بلا رد بعدها والنافذة مفتوحة، ومرة واحدة لكل وارد.
 * ثم يُنبَّه الموكَّل وإلا المدراء (إن طُلب)، بلا اسم العميل ولا رقمه.
 */
class SendOutageNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $conversationId, public bool $notify = true) {}

    public function handle(): void
    {
        $conv = WaConversation::find($this->conversationId);
        if (!$conv) {
            return;
        }
        $lastIn = $conv->messages()->where('direction', 'in')->where('source', '!=', 'system')->where('msg_type', '!=', 'reaction')->orderByDesc('id')->first();
        $answered = $lastIn && $conv->messages()->where('direction', 'out')->where('id', '>', $lastIn->id)->where('status', '!=', 'failed')->exists();
        if (!$lastIn || $answered || !$conv->isWindowOpen()) {
            return;
        }
        if (!Cache::add("wa:outage-notice:{$conv->id}:{$lastIn->id}", 1, now()->addDay())) {
            return;
        }

        // المفتاح نفسه لإعادة الخنق المؤجلة: لا تتكرر رسالة التحويل إن نجحت الأولى متأخرة (E19)
        $res = WhatsAppChannel::sendText($conv, HandoffGuard::confirmation(HandoffGuard::isLatin((string) $lastIn->body)), 'system',
            sendKey: \App\Services\WhatsApp\SendKey::outage($conv, $lastIn->id));
        WaLog::warning('reply.outage_notice', ['conv_id' => $conv->id, 'status' => $res['ok'] ? 'sent' : 'failed', 'meta_code' => $res['meta_code'] ?? null]);

        if ($this->notify && Cache::add("wa:outage-notice:notify:{$conv->id}", 1, now()->addMinutes(30))) {
            $title = '⚠️ تعذّر رد البوت';
            $body = 'لم يكتمل رد البوت على عميل، ووصلته رسالة تحويل. ردّوا عليه يدوياً.';
            $url = "/whatsapp/inbox?conv={$conv->id}";
            $conv->assigned_to
                ? NotificationService::send((int) $conv->assigned_to, $title, $body, ['type' => 'whatsapp', 'icon' => '💬', 'action_url' => $url])
                : WhatsAppInbox::notifyAdmins($title, $body, $url);
        }
    }
}

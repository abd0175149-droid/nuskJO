<?php

namespace App\Jobs;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaChannel;
use App\Services\WhatsApp\EchoHandler;
use App\Services\WhatsApp\OpenWa\EventRouter;
use App\Services\WhatsApp\OpenWa\HistoryImport;
use App\Services\WhatsApp\OpenWa\InboundParser;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * بعد عودة رقم من انقطاع (ChannelState): ما وصل أثناءه ولم يصل webhookه. آخر 40 محادثة فردية × 15 رسالة منذ آخر وارد
 * ناقص دقيقتين، تمر بالمحلل نفسه، ومنع التكرار بالمعرّف (wamid). الصادر منها (fromMe) يمر بـ EchoHandler بوصفه تاريخاً:
 * إرسالنا القديم يُطابَق 24 ساعة، وما كتبه الهاتف قديماً لا يوقف البوت الآن. السجل الأول ليس هنا بل HistoryImport.
 * البوت يُجدول للمحادثات التي وصلها جديد، وبوابة الحداثة في ProcessWhatsAppReply تمنع الرد على القديم جداً.
 */
class BackfillChannel implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $channelId)
    {
        $this->onQueue('wa-system');
    }

    public function uniqueId(): string
    {
        return 'backfill:'.$this->channelId;
    }

    public function handle(ChannelTransport $transport): void
    {
        $channel = WaChannel::find($this->channelId);
        if (!$channel || !$channel->isReady()) {
            return;
        }
        // ليس استيراداً أول: رقم لم يُستورد سجله يبدأ الاستيراد (HistoryImport)، والجاري يكمل وحده
        if (HistoryImport::neverStarted($channel)) {
            if (config('whatsapp.openwa.import.auto', true)) {
                HistoryImport::start($channel, 'auto');
            }

            return;
        }
        if (HistoryImport::isRunning($channel)) {
            return;
        }
        $cfg = (array) config('whatsapp.openwa.backfill', []);
        $since = ($channel->last_inbound_at ?? now()->subHours(6))->copy()->subSeconds((int) ($cfg['overlap_seconds'] ?? 120))->getTimestamp();

        $chats = $transport->call($channel, 'GET', '/chats', null, 'send', [], ['limit' => (int) ($cfg['chats'] ?? 40)]);
        if (!$chats->ok) {
            WaLog::warning('openwa.backfill.failed', ['channel_id' => $channel->id, 'error_code' => $chats->error?->code]);

            return;
        }
        $list = $chats->json('chats', $chats->json('items', $chats->json()));
        $stored = 0;
        $touched = [];
        foreach ((array) $list as $chat) {
            $jid = (string) (is_array($chat['id'] ?? null) ? ($chat['id']['_serialized'] ?? '') : ($chat['id'] ?? $chat['chatId'] ?? ''));
            $ts = (int) ($chat['timestamp'] ?? data_get($chat, 'lastMessage.timestamp', 0));
            if (!InboundParser::isPerson($jid) || ($ts > 0 && $ts < $since)) {
                continue;
            }
            $history = $transport->call($channel, 'GET', '/messages/'.rawurlencode($jid).'/history', null, 'send', [],
                ['limit' => (int) ($cfg['messages'] ?? 15), 'inlineMedia' => 'false']);
            if (!$history->ok) {
                continue;
            }
            $messages = $history->json('messages', $history->json('items', $history->json()));
            foreach ((array) $messages as $m) {
                if (!is_array($m) || (int) ($m['timestamp'] ?? 0) < $since) {
                    continue;
                }
                $m['chatId'] ??= $jid;
                if (!empty($m['fromMe'])) {
                    EchoHandler::handleOpenWa($channel, $m, null, retried: true, historical: true);

                    continue;
                }
                $in = InboundParser::parse($m, $channel);
                if ($in === null) {
                    continue;
                }
                $convId = WhatsAppInbox::storeInbound($in, EventRouter::storable($m) + ['_backfill' => true]);
                $stored++;
                if ($convId) {
                    $touched[$convId] = true;
                }
            }
        }
        foreach (array_keys($touched) as $id) {
            rescue(fn () => ProcessWhatsAppReply::schedule($id), null, false);
        }
        $channel->logEvent('backfill', ['count' => $stored, 'touched' => count($touched)]);
        WaLog::info('openwa.backfill', ['channel_id' => $channel->id, 'count' => $stored]);
    }
}

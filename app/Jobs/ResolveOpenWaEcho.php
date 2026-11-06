<?php

namespace App\Jobs;

use App\Models\WaChannel;
use App\Services\WhatsApp\EchoHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * صدى fromMe وصل وإرسالنا للمحادثة نفسها ما زال قيد الطيران: بعد ثوانٍ يُحسم مرة ثانية وأخيرة (EchoHandler)، فإما
 * يُطابَق بصفنا وإما يُحفظ رسالة من الهاتف. الحمولة بلا وسائط (EventRouter::storable).
 */
class ResolveOpenWaEcho implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $channelId, public array $data)
    {
        $this->onQueue('wa-replies');
    }

    public function handle(): void
    {
        $channel = WaChannel::find($this->channelId);
        if ($channel !== null) {
            EchoHandler::handleOpenWa($channel, $this->data, null, retried: true);
        }
    }
}

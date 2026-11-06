<?php

namespace App\Console\Commands;

use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Services\WhatsApp\ContactIdentity;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;

/**
 * محادثات بمرسل خلف معرّف خصوصية (@lid) لم يُعرف هاتفه بعد: GET /contacts/{lid}/phone على رقم المحادثة. الهاتف المعروف
 * يملأ المحادثة ويربطها بالعميل (ContactIdentity، بالقاعدة نفسها للوارد: إن لم تحجزه محادثة أخرى على الرقم نفسه).
 * كل 30 دقيقة (routes/console.php)، 50 محادثة في الدورة، ونوم 30 ثانية عند 429.
 */
class WhatsAppResolveLids extends Command
{
    protected $signature = 'whatsapp:resolve-lids {--limit=50}';

    protected $description = 'معرفة هواتف محادثات واتساب التي وصلت بمعرّف خصوصية (@lid)';

    public function handle(ChannelTransport $transport): int
    {
        $convs = WaConversation::query()->whereNotNull('lid')->whereNull('phone')->whereNotNull('channel_id')
            ->where('status', '!=', 'merged')->orderByDesc('last_message_at')->limit(max(1, (int) $this->option('limit')))->get();
        $channels = WaChannel::query()->whereIn('id', $convs->pluck('channel_id')->unique())->get()->keyBy('id');
        $found = 0;
        foreach ($convs as $conv) {
            $ch = $channels[$conv->channel_id] ?? null;
            if (!$ch || !$ch->isReady()) {
                continue;
            }
            $res = $transport->call($ch, 'GET', '/contacts/'.rawurlencode((string) $conv->lid).'/phone');
            if ($res->status() === 429) {
                \Illuminate\Support\Sleep::for(30)->seconds();

                continue;
            }
            $phone = PhoneNormalizer::digits((string) ($res->json('phone') ?? $res->json('number') ?? ''));
            if (!$res->ok || $phone === '') {
                continue;
            }
            ContactIdentity::resolveIdentity($phone, null, null, channelId: (int) $ch->id, lid: (string) $conv->lid);
            $found++;
        }
        WaLog::info('openwa.lids', ['count' => $found]);
        $this->info("resolved {$found}");

        return self::SUCCESS;
    }
}

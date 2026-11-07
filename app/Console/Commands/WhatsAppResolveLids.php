<?php

namespace App\Console\Commands;

use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Services\WhatsApp\ContactIdentity;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * محادثات بمرسل خلف معرّف خصوصية (@lid) لم يُعرف هاتفه بعد: GET /contacts/{lid}/phone على رقم المحادثة. الهاتف المعروف
 * يملأ المحادثة ويربطها بالعميل (ContactIdentity، بالقاعدة نفسها للوارد: إن لم تحجزه محادثة أخرى على الرقم نفسه).
 * كل 30 دقيقة (routes/console.php)، 200 محادثة في الدورة، ونوم 30 ثانية عند 429. ما لم تعرف البوابة رقمه يُتخطى يوماً
 * (MISSES في الكاش)، فلا تحجز المحادثات التي لا رقم لها رأس القائمة عن البقية.
 */
class WhatsAppResolveLids extends Command
{
    protected $signature = 'whatsapp:resolve-lids {--limit=200}';

    private const MISSES = 'wa:lid-misses';

    protected $description = 'معرفة هواتف محادثات واتساب التي وصلت بمعرّف خصوصية (@lid)';

    public function handle(ChannelTransport $transport): int
    {
        // معرّف المحادثة => وقت آخر محاولة بلا رقم
        $misses = array_filter((array) Cache::get(self::MISSES, []), fn ($at) => $at > now()->subDay()->getTimestamp());
        $convs = WaConversation::query()->whereNotNull('lid')->whereNull('phone')->whereNotNull('channel_id')
            ->where('status', '!=', 'merged')->when($misses !== [], fn ($q) => $q->whereNotIn('id', array_keys($misses)))
            ->orderByDesc('last_message_at')->limit(max(1, (int) $this->option('limit')))->get();
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
                $misses[$conv->id] = now()->getTimestamp();

                continue;
            }
            ContactIdentity::resolveIdentity($phone, null, null, channelId: (int) $ch->id, lid: (string) $conv->lid);
            $found++;
        }
        Cache::put(self::MISSES, $misses, now()->addDay());
        WaLog::info('openwa.lids', ['count' => $found]);
        $this->info("resolved {$found}");

        return self::SUCCESS;
    }
}

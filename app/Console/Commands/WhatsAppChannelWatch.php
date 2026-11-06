<?php

namespace App\Console\Commands;

use App\Events\WhatsApp\ChannelStatusChanged;
use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\ChannelState;
use App\Services\WhatsApp\OpenWa\SessionManager;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;

/**
 * صحة كل رقم كل دقيقتين (routes/console.php): الحالة من البوابة، ثم فحص حقيقي بـ contacts/check على رقم الرقم نفسه.
 * «ready» وحدها لا تكفي: الجلسة قد تبقى ready وهي عالقة بعد إعادة تشغيل (درس WB web).
 * - نجاح: last_probe_ok_at، وتنبيه «عاد» إن كان قد انقطع.
 * - إخفاق: probe_failures++، وبعد 3 إعادة تشغيل للجلسة (مرة كل 30 دقيقة على الأكثر) وتنبيه الانقطاع.
 */
class WhatsAppChannelWatch extends Command
{
    protected $signature = 'whatsapp:channel-watch {--channel= : رقم صف واحد}';

    protected $description = 'فحص حقيقي لكل رقم واتساب على البوابة وإعادة تشغيل العالق';

    public function handle(ChannelTransport $transport): int
    {
        $channels = WaChannel::query()->active()->whereNotNull('openwa_session_id')
            ->when($this->option('channel'), fn ($q, $id) => $q->whereKey((int) $id))->get();
        foreach ($channels as $ch) {
            try {
                $this->watch($ch, $transport);
            } catch (\Throwable $e) {
                WaLog::error('openwa.watch.failed', ['channel_id' => $ch->id, 'exception' => $e]);
            }
        }

        return self::SUCCESS;
    }

    private function watch(WaChannel $ch, ChannelTransport $transport): void
    {
        $session = SessionManager::refresh($ch);
        $ch->refresh();
        if ($session === null || !$ch->isLinked()) {
            // غير مربوط (QR أو منقطع): لا فحص، والحالة من البوابة. جلسة لا تعمل وكانت مربوطة: تشغيل
            if ($session !== null && in_array($ch->status, ['disconnected', 'failed'], true) && $ch->phone !== null) {
                $this->failed($ch, 'status:'.$ch->status);
            }
            $this->line("{$ch->name}: {$ch->status}");

            return;
        }

        $res = $transport->call($ch, 'GET', '/contacts/check/'.$ch->ownDigits());
        $ok = $res->ok && ($res->json('exists') !== null || $res->json('number') !== null || $res->json('whatsappId') !== null);
        if ($ok) {
            $wasDown = $ch->probe_failures >= (int) config('whatsapp.openwa.probe_failures_restart', 3);
            $ch->forceFill(['last_probe_ok_at' => now(), 'probe_failures' => 0])->save();
            if ($wasDown) {
                $ch->logEvent('probe_ok');
                ChannelState::alert($ch, 'up', '✅ عاد رقم واتساب', "الرقم «{$ch->name}» متصل من جديد، والرسائل المؤجلة تخرج تباعاً.");
                event(new ChannelStatusChanged($ch->id, (string) $ch->status));
            }
            $this->line("{$ch->name}: ok");

            return;
        }
        $this->failed($ch, (string) ($res->error?->code ?? 'probe'));
    }

    private function failed(WaChannel $ch, string $why): void
    {
        $n = $ch->probe_failures + 1;
        $ch->forceFill(['probe_failures' => min($n, 65535)])->save();
        $limit = (int) config('whatsapp.openwa.probe_failures_restart', 3);
        $this->warn("{$ch->name}: probe failed ({$why}) #{$n}");
        if ($n === $limit) {
            $ch->logEvent('probe_failed', ['count' => $n, 'why' => mb_substr($why, 0, 40)]);
            ChannelState::alert($ch, 'down', '📵 رقم واتساب لا يستجيب',
                "الرقم «{$ch->name}» لم يجتز الفحص {$n} مرات. أُعيد تشغيل جلسته، وإن استمر فافتح صفحة الأرقام وأعد ربطه.");
            event(new ChannelStatusChanged($ch->id, (string) $ch->status));
        }
        $cooldown = now()->subMinutes((int) config('whatsapp.openwa.restart_cooldown_minutes', 30));
        if ($n >= $limit && ($ch->last_restart_at === null || $ch->last_restart_at->lt($cooldown))) {
            SessionManager::restart($ch, 'probe');
        }
    }
}

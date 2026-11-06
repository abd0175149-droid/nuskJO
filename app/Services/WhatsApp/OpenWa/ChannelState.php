<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Events\WhatsApp\ChannelStatusChanged;
use App\Jobs\BackfillChannel;
use App\Models\WaChannel;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Support\Facades\Cache;

/**
 * أحداث session.* إلى wa_channels.status وسجل الجلسة، وبث ChannelStatusChanged لصفحة الأرقام.
 *
 * - session.qr: QR في الكاش 60 ثانية (لا في القاعدة)، وبث بلا QR.
 * - session.authenticated: الرقم واسمه، والحالة ready، واسترجاع ما فات إن كان عودة بعد انقطاع.
 * - session.disconnected / reconnect_loop: الحالة والسجل وتنبيه الإدارة (مرة في الساعة لكل رقم).
 * - session.restriction: علامة حظر محتمل من واتساب. الرقم يُوقف فوراً (paused_until) وتنبيه عاجل.
 */
final class ChannelState
{
    public static function handle(WaChannel $channel, string $event, array $data): void
    {
        match ($event) {
            'session.qr' => self::qr($channel, $data),
            'session.authenticated' => self::authenticated($channel, $data),
            'session.status' => self::applyStatus($channel, (string) ($data['status'] ?? ''), $data),
            'session.disconnected' => self::disconnected($channel, (string) ($data['reason'] ?? '')),
            'session.reconnect_loop' => self::reconnectLoop($channel, $data),
            'session.restriction' => self::restriction($channel, $data),
            default => null,
        };
    }

    /** الحالة من حدث أو من قراءة الجلسة. quiet: بلا سجل ولا تنبيه (refresh) */
    public static function applyStatus(WaChannel $channel, string $status, array $data = [], bool $quiet = false): void
    {
        $status = strtolower($status);
        if (!in_array($status, WaChannel::STATUSES, true)) {
            return;
        }
        $before = (string) $channel->status;
        $update = ['status' => $status];
        if ($status !== $before) {
            $update['status_at'] = now();
        }
        $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? '')) ?? '';
        if ($phone !== '') {
            $update['phone'] = $phone;
            $update += $channel->warmup_started_at ? [] : ['warmup_started_at' => now()];
        }
        if (filled($data['pushName'] ?? null)) {
            $update['push_name'] = mb_substr((string) $data['pushName'], 0, 120);
        }
        if ($status === 'ready') {
            Cache::forget(SessionManager::qrKey($channel));
        }
        $channel->forceFill($update)->save();
        if ($status === $before) {
            return;
        }
        if (!$quiet) {
            $channel->logEvent($status === 'ready' ? 'ready' : 'status', ['from' => $before, 'to' => $status]);
        }
        WaLog::info('openwa.channel.status', ['channel_id' => $channel->id, 'from' => $before, 'to' => $status]);
        event(new ChannelStatusChanged($channel->id, $status));
        // عودة بعد انقطاع: ما وصل أثناءه
        if ($status === 'ready' && in_array($before, ['disconnected', 'initializing', 'authenticating', 'failed'], true) && $channel->last_inbound_at) {
            BackfillChannel::dispatch($channel->id)->delay(now()->addSeconds(20));
        }
        if (!$quiet && $before === 'ready' && in_array($status, ['disconnected', 'failed', 'action_required'], true)) {
            self::alert($channel, 'down', '📵 انقطع رقم واتساب', "الرقم «{$channel->name}» لم يعد متصلاً ({$status}). الرسائل الآلية تتأجل حتى يعود.");
        }
    }

    private static function qr(WaChannel $channel, array $data): void
    {
        $qr = (string) ($data['qr'] ?? $data['qrCode'] ?? '');
        if ($qr === '') {
            return;
        }
        Cache::put(SessionManager::qrKey($channel), $qr, now()->addSeconds((int) config('whatsapp.openwa.qr_ttl', 60)));
        if ($channel->status !== 'qr_ready') {
            $channel->forceFill(['status' => 'qr_ready', 'status_at' => now()])->save();
        }
        event(new ChannelStatusChanged($channel->id, 'qr_ready', qr: true));
    }

    private static function authenticated(WaChannel $channel, array $data): void
    {
        $channel->logEvent('authenticated');
        self::applyStatus($channel, 'ready', $data);
        $channel->forceFill(['probe_failures' => 0, 'last_probe_ok_at' => now()])->save();
    }

    private static function disconnected(WaChannel $channel, string $reason): void
    {
        $channel->logEvent('disconnected', ['reason' => mb_substr($reason, 0, 80)]);
        self::applyStatus($channel, 'disconnected');
    }

    private static function reconnectLoop(WaChannel $channel, array $data): void
    {
        $channel->logEvent('reconnect_loop', array_intersect_key($data, array_flip(['attempts', 'reason'])));
        self::alert($channel, 'loop', '🔁 رقم واتساب يعيد الاتصال باستمرار', "الرقم «{$channel->name}» لا يستقر. افتح صفحة الأرقام وأعد ربطه إن لزم.");
    }

    private static function restriction(WaChannel $channel, array $data): void
    {
        $channel->forceFill(['paused_until' => now()->addDays(7), 'pause_reason' => 'restriction'])->save();
        $channel->logEvent('restriction', array_intersect_key($data, array_flip(['type', 'reason', 'until', 'kind'])));
        WaLog::warning('openwa.channel.restriction', ['channel_id' => $channel->id]);
        // عاجل: لا يُطوى في الساعة
        WhatsAppInbox::notifyAdmins('⛔ واتساب قيّد رقماً — أُوقف الإرسال منه',
            "وصلت علامة تقييد للرقم «{$channel->name}». أُوقف كل إرسال منه، الآلي والردود. راجع الهاتف قبل رفع الإيقاف من صفحة الأرقام.",
            '/whatsapp/channels', 'RB-03');
        event(new ChannelStatusChanged($channel->id, (string) $channel->status));
    }

    public static function alert(WaChannel $channel, string $kind, string $title, string $body): void
    {
        if (Cache::add("wa:openwa:alert:{$kind}:{$channel->id}", 1, now()->addHour())) {
            WhatsAppInbox::notifyAdmins($title, $body, '/whatsapp/channels', 'RB-12');
        }
    }
}

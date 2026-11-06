<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaMessage;
use App\Services\WhatsApp\OpenWa\HistoryImport;
use App\Services\WhatsApp\OpenWa\SessionManager;
use App\Services\WhatsApp\Sending\FailureBreaker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة أرقام واتساب (/whatsapp/channels، صلاحية whatsapp.channels): الإضافة والربط بـ QR أو كود، والحالة اللحظية
 * (ChannelStatusChanged)، والأوامر (تشغيل، إعادة تشغيل، إيقاف مؤقت، فك ربط، حذف)، والأدوار والموظفون واللون.
 * لا مفتاح ولا سر في أي رد (hidden في النموذج). كل تغيير في سجل التدقيق.
 */
class WhatsAppChannelController extends Controller
{
    public const COMMANDS = ['start', 'restart', 'stop', 'logout', 'repair', 'pause', 'resume', 'reset_breaker', 'import_start', 'import_stop'];

    public function index()
    {
        return Inertia::render('WhatsApp/Channels', [
            'channels' => WaChannel::query()->orderBy('sort')->orderBy('id')->get()->map(fn (WaChannel $c) => $this->present($c))->values(),
            'staff' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'roles' => WaChannel::ROLES,
            'botModes' => WaChannel::BOT_MODES,
            'palette' => WaChannel::PALETTE,
        ]);
    }

    /** للصفحة بعد كل حدث بث: حالة كل الأرقام بلا إعادة تحميل */
    public function list()
    {
        return response()->json(['channels' => WaChannel::query()->orderBy('sort')->orderBy('id')->get()->map(fn (WaChannel $c) => $this->present($c))->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'roles' => ['array'], 'roles.*' => [Rule::in(WaChannel::ROLES)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $ch = WaChannel::create([
            'name' => trim($data['name']), 'roles' => array_values(array_unique($data['roles'] ?? ['sales'])),
            'color' => $data['color'] ?? WaChannel::nextColor(), 'sort' => (int) WaChannel::max('sort') + 1,
        ]);
        AuditLog::safe(AuditAction::WaChannelChange, 'wa_channel', $ch->id, null, ['action' => 'create', 'name' => $ch->name]);
        $res = SessionManager::provision($ch);
        if ($res['ok']) {
            $res = SessionManager::start($ch->refresh());
        }

        return response()->json(['ok' => $res['ok'], 'message' => $res['message'] ?? null, 'channel' => $this->present($ch->refresh())], $res['ok'] ? 200 : 422);
    }

    public function update(Request $request, WaChannel $channel)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'roles' => ['sometimes', 'array'], 'roles.*' => [Rule::in(WaChannel::ROLES)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bot_mode' => ['sometimes', Rule::in(WaChannel::BOT_MODES)],
            'is_default_for' => ['sometimes', 'array'], 'is_default_for.*' => [Rule::in(['invoice', 'trip', 'quote', 'lead', 'admin'])],
            'users' => ['sometimes', 'array'], 'users.*.id' => ['integer', 'exists:users,id'], 'users.*.primary' => ['boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);
        $before = $channel->only(['name', 'roles', 'color', 'bot_mode', 'is_default_for']);
        $channel->fill(array_intersect_key($data, array_flip(['name', 'roles', 'color', 'bot_mode', 'is_default_for', 'sort'])))->save();
        if (array_key_exists('users', $data)) {
            $sync = [];
            foreach ($data['users'] as $u) {
                $sync[(int) $u['id']] = ['is_primary' => (bool) ($u['primary'] ?? false)];
            }
            $channel->users()->sync($sync);
        }
        AuditLog::safe(AuditAction::WaChannelChange, 'wa_channel', $channel->id, null, ['action' => 'update', 'before' => $before,
            'after' => $channel->only(['name', 'roles', 'color', 'bot_mode', 'is_default_for']), 'users' => isset($data['users']) ? count($data['users']) : null]);

        return response()->json(['ok' => true, 'channel' => $this->present($channel->refresh())]);
    }

    /** QR الحالي (يستطلعه حوار الربط كل 3 ثوانٍ؛ البث يسرّع الطلب) */
    public function qr(WaChannel $channel)
    {
        $res = SessionManager::qr($channel);

        return response()->json($res + ['channel' => $this->present($channel->refresh())], $res['ok'] ? 200 : 422);
    }

    public function pairing(Request $request, WaChannel $channel)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $res = SessionManager::pairingCode($channel, $data['phone']);
        AuditLog::safe(AuditAction::WaChannelChange, 'wa_channel', $channel->id, null, ['action' => 'pairing_code', 'ok' => $res['ok']]);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** start | restart | stop | logout | repair | pause | resume | reset_breaker | import_start | import_stop */
    public function command(Request $request, WaChannel $channel, string $command)
    {
        abort_unless(in_array($command, self::COMMANDS, true), 404);
        $data = $request->validate(['minutes' => ['nullable', 'integer', 'min:5', 'max:10080'], 'reason' => ['nullable', 'string', 'max:160']]);
        $res = match ($command) {
            'start' => SessionManager::provision($channel)['ok'] ? SessionManager::start($channel->refresh()) : SessionManager::provision($channel),
            'restart' => SessionManager::restart($channel, 'manual'),
            'stop' => SessionManager::stop($channel),
            'logout' => SessionManager::logout($channel),
            'repair' => SessionManager::repair($channel),
            'pause' => $this->pause($channel, (int) ($data['minutes'] ?? 60), (string) ($data['reason'] ?? 'manual')),
            'resume' => $this->resume($channel),
            'reset_breaker' => (function () use ($channel) {
                FailureBreaker::reset($channel);

                return ['ok' => true];
            })(),
            // استيراد سجل الرقم (HistoryImport): بدء أو استئناف من المؤشر، وإيقاف
            'import_start' => HistoryImport::start($channel, 'manual:'.$request->user()->id),
            'import_stop' => HistoryImport::stop($channel),
        };
        AuditLog::safe(AuditAction::WaChannelChange, 'wa_channel', $channel->id, null, ['action' => $command, 'ok' => $res['ok'], 'code' => $res['code'] ?? null]);

        return response()->json($res + ['channel' => $this->present($channel->refresh())], $res['ok'] ? 200 : 422);
    }

    /** سجل الجلسة: آخر 50 حدثاً */
    public function events(WaChannel $channel)
    {
        return response()->json(['events' => $channel->events()->orderByDesc('id')->limit(50)->get(['id', 'event', 'detail', 'created_at'])]);
    }

    public function destroy(WaChannel $channel)
    {
        $res = SessionManager::destroy($channel);
        AuditLog::safe(AuditAction::WaChannelChange, 'wa_channel', $channel->id, null, ['action' => 'destroy', 'ok' => $res['ok']]);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    private function pause(WaChannel $channel, int $minutes, string $reason): array
    {
        $channel->forceFill(['paused_until' => now()->addMinutes($minutes), 'pause_reason' => mb_substr($reason, 0, 160)])->save();
        $channel->logEvent('paused', ['minutes' => $minutes]);

        return ['ok' => true];
    }

    private function resume(WaChannel $channel): array
    {
        $channel->forceFill(['paused_until' => null, 'pause_reason' => null])->save();
        $channel->logEvent('resumed');

        return ['ok' => true];
    }

    private function present(WaChannel $c): array
    {
        $today = now()->startOfDay();

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'push_name' => $c->push_name,
            'color' => $c->color,
            'status' => $c->status,
            'status_at' => $c->status_at?->toIso8601String(),
            'linked' => $c->isLinked(),
            'ready' => $c->isReady(),
            'provisioned' => $c->openwa_session_id !== null && $c->api_key !== null && $c->webhook_id !== null,
            'last_probe_ok_at' => $c->last_probe_ok_at?->toIso8601String(),
            'probe_failures' => $c->probe_failures,
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'roles' => (array) $c->roles,
            'bot_mode' => $c->bot_mode,
            'is_default_for' => (array) $c->is_default_for,
            'paused_until' => $c->isPaused() ? $c->paused_until?->toIso8601String() : null,
            'pause_reason' => $c->isPaused() ? $c->pause_reason : null,
            'breaker_until' => FailureBreaker::until($c)?->toIso8601String(),
            'warmup_started_at' => $c->warmup_started_at?->toIso8601String(),
            'is_active' => $c->is_active,
            'users' => $c->users()->get(['users.id', 'users.name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'primary' => (bool) $u->pivot->is_primary])->values(),
            'today' => [
                'in' => WaMessage::where('channel_id', $c->id)->where('direction', 'in')->where('created_at', '>=', $today)->count(),
                'out' => WaMessage::where('channel_id', $c->id)->where('direction', 'out')->whereNotIn('status', ['failed', 'blocked'])->where('created_at', '>=', $today)->count(),
                'failed' => WaMessage::where('channel_id', $c->id)->where('direction', 'out')->where('status', 'failed')->where('created_at', '>=', $today)->count(),
            ],
            'conversations' => $c->conversations()->where('status', '!=', 'merged')->count(),
            'history' => HistoryImport::present($c),
        ];
    }
}

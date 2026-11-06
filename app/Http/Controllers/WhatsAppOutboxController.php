<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaChannel;
use App\Models\WaOutbox;
use App\Services\WhatsApp\System\Catalog;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * صفحة طابور الإرسال (/whatsapp/outbox، whatsapp.outbox): القادم والمؤجل وسببه والمرسل والفاشل، والإجراءات:
 * «أرسل الآن» (تجاوز الموعد لا الحدود)، والإلغاء، وإعادة المحاولة، واعتماد ما ينتظر الموافقة. صفحات بالمؤشر (cursor).
 * رقم العميل لا يظهر كاملاً: آخر 4 أرقام والمحادثة والعميل.
 */
class WhatsAppOutboxController extends Controller
{
    private const TABS = [
        'upcoming' => [WaOutbox::PENDING],
        'deferred' => [WaOutbox::DEFERRED, WaOutbox::SENDING],
        'approval' => [WaOutbox::AWAITING],
        'sent' => [WaOutbox::SENT],
        'problems' => [WaOutbox::FAILED, WaOutbox::EXPIRED, WaOutbox::DENIED],
        'cancelled' => [WaOutbox::CANCELLED],
    ];

    public function index()
    {
        $counts = WaOutbox::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('WhatsApp/Outbox', [
            'counts' => collect(self::TABS)->map(fn ($s) => (int) collect($s)->sum(fn ($x) => $counts[$x] ?? 0)),
            'channels' => WaChannel::query()->orderBy('sort')->get(['id', 'name', 'color']),
            'types' => collect(Catalog::TYPES)->map(fn ($t) => $t['label']),
        ]);
    }

    public function data(Request $request)
    {
        $data = $request->validate([
            'tab' => ['nullable', 'in:'.implode(',', array_keys(self::TABS))],
            'channel_id' => ['nullable', 'integer'], 'type_key' => ['nullable', 'string', 'max:40'],
            'test' => ['nullable', 'in:0,1'], 'before' => ['nullable', 'integer'],
        ]);
        $tab = $data['tab'] ?? 'upcoming';
        $q = WaOutbox::query()->with(['client:id,name,code', 'channel:id,name,color'])->whereIn('status', self::TABS[$tab])
            ->when($data['channel_id'] ?? null, fn ($w, $id) => $w->where('channel_id', $id))
            ->when($data['type_key'] ?? null, fn ($w, $k) => $w->where('type_key', $k))
            ->when(isset($data['test']), fn ($w) => $w->where('is_test', $data['test'] === '1'))
            ->when($data['before'] ?? null, fn ($w, $id) => $w->where('id', '<', $id));
        in_array($tab, ['upcoming', 'deferred', 'approval'], true) ? $q->orderBy('due_at')->orderBy('id') : $q->orderByDesc('id');
        $rows = $q->limit(51)->get();

        return response()->json([
            'rows' => $rows->take(50)->map(fn (WaOutbox $o) => [
                'id' => $o->id, 'type_key' => $o->type_key, 'type' => Catalog::label((string) $o->type_key), 'status' => $o->status,
                'channel' => $o->channel ? ['id' => $o->channel->id, 'name' => $o->channel->name, 'color' => $o->channel->color] : null,
                'client' => $o->client ? ['id' => $o->client->id, 'name' => $o->client->name, 'code' => $o->client->code] : null,
                'phone_tail' => $o->phone ? substr($o->phone, -4) : null, 'conversation_id' => $o->conversation_id,
                'due_at' => $o->due_at?->toIso8601String(), 'sent_at' => $o->sent_at?->toIso8601String(), 'expires_at' => $o->expires_at?->toIso8601String(),
                'reason' => $o->defer_reason, 'error' => $o->last_error, 'attempts' => $o->attempts, 'is_test' => $o->is_test, 'priority' => $o->priority,
                'source' => $o->source_type ? $o->source_type.'#'.$o->source_id : null, 'message_id' => $o->message_id,
            ])->values(),
            'next' => $rows->count() > 50 ? $rows[49]->id : null,
        ]);
    }

    /** send_now | cancel | retry | approve */
    public function action(Request $request, WaOutbox $outbox, string $action)
    {
        abort_unless(in_array($action, ['send_now', 'cancel', 'retry', 'approve'], true), 404);
        $user = $request->user();
        $moved = match ($action) {
            'send_now' => WaOutbox::whereKey($outbox->id)->whereIn('status', [WaOutbox::PENDING, WaOutbox::DEFERRED])->update(['due_at' => now(), 'defer_reason' => null, 'updated_at' => now()]),
            'cancel' => WaOutbox::whereKey($outbox->id)->whereIn('status', WaOutbox::OPEN)->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => 'ألغاه '.$user->name, 'updated_at' => now()]),
            'retry' => WaOutbox::whereKey($outbox->id)->whereIn('status', [WaOutbox::FAILED, WaOutbox::EXPIRED])->update([
                'status' => WaOutbox::PENDING, 'due_at' => now(), 'expires_at' => now()->addHours(6), 'last_error' => null, 'defer_reason' => null, 'attempts' => 0, 'updated_at' => now()]),
            'approve' => WaOutbox::whereKey($outbox->id)->where('status', WaOutbox::AWAITING)->update([
                'status' => WaOutbox::PENDING, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_at' => now()]),
        };
        AuditLog::safe(AuditAction::WaOutboxAction, 'wa_outbox', $outbox->id, $action, ['type_key' => $outbox->type_key, 'moved' => $moved]);
        if ($moved === 0) {
            return response()->json(['ok' => false, 'error' => 'تغيّرت حالة الرسالة — حدّث الصفحة.'], 409);
        }

        return response()->json(['ok' => true]);
    }
}

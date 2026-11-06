<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaSendLimit;
use App\Services\WhatsApp\Sending\LimitResolver;
use App\Services\WhatsApp\Sending\UsageCounter;
use App\Services\WhatsApp\Sending\WarmupLadder;
use App\Services\WhatsApp\System\Catalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة حدود الإرسال (/whatsapp/limits، whatsapp.settings): مصفوفة الأرقام × الأنواع، وسلّم التسخين، والاستهلاك اليوم.
 * الحدود للإرسال الاستباقي وحده (wa_outbox). الردود لا تخضع لها. كل حفظ في سجل التدقيق.
 */
class WhatsAppLimitsController extends Controller
{
    public function index()
    {
        $channels = WaChannel::query()->orderBy('sort')->orderBy('id')->get();
        $today = UsageCounter::dayStart(now());

        return Inertia::render('WhatsApp/Limits', [
            'rows' => WaSendLimit::query()->get()->map(fn (WaSendLimit $l) => ['channel_id' => $l->channel_id, 'type_key' => $l->type_key] + $l->only(WaSendLimit::FIELDS))->values(),
            'defaults' => WaSendLimit::DEFAULTS,
            'channels' => $channels->map(fn (WaChannel $c) => [
                'id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'ready' => $c->isReady(),
                'warmup_started_at' => $c->warmup_started_at?->toDateString(),
                'warmup_cap' => WarmupLadder::capFor($c),
                'used_today' => UsageCounter::sent((int) $c->id, null, $today),
                'effective' => LimitResolver::for((int) $c->id, null),
            ])->values(),
            'types' => collect(Catalog::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t['label'], 'group' => $t['group']])->values(),
            'groups' => Catalog::GROUPS,
            'ladder' => WarmupLadder::ladder(),
        ]);
    }

    /** حفظ صف واحد من المصفوفة: الفارغ يرث من الأعم */
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'channel_id' => ['nullable', 'integer', 'exists:wa_channels,id'],
            'type_key' => ['nullable', 'string', Rule::in(array_keys(Catalog::TYPES))],
            'per_minute' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'per_hour' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'per_day' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'cold_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'per_recipient_day' => ['nullable', 'integer', 'min:0', 'max:50'],
            'min_gap_sec' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'jitter_pct' => ['nullable', 'integer', 'min:0', 'max:90'],
            'spread' => ['nullable', 'boolean'],
            'window_from' => ['nullable', 'integer', 'min:0', 'max:23'],
            'window_to' => ['nullable', 'integer', 'min:1', 'max:24'],
            'weekdays' => ['nullable', 'array'], 'weekdays.*' => ['integer', 'between:1,7'],
            'remove' => ['sometimes', 'boolean'],
        ]);
        $key = ['channel_id' => $data['channel_id'] ?? null, 'type_key' => $data['type_key'] ?? null];
        $isBase = $key['channel_id'] === null && $key['type_key'] === null;
        $row = WaSendLimit::query()->where($key)->first();
        if (!empty($data['remove'])) {
            abort_if($isBase, 422, 'الصف الأساسي لا يُحذف.');
            $row?->delete();
        } else {
            $values = array_intersect_key($data, array_flip(WaSendLimit::FIELDS));
            if (isset($values['window_from'], $values['window_to']) && $values['window_from'] >= $values['window_to']) {
                return response()->json(['ok' => false, 'error' => 'بداية النافذة قبل نهايتها.'], 422);
            }
            $row ??= new WaSendLimit($key);
            $row->fill($values + ['updated_by' => $request->user()->id])->save();
        }
        LimitResolver::flush();
        AuditLog::safe(AuditAction::WaSendLimitsUpdate, 'wa_send_limit', $row?->id, null, $key + ['removed' => !empty($data['remove'])]);

        return response()->json(['ok' => true]);
    }

    public function ladder(Request $request)
    {
        $data = $request->validate(['ladder' => ['required', 'array', 'min:1', 'max:10'], 'ladder.*' => ['array', 'size:2'],
            'ladder.*.0' => ['integer', 'min:0', 'max:365'], 'ladder.*.1' => ['integer', 'min:0', 'max:50000']]);
        $s = WaBotSetting::current();
        $s->forceFill(['warmup_ladder' => json_encode(array_values($data['ladder']))])->save();
        AuditLog::safe(AuditAction::WaSendLimitsUpdate, 'wa_bot_settings', $s->id, null, ['ladder' => $data['ladder']]);

        return response()->json(['ok' => true, 'ladder' => WarmupLadder::ladder()]);
    }

    /** تاريخ بدء تسخين الرقم: رقم قديم معروف يُرجع للماضي فيبدأ بسقف أكبر */
    public function warmup(Request $request, WaChannel $channel)
    {
        $data = $request->validate(['date' => ['required', 'date', 'before_or_equal:today']]);
        $channel->forceFill(['warmup_started_at' => $data['date']])->save();
        AuditLog::safe(AuditAction::WaSendLimitsUpdate, 'wa_channel', $channel->id, null, ['warmup_started_at' => $data['date']]);

        return response()->json(['ok' => true, 'cap' => WarmupLadder::capFor($channel->refresh())]);
    }
}

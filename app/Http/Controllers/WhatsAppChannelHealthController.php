<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\HealthWriter;
use App\Enums\WaHealth;
use App\Models\AuditLog;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\AuditRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * أفعال المدير على صحة القناة (E22): العرض، ورفع الحجب بسبب وتأكيد وتدقيق، والاطلاع على اقتراح الرفع، وحمولة حدث واحد.
 * لا مسار هنا يكتب wa_suspended: الإيقاف اليدوي واستئنافه ملك المفتاح الطارئ (E10). و«افحص الآن» هو meta-check (E08).
 * الرفع يعمل والعلم channel_health مطفأ أيضاً: BLOCKED قائم (نقلته الهجرة) لا يُرفع إلا من هنا.
 */
class WhatsAppChannelHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['channelHealth' => ChannelHealth::panel()])->header('Cache-Control', 'no-store');
    }

    /** حمولة حدث واحد بعد AuditRedactor (الأرقام المعروضة والرموز محجوبة) */
    public function event(WaAccountEvent $event): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json([
            'id' => $event->id, 'field' => $event->field, 'event' => $event->event, 'severity' => $event->severity, 'effect' => $event->effect,
            'occurred_at' => $event->occurred_at?->toIso8601String(), 'created_at' => $event->created_at?->toIso8601String(),
            'payload' => AuditRedactor::clean($event->payload),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * رفع الحجب يدوياً: سبب مكتوب وكتابة «رفع»، وفحص من ميتا خلال 10 دقائق لا يعيد BLOCKED (وإلا يُفحص الآن).
     * الحالة بعده كما يقول الفحص: AVAILABLE أو LIMITED.
     */
    public function unblock(Request $request, MetaHealth $meta): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'confirm_text' => 'required|string|max:20',
        ], ['reason.min' => 'اكتب السبب (10 أحرف على الأقل)', 'confirm_text.required' => 'اكتب «رفع» للتأكيد']);
        if (trim($data['confirm_text']) !== 'رفع') {
            throw ValidationException::withMessages(['confirm_text' => 'اكتب «رفع» للتأكيد']);
        }

        $s = WaBotSetting::current();
        $state = ChannelHealth::current($s);
        if ($state !== WaHealth::Blocked) {
            throw ValidationException::withMessages(['state' => $state === WaHealth::TokenInvalid
                ? 'الرمز الباطل لا يُرفع من هنا: احفظ رمزاً جديداً ثم «افحص الآن»'
                : 'القناة غير محجوبة — لا شيء لرفعه']);
        }

        $poll = $this->freshPoll($s, $meta);
        if ($poll === null) {
            return response()->json(['message' => 'تعذّر الفحص من ميتا الآن — أعد المحاولة بعد قليل', 'state' => $state->value], 409);
        }
        if ($poll['can_send_message'] === 'BLOCKED' || !in_array($poll['can_send_message'], ['AVAILABLE', 'LIMITED'], true)) {
            return response()->json([
                'message' => 'ميتا ما زالت تحجب الرقم — الرفع لن يفيد',
                'possible_solution' => data_get(WaBotSetting::current()->wa_health_meta, 'possible_solution'),
                'state' => $state->value,
            ], 409);
        }

        $to = $poll['can_send_message'] === 'LIMITED' ? WaHealth::Limited : WaHealth::Available;
        // لا رفع بلا صف تدقيق: الانتقال والتدقيق في معاملة واحدة
        $lifted = DB::transaction(function () use ($to, $state, $data, $poll) {
            if (!ChannelHealth::transition($to, HealthWriter::Manual, 'manual', mb_substr($data['reason'], 0, 200), null, true)) {
                return false;
            }
            AuditLog::log(AuditAction::WaHealthUnblock, 'wa_channel', null, $to->value, [
                'from' => $state->value, 'to' => $to->value, 'reason' => $data['reason'], 'poll' => $poll['can_send_message'],
            ]);

            return true;
        });
        if (!$lifted) {
            return response()->json(['message' => 'تغيّرت الحالة أثناء الطلب — أعد تحميل البطاقة', 'state' => ChannelHealth::current()->value], 409);
        }
        WhatsAppInbox::notifyAdmins('✅ رُفع حجب إرسال الواتساب', 'رفعه '.$request->user()->name.': '.$data['reason'], '/whatsapp/settings');

        return response()->json(['channelHealth' => ChannelHealth::panel()]);
    }

    /** «تم الاطلاع» على اقتراح الرفع: العلامة وحدها، والقفل باقٍ */
    public function acknowledge(): JsonResponse
    {
        $this->authorizeSettings();
        if (ChannelHealth::acknowledge()) {
            AuditLog::log(AuditAction::WaHealthAck, 'wa_channel', null, ChannelHealth::current()->value, ['state' => ChannelHealth::current()->value]);
        }

        return response()->json(['channelHealth' => ChannelHealth::panel()]);
    }

    /**
     * آخر فحص إن كان خلال 10 دقائق، وإلا فحص الآن (مهلة 15 ثانية، بدأه إنسان). null: لم يصل الفحص إلى ميتا.
     *
     * @return array{can_send_message: ?string}|null
     */
    private function freshPoll(WaBotSetting $s, MetaHealth $meta): ?array
    {
        $last = (array) data_get($s->wa_health_meta, 'last_poll', []);
        $at = isset($last['at']) ? rescue(fn () => Carbon::parse($last['at']), null, false) : null;
        if ($at !== null && isset($last['can_send_message']) && $at->gt(now()->subMinutes((int) config('whatsapp.health.unblock_poll_max_age_minutes', 10)))) {
            return ['can_send_message' => (string) $last['can_send_message']];
        }

        $summary = $meta->channel(humanInitiated: true);
        $can = $summary['can_send_message'] ?? null;

        return is_string($can) && $can !== '' ? ['can_send_message' => $can] : null;
    }

    private function authorizeSettings(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
    }
}

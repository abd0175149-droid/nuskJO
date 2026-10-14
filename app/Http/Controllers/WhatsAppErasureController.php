<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaErasureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * طلبات حذف البيانات للمدير (E13.4): عرض وتحقّق من الهوية ورفض. التنفيذ من سطر الأوامر وحده
 * (whatsapp:forget --request=ID)، فلا مسار HTTP يحذف شيئاً. لا رقم كامل ولا اسم في الصفحة.
 */
class WhatsAppErasureController extends Controller
{
    public const REJECT_REASONS = ['not_owner', 'duplicate', 'legal_hold', 'withdrawn', 'other'];

    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.erase'), 403);

        $requests = WaErasureRequest::with('conversation:id,phone')->orderByRaw("CASE WHEN status IN ('received','verified','running') THEN 0 ELSE 1 END")
            ->orderBy('due_at')->limit(200)->get()->map(fn ($r) => [
                'id' => $r->id, 'status' => $r->status, 'channel' => $r->channel,
                'conversation_id' => $r->conversation_id,
                'phone_tail' => $r->conversation?->phone ? substr($r->conversation->phone, -4) : null,
                'received_at' => $r->received_at?->toIso8601String(), 'due_at' => $r->due_at?->toIso8601String(),
                'days_left' => $r->due_at ? (int) now()->startOfDay()->diffInDays($r->due_at->copy()->startOfDay(), false) : null,
                'verification_method' => $r->verification_method, 'verified_at' => $r->verified_at?->toIso8601String(),
                'executed_at' => $r->executed_at?->toIso8601String(), 'counts' => $r->counts, 'reason_code' => $r->reason_code,
            ]);

        return Inertia::render('WhatsApp/ErasureRequests', [
            'title' => 'طلبات حذف البيانات',
            'requests' => $requests,
            'methods' => WaErasureRequest::METHODS,
            'rejectReasons' => self::REJECT_REASONS,
            'warnDays' => (int) config('retention.erasure_warn_days'),
            'dueDays' => (int) config('retention.erasure_due_days'),
        ]);
    }

    public function verify(Request $request, WaErasureRequest $erasureRequest)
    {
        abort_unless(auth()->user()->can('whatsapp.erase'), 403);
        $data = $request->validate(['verification_method' => ['required', Rule::in(WaErasureRequest::METHODS)]]);
        abort_unless($erasureRequest->status === 'received', 409, 'الطلب ليس بانتظار التحقق');

        DB::transaction(function () use ($erasureRequest, $data) {
            $erasureRequest->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => auth()->id(), 'verification_method' => $data['verification_method']]);
            AuditLog::log(AuditAction::WaErasureVerify, 'wa_erasure_request', $erasureRequest->id, null, ['method' => $data['verification_method']]);
        });

        return back()->with('success', "تحقّقت من هوية صاحب الطلب #{$erasureRequest->id}. التنفيذ من سطر الأوامر.");
    }

    public function reject(Request $request, WaErasureRequest $erasureRequest)
    {
        abort_unless(auth()->user()->can('whatsapp.erase'), 403);
        $data = $request->validate(['reason_code' => ['required', Rule::in(self::REJECT_REASONS)]]);
        abort_unless(in_array($erasureRequest->status, ['received', 'verified'], true), 409, 'لا يُرفض طلب منفّذ أو قيد التنفيذ');

        DB::transaction(function () use ($erasureRequest, $data) {
            $erasureRequest->update(['status' => 'rejected', 'reason_code' => $data['reason_code']]);
            AuditLog::log(AuditAction::WaErasureReject, 'wa_erasure_request', $erasureRequest->id, null, ['reason' => $data['reason_code']]);
        });

        return back()->with('success', "رُفض الطلب #{$erasureRequest->id}.");
    }
}

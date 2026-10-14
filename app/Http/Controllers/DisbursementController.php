<?php

namespace App\Http\Controllers;

use App\Exceptions\ApprovalConflict;
use App\Models\Disbursement;
use App\Models\Agent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Services\NumberingService;
use App\Services\BalanceService;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DisbursementController extends Controller
{
    private const EXCHANGE_RATE = 0.19;

    public function index(Request $request)
    {
        $disbursements = Disbursement::query()
            ->with(['account:id,code,name,type', 'agent:id,name,code', 'creator:id,name', 'approver:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('disbursement_number', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhereHas('agent', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->agent_id, fn ($q, $id) => $q->where('agent_id', $id))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // جميع الحسابات الورقية (لا أبناء لها) من شجرة الحسابات
        $accounts = Account::where('is_active', true)
            ->whereDoesntHave('children')
            ->select('id', 'code', 'name', 'type')
            ->orderBy('code')
            ->get();

        return Inertia::render('Disbursements/Index', [
            'disbursements' => $disbursements,
            'filters' => $request->only(['search', 'status', 'agent_id']),
            'agents' => Agent::where('is_active', true)->select('id', 'name', 'code')->get(),
            'accounts' => $accounts,
            // ربط اختياري بعرض: الدفعة للوكيل تظهر في «ما ندين به لكل وكيل عن العرض»
            'offers' => \App\Models\Offer::where(fn ($w) => $w->whereNull('settlement_status')->orWhere('settlement_status', '!=', 'approved'))
                ->orderByDesc('id')->limit(200)->get(['id', 'title', 'trip_date'])
                ->map(fn ($o) => ['id' => $o->id, 'title' => $o->title . ($o->trip_date ? ' — ' . $o->trip_date->toDateString() : '')]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.001',
            'currency' => 'required|in:SAR,JOD',
            'payment_method' => 'required|in:cash,bank,check',
            'description' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'offer_id' => 'nullable|integer|exists:offers,id',
        ]);
        // سند على حساب وكيل: نربطه بالوكيل نفسه (لكشف «ما ندين به عن العرض»)
        $validated['agent_id'] = Agent::where('account_id', $validated['account_id'])->value('id');

        $validated['disbursement_number'] = NumberingService::generate('DIS');
        $validated['disbursement_date'] = now()->toDateString();
        $validated['reference_number'] = 'DIS-' . now()->format('ymd') . '-' . rand(100, 999);
        $validated['exchange_rate'] = self::EXCHANGE_RATE;
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $disbursement = Disbursement::create($validated);

        try { \App\Services\NotificationService::disbursementCreated($disbursement); } catch (\Throwable $e) {}

        return redirect()->back()->with('success', "تم إنشاء سند الصرف {$disbursement->disbursement_number} بنجاح");
    }

    public function approve(Disbursement $disbursement)
    {
        abort_unless(auth()->user()->can('disbursements.approve'), 403);

        if (!$disbursement->isPending()) {
            return back()->with('error', 'هذا السند ليس معلقاً');
        }
        if (!$disbursement->approvableBy(auth()->user())) {
            return back()->with('error', 'لا يعتمد المنشئ مستنده إلا المدير');
        }

        try {
            DB::transaction(function () use ($disbursement) {
                $disbursement->approve(auth()->user());

                AuditLog::log(\App\Enums\AuditAction::Approve, 'disbursement', $disbursement->id, $disbursement->disbursement_number);

                // قيد محاسبي إلزامي — فشله يُلغي الاعتماد بالكامل
                AccountingService::recordDisbursement($disbursement);

                // إشعار
                try { \App\Services\NotificationService::disbursementApproved($disbursement); } catch (\Throwable $e) {}
            });
        } catch (ApprovalConflict $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            AccountingService::notifyFailure("اعتماد سند الصرف {$disbursement->disbursement_number}", $e);
            return back()->with('error', 'تعذّر اعتماد سند الصرف محاسبياً ولم يُحفظ أي تغيير. تم إشعار الإدارة: ' . $e->getMessage());
        }

        return back()->with('success', "تم اعتماد سند الصرف {$disbursement->disbursement_number}");
    }

    public function reject(Request $request, Disbursement $disbursement)
    {
        abort_unless(auth()->user()->can('disbursements.reject'), 403);

        if (!$disbursement->isPending()) {
            return back()->with('error', 'هذا السند ليس معلقاً');
        }

        $request->validate(['rejection_reason' => 'required|string|max:500']);
        $disbursement->reject(auth()->user(), $request->rejection_reason);
        AuditLog::log(\App\Enums\AuditAction::Reject, 'disbursement', $disbursement->id, $disbursement->disbursement_number);

        try { \App\Services\NotificationService::operationRejected($disbursement, 'سند صرف', $disbursement->disbursement_number); } catch (\Throwable $e) {}

        return back()->with('success', "تم رفض سند الصرف {$disbursement->disbursement_number}");
    }

    public function startEdit(Disbursement $disbursement)
    {
        abort_unless(auth()->user()->can('admin'), 403);

        if (!$disbursement->isApproved()) {
            return back()->with('error', 'يمكن تعديل السندات المعتمدة فقط');
        }

        DB::transaction(function () use ($disbursement) {
            // أول كتابة: approved → editing ذرياً، فلا يتكرر عكس الذمة مع نقرة مزدوجة
            $disbursement->startEditing(auth()->user());

            // عكس القيد المحاسبي
            $entry = \App\Models\JournalEntry::where('reference_type', 'disbursement')
                ->where('reference_id', $disbursement->id)
                ->where('is_reversed', false)->first();
            if ($entry) try { AccountingService::reverseEntry($entry, 'تعديل سند الصرف'); } catch (\Exception $e) { \Log::error('Reverse Disbursement: ' . $e->getMessage()); }

            AuditLog::log(\App\Enums\AuditAction::StartEdit, 'disbursement', $disbursement->id, $disbursement->disbursement_number);
        });

        return back()->with('success', "تم فتح سند الصرف {$disbursement->disbursement_number} للتعديل");
    }

    public function updateApproved(Request $request, Disbursement $disbursement)
    {
        abort_unless(auth()->user()->can('admin'), 403);

        if (!$disbursement->isEditing()) {
            return back()->with('error', 'هذا السند ليس في وضع التعديل');
        }

        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.001',
            'currency' => 'required|in:SAR,JOD',
            'payment_method' => 'required|in:cash,bank,check',
            'description' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'offer_id' => 'nullable|integer|exists:offers,id',
        ]);
        // سند على حساب وكيل: نربطه بالوكيل نفسه (لكشف «ما ندين به عن العرض»)
        $validated['agent_id'] = Agent::where('account_id', $validated['account_id'])->value('id');

        $validated['exchange_rate'] = self::EXCHANGE_RATE;

        DB::transaction(function () use ($disbursement, $validated) {
            $disbursement->resubmitForApproval();   // ذري: editing → pending
            $disbursement->update($validated);
        });

        return back()->with('success', "تم تعديل سند الصرف {$disbursement->disbursement_number} وإرساله للاعتماد");
    }

    public function destroy(Disbursement $disbursement)
    {
        abort_unless(auth()->user()->can('admin'), 403);

        if (!$disbursement->isPending()) {
            return back()->with('error', 'لا يمكن حذف سند صرف معتمد');
        }
        // سند معلّق بلا أثر مالي، لكن حذفه لا يترك أثراً في الجدول نفسه
        \Illuminate\Support\Facades\DB::transaction(function () use ($disbursement) {
            $disbursement->delete();
            AuditLog::log(\App\Enums\AuditAction::Delete, 'disbursement', $disbursement->id, $disbursement->disbursement_number, ['amount' => (float) $disbursement->amount]);
        });
        return redirect()->route('disbursements.index')->with('success', 'تم حذف سند الصرف');
    }

    public function print(Disbursement $disbursement)
    {
        $disbursement->load(['account:id,code,name', 'agent:id,name,code', 'creator:id,name', 'approver:id,name']);

        $template = \App\Models\Setting::where('key', 'print_template_financial')->first();
        $templateUrl = $template?->value ? \Storage::url($template->value) : null;

        return Inertia::render('Disbursements/Print', [
            'disbursement' => $disbursement,
            'templateUrl' => $templateUrl,
        ]);
    }
}

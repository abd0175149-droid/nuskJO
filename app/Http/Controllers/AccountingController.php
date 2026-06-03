<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AccountingService;
use App\Events\DataUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountingController extends Controller
{
    /**
     * شجرة الحسابات
     */
    public function chartOfAccounts()
    {
        $accounts = Account::with('childrenRecursive')
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get();

        return Inertia::render('Accounting/ChartOfAccounts', [
            'title' => 'شجرة الحسابات',
            'accounts' => $accounts,
        ]);
    }

    /**
     * طباعة شجرة الحسابات
     */
    public function printChart()
    {
        $accounts = Account::with('childrenRecursive')
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get();

        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_chart')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Accounting/PrintChart', [
            'accounts' => $accounts,
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    /**
     * ميزان المراجعة
     */
    public function trialBalance(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        // حسابات الأوراق فقط (بدون الفروع الأم)
        $accounts = Account::whereDoesntHave('children')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function ($account) use ($from, $to) {
                // رصيد أول المدة
                $openingDebit = JournalEntryLine::where('account_id', $account->id)
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('journal_entries.entry_date', '<', $from)
                    ->sum('journal_entry_lines.debit');

                $openingCredit = JournalEntryLine::where('account_id', $account->id)
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->where('journal_entries.entry_date', '<', $from)
                    ->sum('journal_entry_lines.credit');

                // حركات الفترة
                $periodDebit = JournalEntryLine::where('account_id', $account->id)
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->whereBetween('journal_entries.entry_date', [$from, $to . ' 23:59:59'])
                    ->sum('journal_entry_lines.debit');

                $periodCredit = JournalEntryLine::where('account_id', $account->id)
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                    ->whereBetween('journal_entries.entry_date', [$from, $to . ' 23:59:59'])
                    ->sum('journal_entry_lines.credit');

                // رصيد نهاية المدة
                $closingDebit = $openingDebit + $periodDebit;
                $closingCredit = $openingCredit + $periodCredit;

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'currency' => $account->currency,
                    'opening_debit' => round($openingDebit, 3),
                    'opening_credit' => round($openingCredit, 3),
                    'period_debit' => round($periodDebit, 3),
                    'period_credit' => round($periodCredit, 3),
                    'closing_debit' => round($closingDebit, 3),
                    'closing_credit' => round($closingCredit, 3),
                    'balance' => round($closingDebit - $closingCredit, 3),
                ];
            })
            ->filter(fn ($a) => $a['closing_debit'] != 0 || $a['closing_credit'] != 0 || $a['period_debit'] != 0 || $a['period_credit'] != 0);

        // ملخص النقدية (كاش vs بنك)
        $cashAccount = Account::where('code', '1101')->first();
        $bankAccount = Account::where('code', '1102')->first();
        $checksAccount = Account::where('code', '1103')->first();

        $cashSummary = [
            'cash' => $cashAccount ? $cashAccount->balance : 0,
            'bank' => $bankAccount ? $bankAccount->balance : 0,
            'checks' => $checksAccount ? $checksAccount->balance : 0,
        ];

        return Inertia::render('Accounting/TrialBalance', [
            'title' => 'ميزان المراجعة',
            'accounts' => $accounts->values(),
            'filters' => ['from' => $from, 'to' => $to],
            'cashSummary' => $cashSummary,
        ]);
    }

    /**
     * سجل القيود
     */
    public function journalEntries(Request $request)
    {
        $entries = JournalEntry::query()
            ->with(['lines.account:id,code,name', 'creator:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('entry_number', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"))
            ->orderByDesc('entry_date')
            ->paginate(20)
            ->withQueryString();

        // قائمة الحسابات (الأوراق فقط) لنموذج الإضافة
        $leafAccounts = Account::whereDoesntHave('children')
            ->where('is_active', true)
            ->orderBy('code')
            ->select('id', 'code', 'name', 'type', 'currency')
            ->get();

        return Inertia::render('Accounting/JournalEntries', [
            'title' => 'سجل القيود',
            'entries' => $entries,
            'filters' => $request->only(['search']),
            'leafAccounts' => $leafAccounts,
        ]);
    }

    // ============================================================
    // CRUD شجرة الحسابات
    // ============================================================

    /**
     * توليد الرقم التالي لحساب فرعي
     */
    private static function generateNextCode(?int $parentId): string
    {
        if (!$parentId) {
            // حساب رئيسي (بدون أب): آخر رقم ألف + 1000
            $maxRoot = Account::whereNull('parent_id')->max('code');
            $next = $maxRoot ? intval($maxRoot) + 1000 : 1000;
            return (string) $next;
        }

        $parent = Account::findOrFail($parentId);
        $parentCode = $parent->code;
        $parentLen = strlen($parentCode);

        // البحث عن آخر طفل مباشر (أطفال يبدأون بكود الأب)
        $lastChild = Account::where('parent_id', $parentId)
            ->where('code', 'like', $parentCode . '%')
            ->orderByDesc('code')
            ->value('code');

        if ($lastChild) {
            // استخراج الجزء التسلسلي بعد كود الأب
            $suffix = substr($lastChild, $parentLen);
            $nextSuffix = intval($suffix) + 1;
            return $parentCode . str_pad($nextSuffix, strlen($suffix), '0', STR_PAD_LEFT);
        }

        // لا يوجد أطفال: بدء بـ parent_code + "1"  (مثلاً 1102 → 11021)
        // تحقق أولاً: إذا كان كود الأب قصير (4 أرقام أو أقل) — نضيف رقم واحد
        return $parentCode . '1';
    }

    /**
     * API: الرقم التالي المتاح لحساب أب معين
     */
    public function nextCode(Request $request)
    {
        $parentId = $request->parent_id ?: null;
        $code = self::generateNextCode($parentId ? (int) $parentId : null);
        return response()->json(['code' => $code]);
    }

    /**
     * إضافة حساب جديد (الرقم يُولد تلقائياً)
     */
    public function storeAccount(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id' => 'required|exists:accounts,id',
            'currency' => 'required|in:JOD,SAR',
            'description' => 'nullable|string|max:500',
        ]);

        // توليد الرقم تلقائياً
        $validated['code'] = self::generateNextCode((int) $validated['parent_id']);

        // تأكد من عدم التكرار
        if (Account::where('code', $validated['code'])->exists()) {
            return back()->with('error', "رقم الحساب {$validated['code']} موجود مسبقاً");
        }

        $validated['is_system'] = false;
        $validated['is_active'] = true;
        $validated['balance'] = 0;

        $account = Account::create($validated);

        // === مزامنة مباشرة: إذا الحساب تحت "الوكلاء" (2110) — أنشئ وكيل ===
        $parent = Account::find($validated['parent_id']);
        $isAgentAccount = $parent && $parent->code === '2110';
        if (!$isAgentAccount && $parent && $parent->parent_id) {
            $grandParent = Account::find($parent->parent_id);
            $isAgentAccount = $grandParent && $grandParent->code === '2110';
        }

        if ($isAgentAccount) {
            $existing = \App\Models\Agent::where('account_id', $account->id)->first();
            if (!$existing) {
                $codes = \App\Models\Agent::withTrashed()->where('code', 'like', 'AGT-%')->pluck('code')->map(fn($c) => (int)substr($c, 4));
                $nextNum = $codes->max() ? $codes->max() + 1 : 1;
                \App\Models\Agent::create([
                    'name' => $account->name,
                    'code' => 'AGT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT),
                    'account_id' => $account->id,
                    'country' => 'JO',
                    'currency' => 'JOD',
                    'balance_sar' => 0,
                    'is_active' => true,
                ]);
            }
        }

        // === مزامنة: إذا تحت "ذمم العملاء" (1200) — أنشئ عميل ===
        if ($parent && $parent->code === '1200') {
            $existing = \App\Models\Client::where('account_id', $account->id)->first();
            if (!$existing) {
                $codes = \App\Models\Client::withTrashed()->where('code', 'like', 'CLT-%')->pluck('code')->map(fn($c) => (int)substr($c, 4));
                $nextNum = $codes->max() ? $codes->max() + 1 : 1;
                \App\Models\Client::create([
                    'name' => $account->name,
                    'code' => 'CLT-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT),
                    'account_id' => $account->id,
                    'country' => 'JO',
                    'currency' => 'JOD',
                    'balance_jod' => 0,
                    'credit_limit_jod' => 0,
                    'is_active' => true,
                ]);
            }
        }

        event(new DataUpdated('account', 'created', $account->id));

        return redirect()->back()->with('success', 'تم إضافة الحساب بنجاح');
    }

    /**
     * تعديل حساب
     */
    public function updateAccount(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id' => 'nullable|exists:accounts,id',
            'currency' => 'required|in:JOD,SAR',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        // لا يمكن تعديل كود الحسابات الثابتة
        if (!$account->is_system) {
            $codeValidation = $request->validate([
                'code' => 'required|string|max:20|unique:accounts,code,' . $account->id,
            ]);
            $validated['code'] = $codeValidation['code'];
        }

        $account->update($validated);

        return redirect()->back()->with('success', 'تم تحديث الحساب بنجاح');
    }

    /**
     * حذف حساب (فقط غير الثابتة وبدون قيود)
     */
    public function destroyAccount(Account $account)
    {
        if ($account->is_system) {
            return back()->with('error', 'لا يمكن حذف حساب من حسابات النظام الأساسية');
        }

        if ($account->children()->count() > 0) {
            return back()->with('error', 'لا يمكن حذف حساب لديه حسابات فرعية');
        }

        $hasEntries = JournalEntryLine::where('account_id', $account->id)->exists();
        if ($hasEntries) {
            return back()->with('error', 'لا يمكن حذف حساب لديه قيود محاسبية مسجلة');
        }

        $account->delete();
        return redirect()->back()->with('success', 'تم حذف الحساب بنجاح');
    }

    // ============================================================
    // القيود اليدوية
    // ============================================================

    /**
     * إنشاء قيد يدوي
     */
    public function storeJournal(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:500',
            'entry_date' => 'nullable|date',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        // التحقق من التوازن
        $totalDebit = collect($validated['lines'])->sum('debit');
        $totalCredit = collect($validated['lines'])->sum('credit');

        if (round($totalDebit, 3) !== round($totalCredit, 3)) {
            return back()->with('error', "القيد غير متوازن — مدين: {$totalDebit} ≠ دائن: {$totalCredit}");
        }

        if ($totalDebit == 0) {
            return back()->with('error', 'القيد لا يحتوي على أي مبلغ');
        }

        // التحقق من أن كل سطر له مدين أو دائن (وليس كلاهما)
        foreach ($validated['lines'] as $i => $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                return back()->with('error', 'لا يمكن أن يكون نفس السطر مديناً ودائناً في نفس الوقت');
            }
            if ($line['debit'] == 0 && $line['credit'] == 0) {
                return back()->with('error', 'كل سطر يجب أن يحتوي على مبلغ مدين أو دائن');
            }
        }

        DB::transaction(function () use ($validated, $totalDebit, $totalCredit) {
            // التحقق من الفترة المحاسبية
            $entryDate = $validated['entry_date'] ?? now()->toDateString();
            if (AccountingPeriod::isDateLocked($entryDate)) {
                throw new \Exception('الفترة مقفلة');
            }

            // توليد رقم القيد
            $today = now()->format('Ymd');
            $last = JournalEntry::where('entry_number', 'like', "JRN-{$today}-%")
                ->lockForUpdate()
                ->orderByDesc('entry_number')->value('entry_number');
            $seq = $last ? (int) substr($last, -4) + 1 : 1;
            $entryNumber = "JRN-{$today}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $entry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'description' => $validated['description'],
                'reference_type' => 'manual',
                'reference_id' => null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['lines'] as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                ]);

                // تحديث رصيد الحساب
                $account = Account::find($line['account_id']);
                if ($account) {
                    $d = $line['debit'];
                    $c = $line['credit'];
                    if (in_array($account->type, ['asset', 'expense'])) {
                        $account->increment('balance', $d - $c);
                    } else {
                        $account->increment('balance', $c - $d);
                    }
                }
            }
        });

        return redirect()->back()->with('success', 'تم إنشاء القيد اليدوي بنجاح');
    }

    // ============================================================
    // عكس القيود
    // ============================================================

    public function reverseEntry(JournalEntry $entry)
    {
        try {
            $reversal = AccountingService::reverseEntry($entry, 'عكس يدوي');
            return back()->with('success', "تم عكس القيد — القيد العكسي: {$reversal->entry_number}");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ============================================================
    // الفترات المحاسبية
    // ============================================================

    public function periods()
    {
        $periods = AccountingPeriod::orderByDesc('year')->orderByDesc('month')->get();
        $years = JournalEntry::selectRaw("strftime('%Y', entry_date) as year")
            ->groupBy('year')->orderByDesc('year')->pluck('year');
        $fiscalYears = FiscalYear::orderByDesc('year')->get();

        return Inertia::render('Accounting/Periods', [
            'title' => 'الفترات المحاسبية',
            'periods' => $periods,
            'availableYears' => $years,
            'fiscalYears' => $fiscalYears,
        ]);
    }

    public function storeFiscalYear(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2099|unique:fiscal_years,year',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['created_by'] = auth()->id();
        FiscalYear::create($validated);

        return back()->with('success', "تم إنشاء السنة المالية {$validated['year']} بنجاح");
    }

    public function destroyFiscalYear(FiscalYear $fiscalYear)
    {
        if ($fiscalYear->isClosed()) {
            return back()->with('error', 'لا يمكن حذف سنة مالية مقفلة');
        }

        $fiscalYear->delete();
        return back()->with('success', 'تم حذف السنة المالية');
    }

    public function closePeriod(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:0|max:12',
        ]);

        $period = AccountingPeriod::updateOrCreate(
            ['year' => $validated['year'], 'month' => $validated['month']],
            [
                'status' => 'closed',
                'closed_by' => auth()->id(),
                'closed_at' => now(),
            ]
        );

        $label = $validated['month'] == 0
            ? "السنة {$validated['year']} كاملة"
            : "شهر {$validated['month']}/{$validated['year']}";

        return back()->with('success', "تم إقفال {$label}");
    }

    public function openPeriod(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:0|max:12',
        ]);

        AccountingPeriod::where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);

        return back()->with('success', 'تم فتح الفترة');
    }

    // ============================================================
    // إقفال السنة المالية
    // ============================================================

    public function closeYear(Request $request)
    {
        $validated = $request->validate(['year' => 'required|integer|min:2020|max:2099']);

        try {
            $result = AccountingService::closeYear($validated['year']);
            return back()->with('success',
                "تم إقفال السنة {$validated['year']} — صافي الربح: " .
                number_format($result['net_income'], 3) . ' JOD — قيد: ' .
                $result['entry']->entry_number
            );
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ============================================================
    // التقارير المالية
    // ============================================================

    public function profitAndLoss(Request $request)
    {
        $from = $request->from ?? now()->startOfYear()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $report = AccountingService::profitAndLoss($from, $to);

        return Inertia::render('Accounting/ProfitAndLoss', [
            'title' => 'قائمة الدخل',
            'report' => $report,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $asOfDate = $request->as_of ?? now()->toDateString();

        $report = AccountingService::balanceSheet($asOfDate);

        return Inertia::render('Accounting/BalanceSheet', [
            'title' => 'الميزانية العمومية',
            'report' => $report,
            'filters' => ['as_of' => $asOfDate],
        ]);
    }

    /**
     * تفاصيل حساب — كل العمليات المرتبطة
     */
    public function accountDetails(Request $request, Account $account)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $lines = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereBetween('journal_entries.entry_date', [$from, $to . ' 23:59:59'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select(
                'journal_entry_lines.*',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
                'journal_entries.is_reversed',
            )
            ->get();

        // حساب الرصيد الافتتاحي
        $openingDebit = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '<', $from)
            ->sum('journal_entry_lines.debit');
        $openingCredit = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '<', $from)
            ->sum('journal_entry_lines.credit');

        $openingBalance = in_array($account->type, ['asset', 'expense'])
            ? $openingDebit - $openingCredit
            : $openingCredit - $openingDebit;

        return Inertia::render('Accounting/AccountDetails', [
            'title' => "كشف حساب: {$account->code} - {$account->name}",
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'balance' => $account->balance,
            ],
            'opening_balance' => round($openingBalance, 3),
            'lines' => $lines,
            'totals' => [
                'debit' => round($lines->sum('debit'), 3),
                'credit' => round($lines->sum('credit'), 3),
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * طباعة كشف حساب محاسبي
     */
    public function printAccountDetails(Request $request, Account $account)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $lines = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from, $to . ' 23:59:59']))
            ->with('journalEntry')
            ->get()
            ->map(fn ($l) => [
                'entry_date' => $l->journalEntry->entry_date,
                'entry_number' => $l->journalEntry->entry_number,
                'description' => $l->description ?: $l->journalEntry->description,
                'reference_type' => $l->journalEntry->reference_type,
                'debit' => $l->debit,
                'credit' => $l->credit,
            ])
            ->sortBy('entry_date')->values();

        $openingBalance = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('entry_date', '<', $from))
            ->sum(DB::raw('debit - credit'));

        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_statement')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Accounting/PrintStatement', [
            'account' => $account,
            'entries' => $lines,
            'openingBalance' => round($openingBalance, 3),
            'filters' => ['from' => $from, 'to' => $to],
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    /**
     * طباعة ميزان المراجعة
     */
    public function printTrialBalance(Request $request)
    {
        $from = $request->from ?? now()->startOfYear()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $accounts = Account::whereDoesntHave('childrenRecursive')->orderBy('code')->get();
        $result = $accounts->map(function ($a) use ($from, $to) {
            $openingLines = $a->journalLines()->whereHas('journalEntry', fn ($q) => $q->where('entry_date', '<', $from))->get();
            $periodLines = $a->journalLines()->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from, $to . ' 23:59:59']))->get();
            $od = $openingLines->sum('debit'); $oc = $openingLines->sum('credit');
            $pd = $periodLines->sum('debit'); $pc = $periodLines->sum('credit');
            return [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                'opening_debit' => max(0, $od - $oc), 'opening_credit' => max(0, $oc - $od),
                'period_debit' => $pd, 'period_credit' => $pc,
                'closing_debit' => max(0, ($od + $pd) - ($oc + $pc)),
                'closing_credit' => max(0, ($oc + $pc) - ($od + $pd)),
            ];
        })->filter(fn ($a) => $a['opening_debit'] > 0 || $a['opening_credit'] > 0 || $a['period_debit'] > 0 || $a['period_credit'] > 0);

        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_trial_balance')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Accounting/PrintTrialBalance', [
            'accounts' => $result->values(),
            'filters' => ['from' => $from, 'to' => $to],
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    /**
     * طباعة الأرباح والخسائر
     */
    public function printProfitLoss(Request $request)
    {
        $from = $request->from ?? now()->startOfYear()->toDateString();
        $to = $request->to ?? now()->toDateString();

        $revenues = Account::where('type', 'revenue')->whereDoesntHave('childrenRecursive')->orderBy('code')->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'amount' => $a->journalLines()->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from, $to . ' 23:59:59']))->sum(DB::raw('credit - debit'))])
            ->filter(fn ($a) => abs($a['amount']) > 0.001);
        $expenses = Account::where('type', 'expense')->whereDoesntHave('childrenRecursive')->orderBy('code')->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'amount' => $a->journalLines()->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from, $to . ' 23:59:59']))->sum(DB::raw('debit - credit'))])
            ->filter(fn ($a) => abs($a['amount']) > 0.001);

        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_profit_loss')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Accounting/PrintProfitLoss', [
            'revenues' => $revenues->values(),
            'expenses' => $expenses->values(),
            'totalRevenue' => $revenues->sum('amount'),
            'totalExpenses' => $expenses->sum('amount'),
            'netIncome' => $revenues->sum('amount') - $expenses->sum('amount'),
            'filters' => ['from' => $from, 'to' => $to],
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }

    /**
     * طباعة الميزانية العمومية
     */
    public function printBalanceSheet(Request $request)
    {
        $asOf = $request->as_of ?? now()->toDateString();

        $getAccounts = fn ($type) => Account::where('type', $type)->whereDoesntHave('childrenRecursive')->orderBy('code')->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->journalLines()->whereHas('journalEntry', fn ($q) => $q->where('entry_date', '<=', $asOf . ' 23:59:59'))->sum(DB::raw($type === 'asset' || $type === 'expense' ? 'debit - credit' : 'credit - debit'))])
            ->filter(fn ($a) => abs($a['balance']) > 0.001);

        $assets = $getAccounts('asset');
        $liabilities = $getAccounts('liability');
        $equity = $getAccounts('equity');

        $template = \App\Models\Setting::where('key', 'print_template_accounting')->first();
        $templateUrl = $template?->value ? \Illuminate\Support\Facades\Storage::url($template->value) : null;
        $layoutSetting = \App\Models\Setting::where('key', 'print_layout_balance_sheet')->first();
        $layout = $layoutSetting?->value ? json_decode($layoutSetting->value, true) : null;

        return Inertia::render('Accounting/PrintBalanceSheet', [
            'assets' => $assets->values(),
            'liabilities' => $liabilities->values(),
            'equity' => $equity->values(),
            'totalAssets' => $assets->sum('balance'),
            'totalLiabilities' => $liabilities->sum('balance'),
            'totalEquity' => $equity->sum('balance'),
            'filters' => ['as_of' => $asOf],
            'templateUrl' => $templateUrl,
            'layout' => $layout,
        ]);
    }
}

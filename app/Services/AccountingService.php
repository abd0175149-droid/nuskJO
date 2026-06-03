<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Models\Expense;
use App\Models\Transfer;
use App\Models\Invoice;
use App\Models\Advance;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    // ============================================================
    // سعر الصرف الافتراضي SAR → JOD (يمكن جعله ديناميكي مستقبلاً)
    // ============================================================
    private static function getExchangeRate(): float
    {
        return (float) (config('accounting.sar_to_jod', 0.19));
    }

    /**
     * تحويل SAR إلى JOD
     */
    private static function sarToJod(float $amountSar, ?float $customRate = null): float
    {
        $rate = $customRate ?? self::getExchangeRate();
        return round($amountSar * $rate, 3);
    }

    /**
     * توليد رقم قيد فريد (يعمل داخل transaction خارجية)
     */
    private static function generateEntryNumber(): string
    {
        $today = now()->format('Ymd');

        $last = JournalEntry::where('entry_number', 'like', "JRN-{$today}-%")
            ->lockForUpdate()
            ->orderByDesc('entry_number')->value('entry_number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;
        return "JRN-{$today}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * الحصول على حساب حسب الكود
     */
    private static function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /**
     * تحديد حساب مصدر الدفع حسب طريقة الدفع
     */
    private static function paymentAccount(string $method): Account
    {
        return match ($method) {
            'cash' => self::account('1101'),       // الصندوق
            'bank', 'bank_transfer' => self::account('1102'), // البنك
            'check' => self::account('1103'),      // شيكات تحت التحصيل
            default => self::account('1101'),       // افتراضي: الصندوق
        };
    }

    /**
     * التحقق من أن التاريخ ليس في فترة مقفلة
     */
    private static function validatePeriod(string $date): void
    {
        if (AccountingPeriod::isDateLocked($date)) {
            throw new \Exception("لا يمكن التسجيل في فترة محاسبية مقفلة ({$date})");
        }
    }

    /**
     * إنشاء قيد يومية — المحرك الأساسي
     */
    private static function createEntry(
        string $description,
        string $referenceType,
        int $referenceId,
        array $lines,
        ?string $entryDate = null,
        ?string $reversalOf = null
    ): JournalEntry {
        $totalDebit = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));

        // التحقق من التوازن
        if (round($totalDebit, 3) !== round($totalCredit, 3)) {
            throw new \Exception("القيد غير متوازن: مدين={$totalDebit} ≠ دائن={$totalCredit}");
        }

        $date = $entryDate ?? now()->toDateString();

        // التحقق من الفترة (إلا إذا كان قيد إقفال)
        if ($referenceType !== 'year_closing') {
            self::validatePeriod($date);
        }

        return DB::transaction(function () use ($description, $referenceType, $referenceId, $lines, $totalDebit, $totalCredit, $date, $reversalOf) {
            $entry = JournalEntry::create([
                'entry_number' => self::generateEntryNumber(),
                'entry_date' => $date,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by' => auth()->id(),
                'reversal_of' => $reversalOf,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                ]);

                // تحديث رصيد الحساب المخبأ
                $account = Account::find($line['account_id']);
                if ($account) {
                    $debit = $line['debit'] ?? 0;
                    $credit = $line['credit'] ?? 0;

                    if (in_array($account->type, ['asset', 'expense'])) {
                        $account->increment('balance', $debit - $credit);
                    } else {
                        $account->increment('balance', $credit - $debit);
                    }
                }
            }

            return $entry;
        });
    }

    // ============================================================
    // عكس القيود (Reversal Entries)
    // ============================================================

    /**
     * عكس قيد موجود — يُنشئ قيد عكسي مع عكس المدين والدائن
     */
    public static function reverseEntry(JournalEntry $entry, string $reason = ''): JournalEntry
    {
        if ($entry->is_reversed) {
            throw new \Exception("هذا القيد تم عكسه مسبقاً");
        }

        if ($entry->reference_type === 'year_closing') {
            throw new \Exception("لا يمكن عكس قيد إقفال سنوي — يجب فتح الفترة أولاً");
        }

        $entry->load('lines');

        $reversedLines = [];
        foreach ($entry->lines as $line) {
            $reversedLines[] = [
                'account_id' => $line->account_id,
                'debit' => $line->credit,     // عكس: الدائن يصبح مدين
                'credit' => $line->debit,     // عكس: المدين يصبح دائن
                'description' => "عكس: {$line->description}",
            ];
        }

        $description = "عكس قيد {$entry->entry_number}";
        if ($reason) $description .= " — {$reason}";

        $reversal = self::createEntry(
            $description,
            'reversal',
            $entry->id,
            $reversedLines,
            now()->toDateString(),
            $entry->entry_number
        );

        // تعليم القيد الأصلي كمعكوس
        $entry->update(['is_reversed' => true]);

        return $reversal;
    }

    // ============================================================
    // القيود المحاسبية لكل عملية (كل المبالغ بالدينار JOD)
    // ============================================================

    /**
     * قيد اعتماد سند قبض
     * الحالة العادية (نقد/شيك):
     *   مدين: الصندوق/شيكات = المبلغ الكامل
     *   دائن: حساب العميل = المبلغ الكامل
     * 
     * حالة البنك مع عمولة:
     *   مدين: البنك = (المبلغ - العمولة)  ← اللي فعلاً وصل
     *   مدين: مصاريف عمولات بنكية = العمولة
     *   دائن: حساب العميل = المبلغ الكامل
     */
    public static function recordReceipt(Receipt $receipt): JournalEntry
    {
        $clientAccount = $receipt->client->account_id
            ? Account::find($receipt->client->account_id)
            : self::account('1200');

        $commission = (float) ($receipt->bank_commission ?? 0);
        $totalAmount = (float) $receipt->amount_jod;

        $lines = [];

        if ($commission > 0 && $receipt->payment_method === 'bank') {
            // حالة البنك مع عمولة
            $bankAccount = self::account('1102');
            $commissionAccount = self::account('5500'); // عمولات بنكية

            // مدين: البنك = المبلغ الصافي (بعد خصم العمولة)
            $lines[] = [
                'account_id' => $bankAccount->id,
                'debit' => round($totalAmount - $commission, 3),
                'credit' => 0,
                'description' => "تحصيل بنكي من {$receipt->client->name} (صافي بعد العمولة)",
            ];

            // مدين: مصاريف عمولات بنكية
            $lines[] = [
                'account_id' => $commissionAccount->id,
                'debit' => $commission,
                'credit' => 0,
                'description' => "عمولة بنكية على سند {$receipt->receipt_number}",
            ];
        } else {
            // حالة عادية (نقد/شيك/بنك بدون عمولة)
            $paymentAccount = self::paymentAccount($receipt->payment_method);
            $lines[] = [
                'account_id' => $paymentAccount->id,
                'debit' => $totalAmount,
                'credit' => 0,
                'description' => "تحصيل من {$receipt->client->name}",
            ];
        }

        // دائن: حساب العميل = المبلغ الكامل (كل المبلغ يخصم من ذمته)
        $lines[] = [
            'account_id' => $clientAccount->id,
            'debit' => 0,
            'credit' => $totalAmount,
            'description' => "تسديد ذمة {$receipt->client->name}",
        ];

        return self::createEntry(
            "سند قبض {$receipt->receipt_number} — {$receipt->client->name}",
            'receipt',
            $receipt->id,
            $lines
        );
    }

    /**
     * قيد اعتماد حوالة
     * مدين: حساب الوكيل الفرعي (بالدينار = cost_jod)
     * دائن: الصندوق/البنك
     */
    public static function recordTransfer(Transfer $transfer): JournalEntry
    {
        $paymentAccount = self::paymentAccount($transfer->payment_method);
        $agentAccount = $transfer->agent->account_id
            ? Account::find($transfer->agent->account_id)
            : self::account('2110');

        // المبلغ بالدينار (cost_jod) — لا نحتاج تحويل
        $amountJod = (float) $transfer->cost_jod;

        return self::createEntry(
            "حوالة {$transfer->transfer_number} — {$transfer->agent->name} ({$transfer->amount_sar} SAR)",
            'transfer',
            $transfer->id,
            [
                [
                    'account_id' => $agentAccount->id,
                    'debit' => $amountJod,
                    'credit' => 0,
                    'description' => "شحن رصيد {$transfer->agent->name}",
                ],
                [
                    'account_id' => $paymentAccount->id,
                    'debit' => 0,
                    'credit' => $amountJod,
                    'description' => "دفع حوالة {$transfer->transfer_number}",
                ],
            ]
        );
    }


    /**
     * قيد اعتماد سلفة موظف
     * مدين: ذمم الموظفين (سلف) (1300)
     * دائن: الصندوق/البنك
     */
    public static function recordAdvance(Advance $advance): JournalEntry
    {
        $paymentAccount = self::paymentAccount($advance->payment_method);
        $advancesAccount = self::account('1300'); // ذمم الموظفين

        // حساب المبلغ بالدينار (إذا كانت العملة SAR نضرب بسعر الصرف)
        $amountJod = $advance->currency === 'SAR' 
            ? round($advance->amount * self::getExchangeRate(), 3) 
            : $advance->amount;

        return self::createEntry(
            "سلفة للموظف {$advance->employee->user->name} — {$advance->advance_number}",
            'advance',
            $advance->id,
            [
                [
                    'account_id' => $advancesAccount->id,
                    'debit' => $amountJod,
                    'credit' => 0,
                    'description' => "إثبات سلفة الموظف {$advance->employee->user->name}",
                ],
                [
                    'account_id' => $paymentAccount->id,
                    'debit' => 0,
                    'credit' => $amountJod,
                    'description' => "صرف سلفة الموظف {$advance->employee->user->name}",
                ],
            ]
        );
    }

    /**
     * قيد اعتماد مسير الرواتب
     */
    public static function recordPayroll(Payroll $payroll): JournalEntry
    {
        // حساب الإجماليات للمسير بالكامل
        // إذا كان المسير بالريال السعودي نحوله للدينار أولاً
        $isSar = $payroll->currency === 'SAR';
        $rate = self::getExchangeRate();

        $payroll->load('items');
        
        $totalBasic = $isSar ? round($payroll->items->sum('basic_salary') * $rate, 3) : $payroll->items->sum('basic_salary');
        $totalAllowances = $isSar ? round( ($payroll->items->sum('housing_allowance') + $payroll->items->sum('transport_allowance') + $payroll->items->sum('other_allowance')) * $rate, 3) : ($payroll->items->sum('housing_allowance') + $payroll->items->sum('transport_allowance') + $payroll->items->sum('other_allowance'));
        $totalOvertime = $isSar ? round($payroll->items->sum('overtime_amount') * $rate, 3) : $payroll->items->sum('overtime_amount');
        
        $totalLate = $isSar ? round($payroll->items->sum('late_deduction') * $rate, 3) : $payroll->items->sum('late_deduction');
        $totalAbsence = $isSar ? round($payroll->items->sum('absence_deduction') * $rate, 3) : $payroll->items->sum('absence_deduction');
        $totalUnpaidLeave = $isSar ? round($payroll->items->sum('unpaid_leave_deduction') * $rate, 3) : $payroll->items->sum('unpaid_leave_deduction');

        $totalAdvanceDeductions = $isSar ? round($payroll->items->sum('advance_deduction') * $rate, 3) : $payroll->items->sum('advance_deduction');
        $totalPenaltyDeductions = $isSar ? round($payroll->items->sum('penalty_deduction') * $rate, 3) : $payroll->items->sum('penalty_deduction');

        $netSalary = $isSar ? round($payroll->total_net * $rate, 3) : $payroll->total_net;

        // المصروف الفعلي للرواتب بعد خصم الغياب والتأخير (لأننا لا ندفع مقابل الغياب فلا يعتبر مصروف)
        $actualBasicExpense = $totalBasic - $totalLate - $totalAbsence - $totalUnpaidLeave;

        $lines = [];

        // 1. مدين: مصاريف الرواتب (5400)
        if ($actualBasicExpense > 0) {
            $lines[] = [
                'account_id' => self::account('5400')->id,
                'debit' => $actualBasicExpense,
                'credit' => 0,
                'description' => "مصاريف الرواتب الأساسية",
            ];
        }

        // 2. مدين: مصاريف البدلات (5410)
        if ($totalAllowances > 0) {
            $lines[] = [
                'account_id' => self::account('5410')->id,
                'debit' => $totalAllowances,
                'credit' => 0,
                'description' => "مصاريف بدلات الموظفين",
            ];
        }

        // 3. مدين: مصاريف العمل الإضافي (5420)
        if ($totalOvertime > 0) {
            $lines[] = [
                'account_id' => self::account('5420')->id,
                'debit' => $totalOvertime,
                'credit' => 0,
                'description' => "مصاريف العمل الإضافي",
            ];
        }

        // 4. دائن: ذمم الموظفين للخصومات (سلف) (1300)
        if ($totalAdvanceDeductions > 0) {
            $lines[] = [
                'account_id' => self::account('1300')->id,
                'debit' => 0,
                'credit' => $totalAdvanceDeductions,
                'description' => "سداد سلف الموظفين",
            ];
        }

        // 5. دائن: إيرادات أخرى للمخالفات المالية (4003)
        if ($totalPenaltyDeductions > 0) {
            $lines[] = [
                'account_id' => self::account('4003')->id,
                'debit' => 0,
                'credit' => $totalPenaltyDeductions,
                'description' => "خصومات المخالفات المالية",
            ];
        }

        // 6. دائن: البنك/الصندوق لإجمالي الرواتب المحولة (الصافي) (1102)
        // نفترض أن مسير الرواتب يُدفع عبر البنك (يمكن تخصيصها لاحقاً)
        if ($netSalary > 0) {
            $lines[] = [
                'account_id' => self::account('1102')->id,
                'debit' => 0,
                'credit' => $netSalary,
                'description' => "تحويل رواتب الموظفين",
            ];
        }

        return self::createEntry(
            "مسير الرواتب رقم {$payroll->payroll_number} لشهر {$payroll->month}/{$payroll->year} ({$payroll->currency})",
            'payroll',
            $payroll->id,
            $lines
        );
    }

    /**
     * قيد اعتماد فاتورة (وكلاء متعددون)
     * مدين: حساب العميل (إجمالي البيع)
     * دائن: حساب كل وكيل (تكلفته محولة بالدينار)
     * دائن: إيرادات الخدمات (الربح)
     */
    public static function recordInvoice(Invoice $invoice): JournalEntry
    {
        $invoice->load(['items.service']);

        $clientAccount = $invoice->client->account_id
            ? Account::find($invoice->client->account_id)
            : self::account('1200');

        // إيرادات الخدمات
        $revenueParent = self::account('4001');
        $revenueChildren = Account::where('parent_id', $revenueParent->id)->get();

        $rate = $invoice->exchange_rate_snapshot ?? self::getExchangeRate();

        $totalSellJod = (float) $invoice->total_sell_jod;
        $agentCosts = []; // [agent_id => cost_jod]
        $revenueProfits = []; // [account_id => profit_jod]

        $totalCostJod = (float) $invoice->total_cost_jod;
        $netProfitJod = (float) $invoice->profit_jod;
        $itemsCount = count($invoice->items);

        foreach ($invoice->items as $item) {
            $costJod = (float) ($item->total_cost_jod ?: round($item->total_cost_sar * $rate, 3));
            $agentId = $item->agent_id;

            if ($agentId) {
                $agentCosts[$agentId] = ($agentCosts[$agentId] ?? 0) + $costJod;
            }

            // توزيع أرباح الفاتورة الكلية على البنود نسبياً حسب التكلفة
            if ($totalCostJod > 0) {
                $itemProfit = round(($costJod / $totalCostJod) * $netProfitJod, 3);
            } else {
                $itemProfit = $itemsCount > 0 ? round($netProfitJod / $itemsCount, 3) : 0;
            }
            
            $matchedAccount = $revenueParent;
            $serviceName = $item->service ? $item->service->name : '';
            
            if ($serviceName && $revenueChildren->count() > 0) {
                $matchedChild = $revenueChildren->first(function($acc) use ($serviceName) {
                    $accName = mb_strtolower($acc->name);
                    $srvName = mb_strtolower($serviceName);
                    // Match common words
                    $keywords = ['تأشير', 'طيران', 'تذكر', 'سياح', 'فندق'];
                    foreach ($keywords as $kw) {
                        if (mb_strpos($srvName, $kw) !== false && mb_strpos($accName, $kw) !== false) {
                            return true;
                        }
                    }
                    return false;
                });
                
                if ($matchedChild) {
                    $matchedAccount = $matchedChild;
                } else {
                    // Fallback to first child if no match, just like old behavior but safer
                    // Actually, if no match, putting it in 4001 (Parent) or the first child is fine.
                    // Let's use the first child if it exists, since 4001 might not accept direct entries in strict systems.
                    $matchedAccount = $revenueChildren->first();
                }
            } elseif ($revenueChildren->count() > 0) {
                $matchedAccount = $revenueChildren->first();
            }

            $revenueProfits[$matchedAccount->id] = ($revenueProfits[$matchedAccount->id] ?? 0) + $itemProfit;
        }

        $lines = [];

        // مدين: حساب العميل ← إجمالي البيع
        $lines[] = [
            'account_id' => $clientAccount->id,
            'debit' => $totalSellJod,
            'credit' => 0,
            'description' => "فاتورة {$invoice->invoice_number} على {$invoice->client->name}",
        ];

        // دائن: كل وكيل بحصته
        foreach ($agentCosts as $agentId => $costJod) {
            $agent = \App\Models\Agent::find($agentId);
            $agentAccount = ($agent && $agent->account_id)
                ? Account::find($agent->account_id)
                : self::account('2110');

            if ($costJod > 0) {
                $lines[] = [
                    'account_id' => $agentAccount->id,
                    'debit' => 0,
                    'credit' => $costJod,
                    'description' => "تكلفة خدمات من {$agent->name}",
                ];
            }
        }

        // دائن: الربح موزّع على حسابات الإيرادات
        foreach ($revenueProfits as $accId => $profit) {
            if ($profit > 0) {
                $acc = Account::find($accId) ?? $revenueParent;
                $lines[] = [
                    'account_id' => $acc->id,
                    'debit' => 0,
                    'credit' => $profit,
                    'description' => "ربح فاتورة {$invoice->invoice_number}",
                ];
            }
        }

        // ضمان التوازن (فرق التقريب)
        $totalDebit = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));
        $diff = round($totalDebit - $totalCredit, 3);
        if ($diff != 0) {
            $lastIdx = count($lines) - 1;
            if ($diff > 0) {
                $lines[$lastIdx]['credit'] = round($lines[$lastIdx]['credit'] + $diff, 3);
            } else {
                $adjustIdx = isset($lines[1]) ? 1 : $lastIdx;
                $lines[$adjustIdx]['credit'] = round($lines[$adjustIdx]['credit'] - $diff, 3);
            }
        }

        return self::createEntry(
            "فاتورة {$invoice->invoice_number} — {$invoice->client->name}",
            'invoice',
            $invoice->id,
            $lines
        );
    }



    /**
     * قيد اعتماد مصروف
     * مدين: مصاريف تشغيلية
     * دائن: الصندوق/البنك
     * (إذا كانت العملة SAR يتم التحويل)
     */
    public static function recordExpense(Expense $expense): JournalEntry
    {
        $expense->loadMissing('category');

        $paymentAccount = self::paymentAccount($expense->payment_method);

        // استخدام حساب التصنيف الفرعي إن وجد، وإلا الحساب الأب
        $expenseAccount = ($expense->category && $expense->category->account_id)
            ? Account::find($expense->category->account_id)
            : self::account('5100');

        if (!$expenseAccount) {
            $expenseAccount = self::account('5100');
        }

        // تحويل العملة إذا كان المبلغ بالريال
        $amountJod = ($expense->currency === 'SAR')
            ? self::sarToJod($expense->amount)
            : (float) $expense->amount;

        return self::createEntry(
            "مصروف {$expense->expense_number} — {$expense->description}",
            'expense',
            $expense->id,
            [
                [
                    'account_id' => $expenseAccount->id,
                    'debit' => $amountJod,
                    'credit' => 0,
                    'description' => $expense->description,
                ],
                [
                    'account_id' => $paymentAccount->id,
                    'debit' => 0,
                    'credit' => $amountJod,
                    'description' => "دفع مصروف {$expense->expense_number}",
                ],
            ],
            $expense->expense_date?->toDateString()
        );
    }

    /**
     * قيد اعتماد سند صرف
     * مدين: الحساب المختار من شجرة الحسابات (حسب طبيعته)
     * دائن: الصندوق/البنك/الشيك
     */
    public static function recordDisbursement(\App\Models\Disbursement $disbursement): JournalEntry
    {
        $paymentAccount = self::paymentAccount($disbursement->payment_method);
        $targetAccount = Account::findOrFail($disbursement->account_id);

        // تحويل المبلغ للدينار إذا كانت العملة ريال
        $amountJod = ($disbursement->currency === 'SAR')
            ? self::sarToJod($disbursement->amount)
            : (float) $disbursement->amount;

        // تحديد طبيعة الحساب: الأصول والمصروفات طبيعتهم مدينة
        // الالتزامات وحقوق الملكية والإيرادات طبيعتهم دائنة
        // سند الصرف دائماً: مدين الحساب المستهدف / دائن مصدر الدفع
        return self::createEntry(
            "سند صرف {$disbursement->disbursement_number} — {$disbursement->description}",
            'disbursement',
            $disbursement->id,
            [
                [
                    'account_id' => $targetAccount->id,
                    'debit' => $amountJod,
                    'credit' => 0,
                    'description' => $disbursement->description,
                ],
                [
                    'account_id' => $paymentAccount->id,
                    'debit' => 0,
                    'credit' => $amountJod,
                    'description' => "دفع سند صرف {$disbursement->disbursement_number}",
                ],
            ],
            $disbursement->disbursement_date?->toDateString()
        );
    }

    /**
     * تسجيل فرق الحوالة كمصروف أو إيراد
     * الفرق = cost_jod - (amount_sar / 0.19)
     */
    public static function recordTransferDifference(Transfer $transfer): ?JournalEntry
    {
        $difference = (float) $transfer->difference_amount;
        if ($difference == 0) return null;

        $paymentAccount = self::paymentAccount($transfer->payment_method);

        if ($transfer->difference_type === 'expense') {
            // فرق مصروف: مدين حساب المصاريف / دائن الصندوق
            $expenseAccountId = null;
            if ($transfer->expense_category_id) {
                $cat = \App\Models\ExpenseCategory::find($transfer->expense_category_id);
                $expenseAccountId = $cat && $cat->account_id
                    ? $cat->account_id
                    : self::account('5100')->id;
            } else {
                $expenseAccountId = self::account('5100')->id;
            }

            return self::createEntry(
                "فرق حوالة {$transfer->transfer_number} (مصروف)",
                'transfer_difference',
                $transfer->id,
                [
                    [
                        'account_id' => $expenseAccountId,
                        'debit' => abs($difference),
                        'credit' => 0,
                        'description' => "فرق مصروف حوالة {$transfer->transfer_number}",
                    ],
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => 0,
                        'credit' => abs($difference),
                        'description' => "دفع فرق حوالة {$transfer->transfer_number}",
                    ],
                ]
            );
        } else {
            // فرق إيراد: مدين الصندوق / دائن حساب الإيرادات
            $revenueAccountId = $transfer->revenue_account_id
                ?? self::account('4003')->id; // إيرادات أخرى

            return self::createEntry(
                "فرق حوالة {$transfer->transfer_number} (إيراد)",
                'transfer_difference',
                $transfer->id,
                [
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => abs($difference),
                        'credit' => 0,
                        'description' => "إيراد فرق حوالة {$transfer->transfer_number}",
                    ],
                    [
                        'account_id' => $revenueAccountId,
                        'debit' => 0,
                        'credit' => abs($difference),
                        'description' => "إيراد فرق حوالة {$transfer->transfer_number}",
                    ],
                ]
            );
        }
    }

    // ============================================================
    // إقفال نهاية السنة المالية (Year-End Closing)
    // ============================================================

    /**
     * إقفال السنة المالية
     *
     * الخطوات:
     * 1. إقفال حسابات الإيرادات → الأرباح المحتجزة
     * 2. إقفال حسابات المصروفات → الأرباح المحتجزة
     * 3. تقفيل الفترة ومنع التسجيل
     */
    public static function closeYear(int $year): array
    {
        $retainedEarnings = self::account('3002');
        $fromDate = "{$year}-01-01";
        $toDate = "{$year}-12-31";

        // التحقق أن السنة غير مقفلة
        $existing = AccountingPeriod::where('year', $year)->where('month', 0)->first();
        if ($existing && $existing->isClosed()) {
            throw new \Exception("السنة المالية {$year} مقفلة مسبقاً");
        }

        return DB::transaction(function () use ($year, $retainedEarnings, $fromDate, $toDate) {
            $closingLines = [];
            $totalRevenue = 0;
            $totalExpenses = 0;

            // 1. إقفال حسابات الإيرادات (type = revenue)
            $revenueAccounts = Account::where('type', 'revenue')
                ->where('is_active', true)->get();
            foreach ($revenueAccounts as $acc) {
                $totals = $acc->totalsForPeriod($fromDate, $toDate . ' 23:59:59');
                $balance = $totals['total_credit'] - $totals['total_debit'];
                if ($balance != 0) {
                    $closingLines[] = [
                        'account_id' => $acc->id,
                        'debit' => round($balance, 3),  // إقفال: عكس الرصيد
                        'credit' => 0,
                        'description' => "إقفال {$acc->name} للسنة {$year}",
                    ];
                    $totalRevenue += $balance;
                }
            }

            // 2. إقفال حسابات المصروفات (type = expense)
            $expenseAccounts = Account::where('type', 'expense')
                ->where('is_active', true)->get();
            foreach ($expenseAccounts as $acc) {
                $totals = $acc->totalsForPeriod($fromDate, $toDate . ' 23:59:59');
                $balance = $totals['total_debit'] - $totals['total_credit'];
                if ($balance != 0) {
                    $closingLines[] = [
                        'account_id' => $acc->id,
                        'debit' => 0,
                        'credit' => round($balance, 3),  // إقفال: عكس الرصيد
                        'description' => "إقفال {$acc->name} للسنة {$year}",
                    ];
                    $totalExpenses += $balance;
                }
            }

            // 3. صافي الربح/الخسارة → الأرباح المحتجزة
            $netIncome = round($totalRevenue - $totalExpenses, 3);
            if ($netIncome != 0) {
                if ($netIncome > 0) {
                    // ربح: يُضاف للأرباح المحتجزة (دائن)
                    $closingLines[] = [
                        'account_id' => $retainedEarnings->id,
                        'debit' => 0,
                        'credit' => $netIncome,
                        'description' => "صافي ربح السنة {$year}",
                    ];
                } else {
                    // خسارة: يُخصم من الأرباح المحتجزة (مدين)
                    $closingLines[] = [
                        'account_id' => $retainedEarnings->id,
                        'debit' => abs($netIncome),
                        'credit' => 0,
                        'description' => "صافي خسارة السنة {$year}",
                    ];
                }
            }

            if (empty($closingLines)) {
                throw new \Exception("لا توجد أرصدة لإقفالها في السنة {$year}");
            }

            // إنشاء قيد الإقفال
            $entry = self::createEntry(
                "قيد إقفال السنة المالية {$year}",
                'year_closing',
                $year,
                $closingLines,
                "{$year}-12-31"
            );

            // إقفال الفترة
            AccountingPeriod::updateOrCreate(
                ['year' => $year, 'month' => 0],
                [
                    'status' => 'closed',
                    'closed_by' => auth()->id(),
                    'closed_at' => now(),
                    'closing_entry_number' => $entry->entry_number,
                ]
            );

            return [
                'entry' => $entry,
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome,
            ];
        });
    }

    /**
     * تقرير قائمة الدخل (Profit & Loss)
     */
    public static function profitAndLoss(string $from, string $to): array
    {
        $toEnd = $to . ' 23:59:59';

        $revenues = Account::where('type', 'revenue')->where('is_active', true)
            ->whereDoesntHave('children') // الحسابات الورقية فقط
            ->get()
            ->map(function ($acc) use ($from, $toEnd) {
                $totals = $acc->totalsForPeriod($from, $toEnd);
                return [
                    'id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'amount' => round($totals['total_credit'] - $totals['total_debit'], 3),
                ];
            })->filter(fn($r) => $r['amount'] != 0)->values();

        $expenses = Account::where('type', 'expense')->where('is_active', true)
            ->whereDoesntHave('children') // الحسابات الورقية فقط
            ->get()
            ->map(function ($acc) use ($from, $toEnd) {
                $totals = $acc->totalsForPeriod($from, $toEnd);
                return [
                    'id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'amount' => round($totals['total_debit'] - $totals['total_credit'], 3),
                ];
            })->filter(fn($r) => $r['amount'] != 0)->values();

        $totalRevenue = $revenues->sum('amount');
        $totalExpenses = $expenses->sum('amount');

        return [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_income' => round($totalRevenue - $totalExpenses, 3),
        ];
    }

    /**
     * تقرير الميزانية العمومية (Balance Sheet)
     */
    public static function balanceSheet(string $asOfDate): array
    {
        $getAccounts = function ($type) use ($asOfDate) {
            return Account::where('type', $type)->where('is_active', true)
                ->whereDoesntHave('children') // الحسابات الورقية فقط
                ->get()
                ->map(function ($acc) use ($asOfDate, $type) {
                    $totals = $acc->totalsForPeriod(null, $asOfDate . ' 23:59:59');
                    $balance = in_array($type, ['asset', 'expense'])
                        ? $totals['total_debit'] - $totals['total_credit']
                        : $totals['total_credit'] - $totals['total_debit'];
                    return [
                        'id' => $acc->id,
                        'code' => $acc->code,
                        'name' => $acc->name,
                        'balance' => round($balance, 3),
                    ];
                })->filter(fn($r) => $r['balance'] != 0)->values();
        };

        $assets = $getAccounts('asset');
        $liabilities = $getAccounts('liability');
        $equity = $getAccounts('equity');

        $totalAssets = $assets->sum('balance');
        $totalLiabilities = $liabilities->sum('balance');
        $totalEquity = $equity->sum('balance');

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => round($totalAssets, 3),
            'total_liabilities' => round($totalLiabilities, 3),
            'total_equity' => round($totalEquity, 3),
            'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 3),
            'is_balanced' => round($totalAssets, 3) === round($totalLiabilities + $totalEquity, 3),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /**
     * إرسال إشعار لمستخدم واحد
     */
    public static function send(int $userId, string $title, string $body, array $options = []): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $options['type'] ?? 'info',
            'icon' => $options['icon'] ?? null,
            'action_url' => $options['action_url'] ?? null,
            'data' => $options['data'] ?? null,
            'is_read' => false,
        ]);
    }

    /**
     * إرسال إشعار لعدة مستخدمين
     */
    public static function sendToMany(array $userIds, string $title, string $body, array $options = []): void
    {
        foreach ($userIds as $userId) {
            self::send($userId, $title, $body, $options);
        }
    }

    /**
     * إرسال إشعار لكل الأدمن (عند إنشاء عملية بانتظار الاعتماد)
     */
    public static function notifyAdmins(string $title, string $body, array $options = []): void
    {
        $adminIds = User::whereHas('role', fn($q) => $q->where('slug', 'admin'))
            ->where('id', '!=', auth()->id())
            ->pluck('id')
            ->toArray();

        self::sendToMany($adminIds, $title, $body, $options);
    }

    /**
     * إرسال إشعار لكل المستخدمين ما عدا المرسِل
     */
    public static function broadcast(string $title, string $body, array $options = [], ?int $excludeUserId = null): void
    {
        $users = User::query();
        if ($excludeUserId) {
            $users->where('id', '!=', $excludeUserId);
        }
        $userIds = $users->pluck('id')->toArray();
        self::sendToMany($userIds, $title, $body, $options);
    }

    // ─── أحداث الإنشاء (بانتظار الاعتماد) ───

    /**
     * إشعار عند إنشاء حوالة جديدة بانتظار الاعتماد
     */
    public static function transferCreated($transfer): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '💱 حوالة جديدة بانتظار الاعتماد',
            "{$creatorName} أنشأ حوالة {$transfer->transfer_number} بمبلغ " . number_format($transfer->amount_sar, 2) . ' SAR',
            [
                'type' => 'transfer',
                'icon' => '💱',
                'action_url' => "/transfers?status=pending&highlight={$transfer->id}",
                'data' => ['reference_type' => 'transfer', 'reference_id' => $transfer->id],
            ]
        );
    }

    /**
     * إشعار عند إنشاء سند قبض بانتظار الاعتماد
     */
    public static function receiptCreated($receipt): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '📄 سند قبض جديد بانتظار الاعتماد',
            "{$creatorName} أنشأ سند قبض {$receipt->receipt_number} بمبلغ " . number_format($receipt->amount_jod, 3) . ' JOD',
            [
                'type' => 'receipt',
                'icon' => '📄',
                'action_url' => "/receipts?highlight={$receipt->id}",
                'data' => ['reference_type' => 'receipt', 'reference_id' => $receipt->id],
            ]
        );
    }

    /**
     * إشعار عند إنشاء مصروف بانتظار الاعتماد
     */
    public static function expenseCreated($expense): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '💰 مصروف جديد بانتظار الاعتماد',
            "{$creatorName} أنشأ مصروف {$expense->expense_number} بمبلغ " . number_format($expense->amount, 2) . " {$expense->currency}",
            [
                'type' => 'expense',
                'icon' => '💰',
                'action_url' => "/expenses?highlight={$expense->id}",
                'data' => ['reference_type' => 'expense', 'reference_id' => $expense->id],
            ]
        );
    }

    /**
     * إشعار عند إنشاء مخالفة بانتظار الاعتماد
     */
    public static function violationCreated($violation): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '⚠️ مخالفة جديدة بانتظار الاعتماد',
            "{$creatorName} سجّل مخالفة {$violation->violation_number} بمبلغ " . number_format($violation->cost_sar, 2) . ' SAR',
            [
                'type' => 'violation',
                'icon' => '⚠️',
                'action_url' => "/violations?status=pending&highlight={$violation->id}",
                'data' => ['reference_type' => 'violation', 'reference_id' => $violation->id],
            ]
        );
    }

    /**
     * إشعار عند إنشاء فاتورة بانتظار الاعتماد
     */
    public static function invoiceCreated($invoice): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '🧾 فاتورة جديدة بانتظار الاعتماد',
            "{$creatorName} أنشأ فاتورة {$invoice->invoice_number} بمبلغ " . number_format($invoice->total_jod, 3) . ' JOD',
            [
                'type' => 'invoice',
                'icon' => '🧾',
                'action_url' => "/invoices?status=pending&highlight={$invoice->id}",
                'data' => ['reference_type' => 'invoice', 'reference_id' => $invoice->id],
            ]
        );
    }

    // ─── أحداث الاعتماد ───

    /**
     * إشعار عند اعتماد حوالة
     */
    public static function transferApproved($transfer): void
    {
        // إشعار لصانع الحوالة أن حوالته اعتمدت
        if ($transfer->created_by && $transfer->created_by !== auth()->id()) {
            self::send($transfer->created_by,
                '✅ تم اعتماد حوالتك',
                "تم اعتماد الحوالة {$transfer->transfer_number} بمبلغ " . number_format($transfer->amount_sar, 2) . ' SAR',
                [
                    'type' => 'transfer',
                    'icon' => '✅',
                    'action_url' => "/transfers?highlight={$transfer->id}",
                    'data' => ['reference_type' => 'transfer', 'reference_id' => $transfer->id],
                ]
            );
        }
    }

    /**
     * إشعار عند اعتماد مصروف
     */
    public static function expenseApproved($expense): void
    {
        if ($expense->created_by && $expense->created_by !== auth()->id()) {
            self::send($expense->created_by,
                '✅ تم اعتماد المصروف',
                "تم اعتماد المصروف {$expense->expense_number} بمبلغ " . number_format($expense->amount, 2) . " {$expense->currency}",
                [
                    'type' => 'expense',
                    'icon' => '✅',
                    'action_url' => "/expenses?highlight={$expense->id}",
                    'data' => ['reference_type' => 'expense', 'reference_id' => $expense->id],
                ]
            );
        }
    }

    /**
     * إشعار عند اعتماد سند قبض
     */
    public static function receiptApproved($receipt): void
    {
        if ($receipt->created_by && $receipt->created_by !== auth()->id()) {
            self::send($receipt->created_by,
                '✅ تم اعتماد سند القبض',
                "تم اعتماد سند القبض {$receipt->receipt_number} بمبلغ " . number_format($receipt->amount_jod, 3) . ' JOD',
                [
                    'type' => 'receipt',
                    'icon' => '✅',
                    'action_url' => "/receipts?highlight={$receipt->id}",
                    'data' => ['reference_type' => 'receipt', 'reference_id' => $receipt->id],
                ]
            );
        }
    }

    // ─── أحداث الرفض ───

    /**
     * إشعار عام عند رفض عملية — يُرسل لصانع العملية
     */
    public static function operationRejected($model, string $typeLabel, string $number): void
    {
        $createdBy = $model->created_by ?? null;
        if ($createdBy && $createdBy !== auth()->id()) {
            self::send($createdBy,
                "❌ تم رفض {$typeLabel}",
                "تم رفض {$typeLabel} رقم {$number}",
                [
                    'type' => 'rejected',
                    'icon' => '❌',
                    'action_url' => null,
                    'data' => ['number' => $number],
                ]
            );
        }
    }

    // ─── إشعارات الموارد البشرية ───

    /**
     * إشعار عند إنشاء طلب إجازة
     */
    public static function leaveRequestCreated($leave): void
    {
        $empName = $leave->employee?->user?->name ?? 'موظف';
        self::notifyAdmins(
            '🏖️ طلب إجازة جديد',
            "{$empName} تقدم بطلب إجازة {$leave->request_number} من {$leave->start_date->format('Y-m-d')} إلى {$leave->end_date->format('Y-m-d')} ({$leave->days_count} يوم)",
            [
                'type' => 'leave',
                'icon' => '🏖️',
                'action_url' => "/leaves?status=pending&highlight={$leave->id}",
                'data' => ['reference_type' => 'leave_request', 'reference_id' => $leave->id],
            ]
        );
    }

    /**
     * إشعار عند اعتماد طلب إجازة
     */
    public static function leaveApproved($leave): void
    {
        $employeeUserId = $leave->employee?->user_id;
        if ($employeeUserId && $employeeUserId !== auth()->id()) {
            self::send($employeeUserId,
                '✅ تم اعتماد طلب الإجازة',
                "تم اعتماد إجازتك {$leave->request_number} من {$leave->start_date->format('Y-m-d')} إلى {$leave->end_date->format('Y-m-d')}",
                [
                    'type' => 'leave',
                    'icon' => '✅',
                    'action_url' => '/leaves',
                    'data' => ['reference_type' => 'leave_request', 'reference_id' => $leave->id],
                ]
            );
        }
    }

    /**
     * إشعار عند إنشاء طلب سلفة
     */
    public static function advanceCreated($advance): void
    {
        $empName = $advance->employee?->user?->name ?? 'موظف';
        self::notifyAdmins(
            '💳 طلب سلفة جديد',
            "{$empName} تقدم بطلب سلفة {$advance->advance_number} بمبلغ " . number_format($advance->amount, 2) . " {$advance->currency}",
            [
                'type' => 'advance',
                'icon' => '💳',
                'action_url' => "/advances?status=pending&highlight={$advance->id}",
                'data' => ['reference_type' => 'advance', 'reference_id' => $advance->id],
            ]
        );
    }

    /**
     * إشعار عند اعتماد سلفة
     */
    public static function advanceApproved($advance): void
    {
        $employeeUserId = $advance->employee?->user_id;
        if ($employeeUserId && $employeeUserId !== auth()->id()) {
            self::send($employeeUserId,
                '✅ تم اعتماد السلفة',
                "تم اعتماد سلفتك {$advance->advance_number} بمبلغ " . number_format($advance->amount, 2) . " {$advance->currency}",
                [
                    'type' => 'advance',
                    'icon' => '✅',
                    'action_url' => '/advances',
                    'data' => ['reference_type' => 'advance', 'reference_id' => $advance->id],
                ]
            );
        }
    }

    /**
     * إشعار عند إصدار مخالفة للموظف
     */
    public static function penaltyIssued($penalty): void
    {
        $employeeUserId = $penalty->employee?->user_id;
        if ($employeeUserId) {
            $typeLabel = $penalty->penalty_type === 'warning' ? 'لفت نظر' : 'خصم مالي';
            self::send($employeeUserId,
                "⚠️ مخالفة جديدة: {$typeLabel}",
                "تم إصدار مخالفة {$penalty->penalty_number}: {$penalty->reason}",
                [
                    'type' => 'penalty',
                    'icon' => '⚠️',
                    'action_url' => '/penalties',
                    'data' => ['reference_type' => 'employee_penalty', 'reference_id' => $penalty->id],
                ]
            );
        }
    }

    /**
     * إشعار عند اعتماد مسير الرواتب — لكل الموظفين في المسير
     */
    public static function payrollReady($payroll): void
    {
        $payroll->load('items.employee');
        $months = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                   'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $periodLabel = ($months[$payroll->month] ?? '') . ' ' . $payroll->year;

        foreach ($payroll->items as $item) {
            $employeeUserId = $item->employee?->user_id;
            if ($employeeUserId) {
                self::send($employeeUserId,
                    '💰 قسيمة راتبك جاهزة',
                    "تم اعتماد راتب {$periodLabel} — صافي: " . number_format((float) $item->net_salary, 2) . " {$payroll->currency}",
                    [
                        'type' => 'payroll',
                        'icon' => '💰',
                        'action_url' => "/payslip/{$item->employee_id}/{$payroll->month}/{$payroll->year}",
                        'data' => ['reference_type' => 'payroll', 'reference_id' => $payroll->id],
                    ]
                );
            }
        }
    }
}

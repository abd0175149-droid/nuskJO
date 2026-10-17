<?php

namespace App\Services;

use App\Events\NewNotification;
use App\Jobs\SendPushNotification;
use App\Models\Notification;
use App\Models\User;

/**
 * الإشعارات: الجرس داخل النظام (قناة خاصة بالمستخدم) وإشعار الجهاز (FCM).
 *
 * تقليل البيانات (E15.10، docs/security/DATA-MINIMIZATION.md): إشعار الجهاز يمر بطرف ثالث ويُعرض على شاشة القفل،
 * فإشعارات الواتساب (type=whatsapp) تصله بعنوان ثابت وجسم «افتح النظام للتفاصيل» مهما كان نص الجرس، والرابط يفتح
 * المحادثة أو الطلب. ونص الجرس نفسه بلا رقم هاتف ولا تفاصيل طلب (WaConversation::noticeLabel ورقم الطلب).
 * options['push'] = ['title' => …, 'body' => …] لنص جهاز مختلف عن الجرس؛ جسم الواتساب لا يُتجاوز.
 */
class NotificationService
{
    public const WHATSAPP_PUSH_BODY = 'افتح النظام للتفاصيل';

    /**
     * إرسال إشعار لمستخدم واحد
     */
    public static function send(int $userId, string $title, string $body, array $options = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $options['type'] ?? 'info',
            'icon' => $options['icon'] ?? null,
            'action_url' => $options['action_url'] ?? null,
            'data' => $options['data'] ?? null,
            'is_read' => false,
        ]);

        // الجرس في الصفحات المفتوحة يتحدّث فوراً (Reverb)، والجهاز يتلقى إشعار نظام (FCM).
        // كلاهما في الطابور وبعد اعتماد المعاملة، وفشلهما لا يُفشل العملية التي أنشأت الإشعار
        try {
            event(new NewNotification($userId, self::toBell($notification)));
        } catch (\Throwable $e) {
            report($e);
        }
        [$pushTitle, $pushBody] = self::pushText($title, $body, $options);
        try {
            // sync_push: تنبيهات التشغيل تُدفع مباشرة، فتصل والطابور متوقف (OpsAlerts)
            if (!empty($options['sync_push'])) {
                PushService::toUser($userId, $pushTitle, $pushBody, $notification->action_url, 'n' . $notification->id);
            } else {
                // الوظيفة تحمل نص الجهاز وحده، فلا تدخل الأسماء حمولة jobs ولا failed_jobs
                SendPushNotification::dispatch($userId, $pushTitle, $pushBody, $notification->action_url, 'n' . $notification->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $notification;
    }

    /** نص إشعار الجهاز: للواتساب عنوان الإشعار الثابت (أو push.title) وجسم عام دائماً @return array{0: string, 1: string} */
    public static function pushText(string $title, string $body, array $options = []): array
    {
        $push = (array) ($options['push'] ?? []);
        if (($options['type'] ?? null) === 'whatsapp') {
            return [(string) ($push['title'] ?? $title), self::WHATSAPP_PUSH_BODY];
        }

        return [(string) ($push['title'] ?? $title), (string) ($push['body'] ?? $body)];
    }

    /** بنفس شكل /api/notifications ليُضاف لقائمة الجرس كما هو */
    private static function toBell(Notification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title ?? '',
            'body' => $n->body ?? '',
            'type' => $n->type ?? 'info',
            'icon' => $n->icon ?? 'ℹ️',
            'action_url' => $n->action_url,
            'is_read' => false,
            'time_ago' => 'الآن',
            'created_at' => $n->created_at?->toISOString() ?? now()->toISOString(),
        ];
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
            ->where('is_active', true)
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
        $users = User::where('is_active', true);
        if ($excludeUserId) {
            $users->where('id', '!=', $excludeUserId);
        }
        $userIds = $users->pluck('id')->toArray();
        self::sendToMany($userIds, $title, $body, $options);
    }

    // ─── أحداث الإنشاء (بانتظار الاعتماد) ───

    /**
     * إشعار عند إنشاء سند صرف جديد بانتظار الاعتماد
     */
    public static function disbursementCreated($disbursement): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        self::notifyAdmins(
            '📤 سند صرف جديد بانتظار الاعتماد',
            "{$creatorName} أنشأ سند صرف {$disbursement->disbursement_number} بمبلغ " . number_format($disbursement->amount, 3) . " {$disbursement->currency}",
            [
                'type' => 'disbursement',
                'icon' => '📤',
                'action_url' => "/disbursements?status=pending&highlight={$disbursement->id}",
                'data' => ['reference_type' => 'disbursement', 'reference_id' => $disbursement->id],
            ]
        );
    }

    /**
     * إشعار عند إنشاء فاتورة بانتظار الاعتماد
     */
    public static function invoiceCreated($invoice): void
    {
        $creatorName = auth()->user()->name ?? 'موظف';
        $clientName = $invoice->client->name ?? '—';
        self::notifyAdmins(
            '🧾 فاتورة جديدة بانتظار الاعتماد',
            "{$creatorName} أنشأ فاتورة {$invoice->invoice_number} للعميل {$clientName} بمبلغ " . number_format($invoice->total_jod, 3) . ' JOD',
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
     * إشعار عند اعتماد سند صرف
     */
    public static function disbursementApproved($disbursement): void
    {
        // إشعار لصانع السند أن سنده اعتمد
        if ($disbursement->created_by && $disbursement->created_by !== auth()->id()) {
            self::send($disbursement->created_by,
                '✅ تم اعتماد سند الصرف',
                "تم اعتماد سند الصرف {$disbursement->disbursement_number} بمبلغ " . number_format($disbursement->amount, 3) . " {$disbursement->currency}",
                [
                    'type' => 'disbursement',
                    'icon' => '✅',
                    'action_url' => "/disbursements?highlight={$disbursement->id}",
                    'data' => ['reference_type' => 'disbursement', 'reference_id' => $disbursement->id],
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
     * إشعار عند إنشاء سند قبض
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
                'action_url' => "/receipts?status=pending&highlight={$receipt->id}",
                'data' => ['reference_type' => 'receipt', 'reference_id' => $receipt->id],
            ]
        );
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

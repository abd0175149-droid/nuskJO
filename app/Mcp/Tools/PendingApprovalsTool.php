<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;

/** الاعتماد نفسه من لوحة التحكم وحدها: الأداة تعرض القائمة فقط */
#[IsReadOnly]
#[IsIdempotent]
final class PendingApprovalsTool extends Tool
{
    public function name(): string
    {
        return 'pending_approvals';
    }

    public function title(): string
    {
        return 'ما ينتظر الاعتماد';
    }

    public function description(): string
    {
        return 'الفواتير وسندات القبض وسندات الصرف التي تنتظر الاعتماد: العدد وأول ثمانية من كل نوع برقمه ومبلغه. لا تعتمد شيئاً.';
    }

    public function permissions(): array
    {
        // كصلاحية المساعد الإداري لهذا التقرير (AdminTools::RBAC_REQUIRES['report.approvals'])
        return ['invoices.approve', 'receipts.approve', 'disbursements.approve'];
    }

    protected function run(array $args): array
    {
        return OwnerReports::approvals();
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;

#[IsReadOnly]
#[IsIdempotent]
final class DailySummaryTool extends Tool
{
    public function name(): string
    {
        return 'daily_summary';
    }

    public function title(): string
    {
        return 'ملخّص اليوم';
    }

    public function description(): string
    {
        return 'ملخّص اليوم في نظام نسك: الفواتير المعتمدة اليوم وإجماليها، والفواتير المنشأة، والمقبوضات المعتمدة، وما ينتظر الاعتماد، ومسافرو اليوم.';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    protected function run(array $args): array
    {
        return OwnerReports::dailySummary(withWhatsapp: false);
    }
}

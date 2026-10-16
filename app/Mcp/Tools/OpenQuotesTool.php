<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;

/** بلا نص الطلب ولا اسم العميل ولا رقمه: نص الطلب من المحادثة، فلا يغادر النظام */
#[IsReadOnly]
#[IsIdempotent]
final class OpenQuotesTool extends Tool
{
    public function name(): string
    {
        return 'open_quotes';
    }

    public function title(): string
    {
        return 'طلبات التسعير المفتوحة';
    }

    public function description(): string
    {
        return 'طلبات التسعير والحجز المفتوحة (جديدة أو مسعّرة لم تُغلق): رقم الطلب ونوعه وحالته وعنوان العرض والموظف المسند إليه ووقت الإنشاء. 15 طلباً حداً أقصى.';
    }

    public function permissions(): array
    {
        return ['quotes.view'];
    }

    protected function run(array $args): array
    {
        return OwnerReports::openQuotes(withDetails: false);
    }
}

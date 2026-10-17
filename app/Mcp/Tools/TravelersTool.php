<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;

#[IsReadOnly]
#[IsIdempotent]
final class TravelersTool extends Tool
{
    public function name(): string
    {
        return 'travelers';
    }

    public function title(): string
    {
        return 'المسافرون بتاريخ';
    }

    public function description(): string
    {
        return 'الفواتير المعتمدة التي يسافر أصحابها في تاريخ محدّد: اسم العميل ورقم الفاتورة وعدد المسافرين والمتبقي بالدينار، بلا أرقام هواتف. الافتراضي اليوم، و30 فاتورة حداً أقصى.';
    }

    public function permissions(): array
    {
        return ['reports.trip_date'];
    }

    public function properties(): array
    {
        return ['date' => ['type' => 'string', 'format' => 'date', 'description' => 'التاريخ YYYY-MM-DD']];
    }

    public function rules(): array
    {
        return ['date' => 'nullable|date_format:Y-m-d'];
    }

    protected function run(array $args): array
    {
        $out = OwnerReports::travelers($args['date'] ?? now()->toDateString(), withPhones: false);

        return $out + ['truncated' => $out['count'] > OwnerReports::TRAVELERS_LIMIT];
    }
}

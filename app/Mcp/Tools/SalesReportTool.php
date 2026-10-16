<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** فيه الربح وأعلى البائعين، فصلاحيته أرباح الموظفين */
#[IsReadOnly]
#[IsIdempotent]
final class SalesReportTool extends Tool
{
    public const MAX_DAYS = 366;

    public function name(): string
    {
        return 'sales_report';
    }

    public function title(): string
    {
        return 'تقرير المبيعات والأرباح';
    }

    public function description(): string
    {
        return 'المبيعات المعتمدة لفترة بتاريخ الفاتورة: العدد والإجمالي والربح بالدينار وأعلى خمسة بائعين. الافتراضي من أول الشهر إلى اليوم، والفترة 366 يوماً حداً أقصى.';
    }

    public function permissions(): array
    {
        return ['reports.employee_profit'];
    }

    public function properties(): array
    {
        return [
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'من تاريخ YYYY-MM-DD'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'إلى تاريخ YYYY-MM-DD'],
        ];
    }

    public function rules(): array
    {
        return ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'];
    }

    protected function run(array $args): array
    {
        $from = Carbon::parse($args['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($args['to'] ?? now()->toDateString())->startOfDay();
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'الفترة غير صالحة: «إلى» بعد «من» وبحد 366 يوماً']);
        }

        return OwnerReports::sales($from->toDateString(), $to->toDateString());
    }
}

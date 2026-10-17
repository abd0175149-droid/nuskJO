<?php

namespace App\Mcp\Tools;

use App\Mcp\Attributes\IsIdempotent;
use App\Mcp\Attributes\IsReadOnly;
use App\Services\Reports\OwnerReports;

/** بالاسم أو الرمز وحدهما، وبلا رقم الهاتف في النتيجة */
#[IsReadOnly]
#[IsIdempotent]
final class FindClientTool extends Tool
{
    public function name(): string
    {
        return 'find_client';
    }

    public function title(): string
    {
        return 'بحث عن عميل';
    }

    public function description(): string
    {
        return 'بحث عن عميل بالاسم أو جزء منه أو بالرمز (مثل CLT-041): الرصيد بالدينار (الموجب مبلغ على العميل) وآخر ثلاث فواتير معتمدة. خمس نتائج حداً أقصى، ولا بحث بالهاتف.';
    }

    public function permissions(): array
    {
        return ['clients.view'];
    }

    public function properties(): array
    {
        return ['query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60, 'description' => 'اسم أو جزء منه، أو رمز العميل']];
    }

    public function required(): array
    {
        return ['query'];
    }

    public function rules(): array
    {
        return ['query' => 'required|string|min:2|max:60'];
    }

    protected function run(array $args): array
    {
        return OwnerReports::findClient($args['query'], withPhones: false);
    }
}

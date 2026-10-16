<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>بياناتك لدى نُسك</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #222; }
        h1 { font-size: 1.4rem; } h2 { font-size: 1.1rem; margin-top: 1.5rem; border-bottom: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; font-size: .85rem; } td, th { border: 1px solid #ddd; padding: .3rem .5rem; text-align: right; vertical-align: top; }
        .muted { color: #777; font-size: .8rem; }
    </style>
</head>
<body>
<h1>بياناتك لدى نُسك</h1>
<p class="muted">أُعدّت في {{ $data['generated_at'] }}</p>

@if ($data['client'])
    <h2>بطاقة العميل</h2>
    <table>@foreach ($data['client'] as $k => $v)<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>
@endif

@foreach (['messages' => 'الرسائل', 'notes' => 'الملاحظات', 'quote_requests' => 'طلبات التسعير', 'invoices' => 'الفواتير', 'receipts' => 'سندات القبض', 'attachments' => 'المرفقات (الوصف؛ نسخة الملف عند الطلب)', 'labels' => 'تصنيفات داخلية', 'pinned_notes' => 'ملاحظة مثبتة'] as $key => $title)
    <h2>{{ $title }} ({{ count(($data[$key] ?? [])) }})</h2>
    @if (count(($data[$key] ?? [])))
        <table>
            @foreach (($data[$key] ?? []) as $row)
                <tr>@foreach ((array) $row as $v)<td>{{ is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</td>@endforeach</tr>
            @endforeach
        </table>
    @endif
@endforeach
</body>
</html>

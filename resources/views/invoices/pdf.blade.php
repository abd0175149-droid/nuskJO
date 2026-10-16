{{--
    فاتورة PDF للعميل (E32.8) — قالب مستقل يُطبع بكروميوم بلا شبكة (HeadlessChromium::printToPdf).
    المدخلات: $d من InvoicePdfData (قائمة سماح بلا تكلفة ولا ربح ولا وكيل ولا ملاحظات)، و$brand (الورقة الرسمية والشعار
    data: والهوامش بالمليمتر والخط). كل قيمة بـ {{ }} المهرَّبة، والأرقام داخل dir="ltr" فلا تنقلب «3+».
    لا CDN ولا سكربت ولا ملف خارجي.
--}}
@php
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $perPage = 14;
    $pages = $items === [] ? [[]] : array_chunk($items, $perPage);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 3, '.', ',');
    $company = is_array($d['company'] ?? null) ? $d['company'] : [];
    $letterhead = $brand['letterhead'] ?? null;
    $top = $letterhead ? (float) ($brand['top_mm'] ?? 62) : 18.0;
    $bottom = $letterhead ? (float) ($brand['bottom_mm'] ?? 45) : 18.0;
    $font = preg_replace('/[<>{}\\\\;]/u', '', (string) ($brand['font'] ?? "'Noto Naskh Arabic', serif"));
    $draft = ($d['status'] ?? null) !== 'approved';
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data: file:">
    <title>فاتورة {{ $d['invoice_number'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: {!! $font !!}; color: #1f1a12; font-size: 11pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .page {
            position: relative;
            width: 210mm;
            height: 297mm;
            padding: {{ $top }}mm 14mm {{ $bottom }}mm;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
            background-repeat: no-repeat;
            background-size: 210mm 297mm;
            background-position: top center;
        }
        .page:last-child { page-break-after: auto; break-after: auto; }
        .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 6mm; border-bottom: 1.2pt solid #b8960b; padding-bottom: 3mm; margin-bottom: 4mm; }
        .brand { display: flex; align-items: center; gap: 4mm; }
        .brand img { max-height: 18mm; max-width: 40mm; }
        .brand .name { font-size: 15pt; font-weight: 700; }
        .brand .name-en { font-size: 9pt; color: #6b5a3a; }
        h1 { margin: 0 0 1mm; font-size: 18pt; color: #2c2417; }
        .meta { font-size: 10pt; line-height: 1.7; }
        .meta b { color: #6b5a3a; font-weight: 600; }
        .draft { margin: 0 0 3mm; padding: 1.5mm 3mm; border: 1pt dashed #b45309; color: #b45309; font-weight: 700; text-align: center; border-radius: 2mm; }
        .client { display: flex; flex-wrap: wrap; gap: 2mm 8mm; padding: 2.5mm 3mm; background: #faf6ec; border-radius: 2mm; margin-bottom: 4mm; font-size: 10.5pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10pt; }
        th { background: #2c2417; color: #e8dcc8; font-weight: 600; padding: 2mm 1.5mm; border: 0.6pt solid #b8960b; }
        td { padding: 1.8mm 1.5mm; border: 0.6pt solid #d9cfba; vertical-align: top; word-wrap: break-word; }
        tbody tr:nth-child(even) td { background: #faf8f5; }
        .num { text-align: center; }
        .amount { text-align: left; white-space: nowrap; }
        .c-n { width: 8mm; } .c-type { width: 18mm; } .c-qty { width: 15mm; } .c-price { width: 26mm; } .c-total { width: 28mm; }
        .totals { margin-top: 4mm; margin-inline-start: auto; width: 80mm; font-size: 11pt; }
        .totals div { display: flex; justify-content: space-between; padding: 1.5mm 3mm; }
        .totals .grand { background: #2c2417; color: #f5e9c8; font-weight: 700; font-size: 13pt; border-radius: 2mm; }
        .foot { position: absolute; bottom: 8mm; inset-inline: 14mm; font-size: 8.5pt; color: #6b5a3a; text-align: center; border-top: 0.6pt solid #d9cfba; padding-top: 2mm; }
        .cont { font-size: 9pt; color: #6b5a3a; margin-bottom: 2mm; }
    </style>
</head>
<body>
@foreach ($pages as $pi => $rows)
    <div class="page" @if ($letterhead) style="background-image: url('{{ $letterhead }}');" @endif>
        @if ($pi === 0)
            <div class="head">
                <div>
                    <h1>فاتورة مبيعات</h1>
                    <div class="meta">
                        <div><b>رقم الفاتورة:</b> <span class="ltr">{{ $d['invoice_number'] ?? '' }}</span></div>
                        @if (!empty($d['invoice_date']))<div><b>التاريخ:</b> <span class="ltr">{{ $d['invoice_date'] }}</span></div>@endif
                        @if (!empty($d['trip_date']))<div><b>تاريخ الرحلة:</b> <span class="ltr">{{ $d['trip_date'] }}</span></div>@endif
                        <div><b>الحالة:</b> {{ $d['status_label'] ?? '' }}</div>
                    </div>
                </div>
                @if (!$letterhead)
                    <div class="brand">
                        @if (!empty($brand['logo']))<img src="{{ $brand['logo'] }}" alt="">@endif
                        <div>
                            @if (!empty($company['name']))<div class="name">{{ $company['name'] }}</div>@endif
                            @if (!empty($company['name_en']))<div class="name-en ltr">{{ $company['name_en'] }}</div>@endif
                        </div>
                    </div>
                @endif
            </div>
            @if ($draft)
                <div class="draft">مسودة — فاتورة غير معتمدة بعد</div>
            @endif
            <div class="client">
                <div><b>العميل:</b> {{ $d['client']['name'] ?? '' }}</div>
                @if (!empty($d['client']['code']))<div><b>الكود:</b> <span class="ltr">{{ $d['client']['code'] }}</span></div>@endif
                @if (!empty($d['offer']['title']))<div><b>العرض:</b> {{ $d['offer']['title'] }}</div>@endif
                @if (!empty($d['offer']['destination']))<div><b>الوجهة:</b> {{ $d['offer']['destination'] }}</div>@endif
            </div>
        @else
            <div class="cont">تابع فاتورة <span class="ltr">{{ $d['invoice_number'] ?? '' }}</span> — صفحة <span class="ltr">{{ $pi + 1 }}</span></div>
        @endif

        <table>
            <thead>
                <tr>
                    <th class="c-n">#</th>
                    <th class="c-type">النوع</th>
                    <th>الوصف</th>
                    <th class="c-qty">الكمية</th>
                    <th class="c-price">سعر الفرد JOD</th>
                    <th class="c-total">الإجمالي JOD</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $ri => $item)
                    <tr>
                        <td class="num"><span class="ltr">{{ $pi * $perPage + $ri + 1 }}</span></td>
                        <td>{{ $item['type_label'] ?? '' }}</td>
                        <td>{{ $item['description'] ?? '' }}</td>
                        <td class="num"><span class="ltr">{{ $item['quantity'] ?? '' }}</span></td>
                        <td class="amount"><span class="ltr">{{ $money($item['sell_price_jod'] ?? null) }}</span></td>
                        <td class="amount"><span class="ltr">{{ $money($item['total_sell_jod'] ?? null) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="num">—</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($loop->last)
            <div class="totals">
                @if (!empty($d['discount_jod']))
                    <div><span>الخصم</span><span class="ltr">-{{ $money($d['discount_jod']) }} JOD</span></div>
                @endif
                <div class="grand"><span>الإجمالي</span><span class="ltr">{{ $money($d['total_jod'] ?? 0) }} JOD</span></div>
            </div>
        @endif

        @if (!$letterhead && ($company['phone'] ?? $company['email'] ?? $company['address'] ?? $company['tax'] ?? null))
            <div class="foot">
                @if (!empty($company['address'])){{ $company['address'] }}@endif
                @if (!empty($company['phone'])) · <span class="ltr">{{ $company['phone'] }}</span>@endif
                @if (!empty($company['email'])) · <span class="ltr">{{ $company['email'] }}</span>@endif
                @if (!empty($company['tax'])) · الرقم الضريبي: <span class="ltr">{{ $company['tax'] }}</span>@endif
            </div>
        @endif
    </div>
@endforeach
</body>
</html>

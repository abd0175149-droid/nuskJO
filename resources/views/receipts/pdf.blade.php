{{--
    سند قبض PDF للعميل (رسالة النظام receipt.approved) — قالب مستقل يُطبع بكروميوم بلا شبكة، بهوية الفاتورة نفسها.
    المدخلات: $r (الرقم والتاريخ والعميل والمبلغ والطريقة والمرجع والملاحظات)، و$company، و$brand. كل قيمة مهرَّبة.
--}}
@php
    $letterhead = $brand['letterhead'] ?? null;
    $top = $letterhead ? (float) ($brand['top_mm'] ?? 62) : 18.0;
    $bottom = $letterhead ? (float) ($brand['bottom_mm'] ?? 45) : 18.0;
    $font = preg_replace('/[<>{};]/u', '', (string) ($brand['font'] ?? "'Noto Naskh Arabic', serif"));
    $methods = ['cash' => 'نقداً', 'bank' => 'تحويل بنكي', 'transfer' => 'تحويل بنكي', 'check' => 'شيك', 'cheque' => 'شيك', 'card' => 'بطاقة', 'cliq' => 'كليك'];
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data: file:">
    <title>سند قبض {{ $r['number'] }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: {!! $font !!}; color: #1f1a12; font-size: 12pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .page { position: relative; width: 210mm; height: 297mm; padding: {{ $top }}mm 16mm {{ $bottom }}mm; background-size: 210mm 297mm; background-repeat: no-repeat; }
        .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
        .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 1.2pt solid #b8960b; padding-bottom: 3mm; margin-bottom: 8mm; }
        .head img { max-height: 18mm; max-width: 40mm; }
        h1 { margin: 0; font-size: 20pt; color: #2c2417; }
        .box { border: 0.8pt solid #d9cfba; border-radius: 3mm; padding: 6mm 7mm; line-height: 2.1; }
        .row { display: flex; justify-content: space-between; border-bottom: 0.5pt dashed #e4dccb; }
        .row:last-child { border-bottom: 0; }
        .row b { color: #6b5a3a; font-weight: 600; }
        .amount { margin: 8mm 0; text-align: center; font-size: 22pt; font-weight: 700; color: #2c2417; background: #faf6ec; border-radius: 3mm; padding: 5mm; }
        .foot { margin-top: 12mm; font-size: 9.5pt; color: #6b5a3a; text-align: center; }
    </style>
</head>
<body>
<div class="page" @if ($letterhead) style="background-image: url('{{ $letterhead }}')" @endif>
    @unless ($letterhead)
        <div class="head">
            <div>
                @if (!empty($brand['logo']))<img src="{{ $brand['logo'] }}" alt="">@endif
                <div style="font-weight:700;font-size:14pt">{{ $company['name'] ?? '' }}</div>
            </div>
            <h1>سند قبض</h1>
        </div>
    @else
        <h1 style="text-align:center;margin-bottom:8mm">سند قبض</h1>
    @endunless

    <div class="box">
        <div class="row"><b>رقم السند</b><span class="ltr">{{ $r['number'] }}</span></div>
        <div class="row"><b>التاريخ</b><span class="ltr">{{ $r['date'] }}</span></div>
        <div class="row"><b>استلمنا من</b><span>{{ $r['client'] }}@if ($r['client_code']) <span class="ltr">({{ $r['client_code'] }})</span>@endif</span></div>
        <div class="row"><b>طريقة الدفع</b><span>{{ $methods[$r['method']] ?? $r['method'] }}</span></div>
        @if ($r['reference'])<div class="row"><b>المرجع</b><span class="ltr">{{ $r['reference'] }}</span></div>@endif
        @if ($r['notes'])<div class="row"><b>ملاحظات</b><span>{{ $r['notes'] }}</span></div>@endif
    </div>

    <div class="amount">المبلغ: <span class="ltr">{{ $r['amount'] }}</span> دينار أردني</div>

    <div class="foot">هذا السند صادر إلكترونياً من {{ $company['name'] ?? '' }}، ولا يحتاج إلى توقيع.</div>
</div>
</body>
</html>

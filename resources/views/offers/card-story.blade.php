{{--
    بطاقة الحالة 9:16 (E38) — 1080×1920 تُرسم بكروميوم بلا شبكة للنشر اليدوي في حالة واتساب. لا نشر آلي.
    القالب لا يستقبل نموذج العرض: $card من OfferCardService::storyData() وحدها (القائمة البيضاء نفسها التي يقرؤها البوت):
      title, category, nights, airline, trip_date, valid_to, includes (4), price_from, company{name, phone},
      logoUri, heroUri, ref (N-{id}), short_link
    لا جدول فنادق: لا يُقرأ على شاشة هاتف في الحالة. والأرقام و«3+» باتجاه ltr تجنباً لانقلاب bidi.
--}}
@php
    $card = is_array($card ?? null) ? $card : [];
    $has = fn ($v) => $v !== null && trim((string) $v) !== '';
    $font = $fontFamily ?? "'Noto Naskh Arabic', 'Noto Sans Arabic', sans-serif";
    $price = is_numeric($card['price_from'] ?? null) ? rtrim(rtrim(number_format((float) $card['price_from'], 2, '.', ','), '0'), '.') : null;
    $includes = array_values(array_filter((array) ($card['includes'] ?? []), $has));
    $company = is_array($card['company'] ?? null) ? $card['company'] : [];
    $facts = array_values(array_filter([
        $has($card['nights'] ?? null) ? ['الليالي', $card['nights']] : null,
        $has($card['airline'] ?? null) ? ['الطيران', $card['airline']] : null,
        $has($card['trip_date'] ?? null) ? ['تاريخ الرحلة', $card['trip_date']] : null,
    ]));
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'">
    <title>{{ $has($card['title'] ?? null) ? $card['title'] : 'بطاقة الحالة' }}</title>
    <style>
        :root { --font: {!! $font !!}; --gold: #b8912f; --ink: #1d1a14; }
        * { box-sizing: border-box; }
        body { margin: 0; width: 1080px; height: 1920px; overflow: hidden; font-family: var(--font); background: #faf6ee; color: var(--ink); }
        .story { width: 100%; height: 100%; display: flex; flex-direction: column; }
        .hero { flex: 0 0 640px; position: relative; background: linear-gradient(160deg, #efe2c4, #d9c08a); background-size: cover; background-position: center; }
        .hero__logo { position: absolute; top: 56px; right: 56px; max-height: 150px; max-width: 360px; background: rgba(255,255,255,.88); border-radius: 24px; padding: 18px 26px; }
        .hero__cat { position: absolute; bottom: 40px; right: 56px; background: var(--gold); color: #fff; font-size: 40px; font-weight: 700; padding: 10px 34px; border-radius: 999px; }
        .body { flex: 1 1 auto; padding: 54px 64px 0; display: flex; flex-direction: column; gap: 34px; }
        h1 { margin: 0; font-size: 84px; line-height: 1.2; font-weight: 800; color: var(--ink); }
        .price { background: #fff; border: 4px solid var(--gold); border-radius: 36px; padding: 28px 40px; }
        .price__main { font-size: 62px; font-weight: 800; color: var(--gold); }
        .price__main bdi { direction: ltr; unicode-bidi: isolate; }
        .price__note { font-size: 30px; color: #6b5a33; margin-top: 8px; }
        .facts { display: flex; flex-wrap: wrap; gap: 18px; }
        .fact { background: #fff; border-radius: 24px; padding: 18px 28px; font-size: 34px; }
        .fact b { color: #6b5a33; font-weight: 600; margin-left: 10px; }
        .fact bdi { direction: ltr; unicode-bidi: isolate; }
        ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 14px; }
        li { font-size: 38px; line-height: 1.35; }
        li::before { content: '✓'; color: var(--gold); font-weight: 800; margin-left: 14px; }
        .valid { font-size: 30px; color: #6b5a33; }
        .valid bdi { direction: ltr; unicode-bidi: isolate; }
        .cta { margin-top: auto; background: #1d1a14; color: #f3e3b5; padding: 46px 64px 64px; text-align: center; }
        .cta__line { font-size: 42px; line-height: 1.45; }
        .cta__line bdi { direction: ltr; unicode-bidi: isolate; font-weight: 800; color: #fff; }
        .cta__ref { display: inline-block; margin-top: 18px; font-size: 54px; font-weight: 800; color: #fff; border: 3px dashed #e2c893; border-radius: 22px; padding: 6px 30px; direction: ltr; }
        .cta__link { margin-top: 18px; font-size: 32px; direction: ltr; color: #e2c893; }
        .cta__company { margin-top: 18px; font-size: 30px; opacity: .8; }
    </style>
</head>
<body>
<div class="story">
    <div class="hero" @if ($has($card['heroUri'] ?? null)) style="background-image: url('{{ $card['heroUri'] }}');" @endif>
        @if ($has($card['logoUri'] ?? null))
            <img class="hero__logo" src="{{ $card['logoUri'] }}" alt="">
        @endif
        @if ($has($card['category'] ?? null))
            <span class="hero__cat">{{ $card['category'] }}</span>
        @endif
    </div>

    <div class="body">
        <h1>{{ $card['title'] ?? '' }}</h1>

        @if ($price !== null)
            <div class="price">
                <div class="price__main">يبدأ من <bdi>{{ $price }}</bdi> د.أ للفرد</div>
                <div class="price__note">السعر غير نهائي ويؤكده الموظف</div>
            </div>
        @endif

        @if (count($facts))
            <div class="facts">
                @foreach ($facts as [$label, $value])
                    <span class="fact"><b>{{ $label }}</b><bdi>{{ $value }}</bdi></span>
                @endforeach
            </div>
        @endif

        @if (count($includes))
            <ul>
                @foreach ($includes as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        @if ($has($card['valid_to'] ?? null))
            <div class="valid">العرض صالح حتى <bdi>{{ $card['valid_to'] }}</bdi></div>
        @endif
    </div>

    <div class="cta">
        <div class="cta__line">
            راسلنا على واتساب
            @if ($has($company['phone'] ?? null)) <bdi>{{ $company['phone'] }}</bdi> @endif
            واكتب:
        </div>
        <div class="cta__ref">{{ $card['ref'] ?? '' }}</div>
        @if ($has($card['short_link'] ?? null))
            <div class="cta__link">{{ $card['short_link'] }}</div>
        @endif
        @if ($has($company['name'] ?? null))
            <div class="cta__company">{{ $company['name'] }}</div>
        @endif
    </div>
</div>
</body>
</html>

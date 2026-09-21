{{--
    بطاقة العرض (بوستر تسويقي) — تُرسم بواسطة Chromium بلا شبكة ثم تُحوّل إلى PNG.
    المقاس ثابت: 1240×1754 بكسل (A4 بدقة 150dpi).
    لا Tailwind، ولا خطوط ويب، ولا أي طلب خارجي — كل شيء داخل هذا الملف.

    المتغيّرات القادمة من المستدعي:
      $offer, $hotels, $roomTypes, $company, $logoUri, $heroUri, $minPrice, $fontFamily
--}}
@php
    /* ------------------------------------------------------------------
     | تهيئة آمنة: كل قيمة قد تكون null أو فارغة — لا نطبع «null» أبداً
     * ------------------------------------------------------------------ */
    $hotels    = collect($hotels ?? []);
    $roomTypes = is_array($roomTypes ?? null) ? $roomTypes : [];
    $company   = is_array($company ?? null) ? $company : [];
    $minPrice  = $minPrice ?? null;

    // هل النص ذو قيمة فعلية؟
    $has = fn ($v) => $v !== null && trim((string) $v) !== '';

    // قراءة سعر خام من مصفوفة prices مع رفض الأصفار والقيم غير الرقمية
    $rawPrice = function ($hotel, string $key) {
        $prices = is_array($hotel->prices ?? null) ? $hotel->prices : [];
        $v = $prices[$key] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $v = (float) $v;

        return $v > 0 ? $v : null;
    };

    // تنسيق السعر: بلا فواصل عشرية إن كان عدداً صحيحاً، وإلا حتى 3 منازل
    $fmtPrice = function (float $v): string {
        if (floor($v) === $v) {
            return number_format($v, 0, '.', '');
        }

        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    };

    // هل هذا السعر هو الأقل في البطاقة؟ (مقارنة بهامش صغير لتفادي أخطاء العشرية)
    $isMin = fn (float $v) => $minPrice !== null && is_numeric($minPrice) && abs($v - (float) $minPrice) < 0.0005;

    /* الأعمدة الوصفية: تُحذف كاملةً (العنوان وكل الخلايا) إن لم يملك أي فندق قيمة لها */
    $showLocation = $hotels->contains(fn ($h) => $has($h->location));
    $showMeals    = $hotels->contains(fn ($h) => $has($h->meals));
    $showDistance = $hotels->contains(fn ($h) => $has($h->distance_haram));

    /* أعمدة أنواع الغرف: نُبقي فقط ما له سعر في فندق واحد على الأقل، وبترتيب $roomTypes */
    $shownRooms = [];
    foreach ($roomTypes as $key => $label) {
        if (!$has($label)) {
            continue;
        }
        if ($hotels->contains(fn ($h) => $rawPrice($h, $key) !== null)) {
            $shownRooms[$key] = $label;
        }
    }

    /* سطر تحت العنوان: «4 ليالٍ · طيران فلاي ناس» — نجمع الموجود فقط */
    $metaParts = [];
    if (is_numeric($offer->nights ?? null) && (int) $offer->nights > 0) {
        $n = (int) $offer->nights;
        $metaParts[] = $n === 1 ? 'ليلة واحدة' : ($n === 2 ? 'ليلتان' : $n . ' ليالٍ');
    }
    if ($has($offer->airline ?? null)) {
        $air = trim((string) $offer->airline);
        // نضيف كلمة «طيران» فقط إن لم يكن اسم الناقل يحملها أصلاً
        $metaParts[] = (mb_strpos($air, 'طيران') !== false || mb_strpos($air, 'خطوط') !== false)
            ? $air
            : 'طيران ' . $air;
    }
    $metaLine = implode(' · ', $metaParts);

    /* تاريخ نهاية الصلاحية */
    $validTo = null;
    if (!empty($offer->valid_to)) {
        $validTo = $offer->valid_to instanceof \DateTimeInterface
            ? $offer->valid_to->format('Y-m-d')
            : trim((string) $offer->valid_to);
    }

    /* الهاتف والبريد: قد يحتويان عدّة قيم مفصولة بفواصل أو شرطة مائلة أو أسطر */
    $splitList = function ($value): array {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        return collect(preg_split('/[,،;\/\r\n|]+/u', (string) $value))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->values()
            ->all();
    };
    $phones = $splitList($company['phone'] ?? null);
    $emails = $splitList($company['email'] ?? null);
    $address = $has($company['address'] ?? null) ? trim((string) $company['address']) : null;

    /* قوائم يشمل / لا يشمل */
    $includes = collect(is_array($offer->includes ?? null) ? $offer->includes : [])
        ->map(fn ($l) => trim((string) $l))->filter()->values();
    $excludes = collect(is_array($offer->excludes ?? null) ? $offer->excludes : [])
        ->map(fn ($l) => trim((string) $l))->filter()->values();

    /* عائلة الخط: تُطبع داخل CSS، لذا نُنقّيها من المحارف الخطرة بدل تهريبها بـ e() */
    $font = $has($fontFamily ?? null)
        ? preg_replace('/[<>{}\\\\]/u', '', (string) $fontFamily)
        : "'Noto Naskh Arabic', 'Amiri', serif";

    $cols = 2 /* الاسم + التصنيف */
        + ($showLocation ? 1 : 0) + ($showMeals ? 1 : 0) + ($showDistance ? 1 : 0)
        + count($shownRooms);
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>{{ $has($offer->title ?? null) ? $offer->title : 'بطاقة العرض' }}</title>
    <style>
        /* ===== الأساس ===== */
        :root { --font: {!! $font !!}; }
        * { box-sizing: border-box; }
        html { font-size: 16px; }
        body {
            margin: 0;
            width: 1240px;
            height: 1754px;
            overflow: hidden;
            font-family: var(--font);
            background: #ffffff;
            color: #1a1a1a;
            -webkit-font-smoothing: antialiased;
        }

        /* إطار المطبوعة: حدّ داكن ثم فجوة بيضاء داخلية */
        .poster {
            width: 100%;
            height: 100%;
            border: 10px solid #2b2b2b;   /* الإطار الخارجي */
            padding: 6px;                  /* الفجوة البيضاء */
            background: #ffffff;
            display: flex;
            flex-direction: column;
        }

        /* ===== 1) شريط الترويسة ===== */
        .hero {
            position: relative;
            flex: 0 0 390px;
            height: 390px;
            overflow: hidden;
            background-color: #e6d5b8;
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
        }
        .hero--plain { background-image: linear-gradient(135deg, #f5efe3, #e6d5b8); }

        .hero__logo {
            position: absolute;
            top: 40px;
            right: 40px;
            width: 150px;
        }
        .hero__logo img { display: block; width: 150px; height: auto; }
        /* بديل الشعار عند غيابه: اسم الشركة نصّاً */
        .hero__brand {
            position: absolute;
            top: 40px;
            right: 40px;
            max-width: 330px;
            text-align: right;
            color: #1f1a10;
            font-weight: 700;
            font-size: 30px;
            line-height: 1.3;
        }
        .hero__brand small { display: block; font-size: 18px; font-weight: 400; opacity: .8; letter-spacing: .5px; }

        .hero__text {
            position: absolute;
            top: 50%;
            left: 48px;
            transform: translateY(-50%);
            max-width: 640px;
            text-align: left;   /* الكتلة مُلاصِقة للحافة اليسرى كما في المرجع */
        }
        .hero__title {
            margin: 0;
            font-size: 62px;
            font-weight: 700;
            line-height: 1.22;
            color: #C9A227;
        }
        .hero__meta {
            margin: 14px 0 0;
            font-size: 26px;
            font-weight: 400;
            line-height: 1.4;
            color: #fdf7ea;
        }
        /* ظلّ النص يُستخدم فقط فوق الصورة */
        .hero--photo .hero__title { text-shadow: 0 3px 10px rgba(0, 0, 0, .65); }
        .hero--photo .hero__meta  { text-shadow: 0 2px 8px rgba(0, 0, 0, .7); }
        /* بلا صورة: خلفية كريمية فاتحة تحتاج نصاً داكناً للسطر الثانوي */
        .hero--plain .hero__meta { color: #4a3d22; }

        /* ===== 2+3) المنطقة القابلة للتقليص ===== */
        #fit { flex: 1 1 auto; overflow: hidden; min-height: 0; }
        #fit-inner { font-size: 100%; padding: 1.5em 2.25em 0.35em; }  /* 36px جانبياً */

        /* جدول الأسعار */
        table.prices {
            width: 100%;
            border-collapse: collapse;
            font-size: 1.375em;   /* 22px */
            line-height: 1.35;
        }
        table.prices th,
        table.prices td {
            border: 1px solid #d8d8d8;
            padding: 0.73em 0.5em;   /* ~16px عمودياً */
            text-align: center;
            vertical-align: middle;
        }
        table.prices thead th {
            background: #e2c893;
            color: #1a1a1a;
            font-weight: 700;
            padding: 0.82em 0.5em;   /* ~18px عمودياً */
            white-space: nowrap;
        }
        table.prices tbody tr:nth-child(odd)  { background: #ffffff; }
        table.prices tbody tr:nth-child(even) { background: #f4f4f4; }
        td.hotel {
            text-align: right;
            padding-right: 0.8em;
            font-weight: 700;
            line-height: 1.3;
        }
        td.price {
            direction: ltr;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum" 1;
            white-space: nowrap;
        }
        /* أقل سعر في البطاقة — أحمر وعريض */
        td.price--min { color: #c00000; font-weight: 700; }
        /* «3+» تُقلب إلى «+3» في سياق rtl ما لم نثبّت الاتجاه */
        td.cell--rating { direction: ltr; }
        .muted { color: #9a9a9a; }

        /* الأقسام النصّية */
        .sections { margin-top: 1.2em; font-size: 1.625em; line-height: 1.7; text-align: right; }
        .sections .intro { margin: 0 0 0.75em; color: #333333; line-height: 1.6; }
        .sec { margin: 0 0 0.75em; }
        .sec__title {
            margin: 0 0 0.25em;
            color: #c00000;
            font-weight: 700;
            font-size: 1.154em;   /* ~30px */
        }
        .sec__line { margin: 0; }
        .sec--notes .sec__body { color: #b8912f; }
        .validity { margin: 0.6em 0 0; font-size: 0.85em; color: #4a4a4a; font-weight: 700; }

        /* ===== 4) شريط التذييل ===== */
        .bar {
            flex: 0 0 130px;
            height: 130px;
            background: #2b2b2b;
            color: #e2c893;
            display: flex;
            align-items: center;
            justify-content: space-around;
        }
        .bar__group {
            flex: 1 1 0;
            padding: 0 26px;
            text-align: center;
            /* فاصل ذهبي رقيق بين المجموعات (في RTL الشقيق السابق يقع على اليمين) */
        }
        .bar__group + .bar__group { border-right: 1px solid rgba(226, 200, 147, .45); }
        .bar__label { display: block; font-size: 17px; opacity: .72; margin-bottom: 8px; letter-spacing: .5px; }
        .pills { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .pill {
            border: 1px solid #e2c893;
            border-radius: 999px;
            padding: 6px 18px;
            font-size: 22px;
            line-height: 1.25;
            direction: ltr;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .bar__text { font-size: 22px; line-height: 1.4; }
        .bar__email { font-size: 20px; line-height: 1.5; direction: ltr; }
    </style>
</head>
<body>
<div class="poster">

    {{-- ===== 1) الترويسة: صورة + شعار يميناً + العنوان يساراً ===== --}}
    <header class="hero {{ $has($heroUri ?? null) ? 'hero--photo' : 'hero--plain' }}"
            @if ($has($heroUri ?? null)) style="background-image: url('{{ $heroUri }}');" @endif>

        @if ($has($logoUri ?? null))
            <div class="hero__logo"><img src="{{ $logoUri }}" alt=""></div>
        @elseif ($has($company['name'] ?? null))
            {{-- لا يوجد شعار: نكتب اسم الشركة مكانه --}}
            <div class="hero__brand">
                {{ $company['name'] }}
                @if ($has($company['name_en'] ?? null))
                    <small>{{ $company['name_en'] }}</small>
                @endif
            </div>
        @endif

        <div class="hero__text">
            @if ($has($offer->title ?? null))
                <h1 class="hero__title">{{ $offer->title }}</h1>
            @endif
            @if ($metaLine !== '')
                <p class="hero__meta">{{ $metaLine }}</p>
            @endif
        </div>
    </header>

    {{-- ===== 2+3) الجدول والأقسام داخل صندوق يتقلّص ليَسَع الصفحة ===== --}}
    <div id="fit">
        <div id="fit-inner">

            @if ($hotels->isNotEmpty() && $cols > 0)
                <table class="prices">
                    <thead>
                        <tr>
                            <th>اسم الفندق</th>
                            @if ($showLocation)<th>الموقع</th>@endif
                            <th>التصنيف</th>
                            @if ($showMeals)<th>الوجبات</th>@endif
                            @if ($showDistance)<th>المسافة عن الحرم</th>@endif
                            @foreach ($shownRooms as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hotels as $hotel)
                            <tr>
                                <td class="hotel">{{ $has($hotel->name) ? $hotel->name : '—' }}</td>

                                @if ($showLocation)
                                    <td>{{ $has($hotel->location) ? $hotel->location : '' }}</td>
                                @endif

                                @php $rating = method_exists($hotel, 'ratingLabel') ? $hotel->ratingLabel() : null; @endphp
                                {{-- ltr وإلا قلبت خوارزمية الاتجاه «3+» إلى «+3» --}}
                                <td class="cell--rating">{{ $has($rating) ? $rating : '' }}</td>

                                @if ($showMeals)
                                    <td>{{ $has($hotel->meals) ? $hotel->meals : '' }}</td>
                                @endif

                                @if ($showDistance)
                                    <td>{{ $has($hotel->distance_haram) ? $hotel->distance_haram : '' }}</td>
                                @endif

                                @foreach ($shownRooms as $key => $label)
                                    @php $p = $rawPrice($hotel, $key); @endphp
                                    @if ($p === null)
                                        <td class="price muted">—</td>
                                    @else
                                        <td class="price {{ $isMin($p) ? 'price--min' : '' }}">{{ $fmtPrice($p) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="sections">

                {{-- وصف العرض للعميل (إن وُجد) كتمهيد قبل الأقسام --}}
                @if ($has($offer->description_client ?? null))
                    <p class="intro">{!! nl2br(e(trim($offer->description_client))) !!}</p>
                @endif

                {{-- السعر يشمل --}}
                @if ($includes->isNotEmpty())
                    <div class="sec">
                        <p class="sec__title">-السعر يشمل :</p>
                        <div class="sec__body">
                            @foreach ($includes as $line)
                                <p class="sec__line">- {{ $line }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- السعر لا يشمل --}}
                @if ($excludes->isNotEmpty())
                    <div class="sec">
                        <p class="sec__title">-السعر لا يشمل:</p>
                        <div class="sec__body">
                            @foreach ($excludes as $line)
                                <p class="sec__line">- {{ $line }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ملاحظات عامة --}}
                @if ($has($offer->notes_public ?? null))
                    <div class="sec sec--notes">
                        <p class="sec__title">-ملاحظات :</p>
                        <div class="sec__body">{!! nl2br(e(trim($offer->notes_public))) !!}</div>
                    </div>
                @endif

                {{-- صلاحية العرض --}}
                @if ($has($validTo))
                    <p class="validity">العرض صالح حتى {{ $validTo }}</p>
                @endif
            </div>

        </div>
    </div>

    {{-- ===== 4) التذييل: هواتف · عنوان · بريد ===== --}}
    @if (count($phones) || $has($address) || count($emails))
        <footer class="bar">
            @if (count($phones))
                <div class="bar__group">
                    <span class="bar__label">للتواصل</span>
                    <div class="pills">
                        @foreach ($phones as $phone)
                            <span class="pill">{{ $phone }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($has($address))
                <div class="bar__group">
                    <span class="bar__label">الموقع</span>
                    <div class="bar__text">{{ $address }}</div>
                </div>
            @endif

            @if (count($emails))
                <div class="bar__group">
                    <span class="bar__label">البريد الإلكتروني</span>
                    @foreach ($emails as $email)
                        <div class="bar__email">{{ $email }}</div>
                    @endforeach
                </div>
            @endif
        </footer>
    @endif

</div>

{{-- تقليص الجدول والأقسام حتى تسَع الارتفاع الثابت للصفحة --}}
<script>
(function () {
  var box = document.getElementById('fit');
  var inner = document.getElementById('fit-inner');
  var size = 100;
  while (inner.scrollHeight > box.clientHeight && size > 55) {
    size -= 2;
    inner.style.fontSize = size + '%';
  }
  document.documentElement.setAttribute('data-ready', '1');
})();
</script>
</body>
</html>

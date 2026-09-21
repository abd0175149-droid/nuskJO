{{--
    بطاقة العرض (بوستر تسويقي) — تُرسم بواسطة Chromium بلا شبكة ثم تُحوّل إلى PNG.
    المقاس ثابت: 1240×1754 بكسل (A4 بدقة 150dpi).
    لا Tailwind، ولا خطوط ويب، ولا أي طلب خارجي — كل شيء داخل هذا الملف.

    المتغيّرات القادمة من المستدعي:
      $offer        العرض (notes_public, requirements, visa_*, price_per_person, nights, airline)
      $options      خيارات مسعّرة (OfferOption) — كل خيار: prices + includes_note + stays
      $isVisa       تأشيرة؟ حينها لا جدول أسعار بل كتلة تفاصيل
      $showDistance هل نُظهر عمود «المسافة عن الحرم» (عمرة/حج فقط)
      $cities       مدن العرض مرتّبة (['مكة','المدينة']) أو [] لعرض بمدينة واحدة
      $roomTypes, $company, $logoUri, $heroUri, $minPrice, $fontFamily
--}}
@php
    /* ------------------------------------------------------------------
     | تهيئة آمنة: كل قيمة قد تكون null أو فارغة — لا نطبع «null» أبداً
     * ------------------------------------------------------------------ */
    $options   = collect($options ?? []);
    $roomTypes = is_array($roomTypes ?? null) ? $roomTypes : [];
    $company   = is_array($company ?? null) ? $company : [];
    $minPrice  = $minPrice ?? null;
    $isVisa    = (bool) ($isVisa ?? false);

    // هل النص ذو قيمة فعلية؟
    $has = fn ($v) => $v !== null && trim((string) $v) !== '';

    // مدن العرض: نُنقّيها من الفراغات والقيم الفارغة (فارغة = عرض بمدينة واحدة)
    $cities = collect(is_array($cities ?? null) ? $cities : [])
        ->map(fn ($c) => trim((string) $c))->filter()->values()->all();
    // $cities غير فارغة ← جدول مُجمّع بالمدن (ترويسة من صفّين)
    $multiCity = count($cities) > 0;

    // قراءة سعر خام من مصفوفة prices الخاصّة بالخيار، مع رفض الأصفار وغير الرقمي
    $rawPrice = function ($option, string $key) {
        $prices = is_array($option->prices ?? null) ? $option->prices : [];
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

    /* كل الإقامات في البطاقة — عليها نحسم أي عمود وصفي يستحق البقاء */
    $allStays = $options->flatMap(fn ($o) => collect($o->stays ?? []))->filter()->values();

    /* الأعمدة الوصفية: تُحذف كاملةً (العنوان وكل الخلايا) إن لم تملك أي إقامة قيمة لها.
       «المسافة عن الحرم» تحتاج أيضاً إذن المستدعي ($showDistance) لأنها تخصّ العمرة والحج. */
    $useLocation = $allStays->contains(fn ($s) => $has($s->location));
    $useMeals    = $allStays->contains(fn ($s) => $has($s->meals));
    $useRating   = $allStays->contains(fn ($s) => $has(method_exists($s, 'ratingLabel') ? $s->ratingLabel() : null));
    $useDistance = (bool) ($showDistance ?? false)
        && $allStays->contains(fn ($s) => $has($s->distance_haram));

    /* ترتيب الأعمدة الوصفية — يختلف بين الجدول المفرد والجدول المُجمّع بالمدن */
    $descCols = [];
    if ($multiCity) {
        $descCols['name'] = 'الفندق';
        if ($useRating)   { $descCols['rating']   = 'التصنيف'; }
        if ($useMeals)    { $descCols['meals']    = 'الوجبات'; }
        if ($useDistance) { $descCols['distance'] = 'المسافة عن الحرم'; }
        if ($useLocation) { $descCols['location'] = 'الموقع'; }
    } else {
        $descCols['name'] = 'اسم الفندق';
        if ($useLocation) { $descCols['location'] = 'الموقع'; }
        if ($useRating)   { $descCols['rating']   = 'التصنيف'; }
        if ($useMeals)    { $descCols['meals']    = 'الوجبات'; }
        if ($useDistance) { $descCols['distance'] = 'المسافة عن الحرم'; }
    }

    /* أعمدة أنواع الغرف: نُبقي فقط ما له سعر في خيار واحد على الأقل، وبترتيب $roomTypes */
    $shownRooms = [];
    foreach ($roomTypes as $key => $label) {
        if (!$has($label)) {
            continue;
        }
        if ($options->contains(fn ($o) => $rawPrice($o, $key) !== null)) {
            $shownRooms[$key] = $label;
        }
    }

    /* خلية إقامة واحدة: [الصنف، النص] — تُستعمل في الجدولين معاً */
    $stayCell = function ($stay, string $key) use ($has) {
        $class = match ($key) {
            'name'   => 'hotel',
            'rating' => 'cell--rating',   // «3+» تُقلب إلى «+3» في سياق rtl
            default  => '',
        };

        $value = null;
        if ($stay) {
            $value = match ($key) {
                'name'     => $stay->name,
                'location' => $stay->location,
                'rating'   => method_exists($stay, 'ratingLabel') ? $stay->ratingLabel() : null,
                'meals'    => $stay->meals,
                'distance' => $stay->distance_haram,
                default    => null,
            };
        }

        return ['class' => $class, 'text' => $has($value) ? trim((string) $value) : ''];
    };

    /* توزيع إقامات الخيار على أعمدة المدن: بالمدينة أولاً، ثم بالترتيب لمن لا مدينة له */
    $staysByCity = function ($option) use ($cities, $has) {
        $stays = collect($option->stays ?? [])->filter()->values()->all();
        $slots = array_fill(0, count($cities), null);
        $taken = [];

        foreach ($cities as $i => $city) {
            foreach ($stays as $k => $stay) {
                if (isset($taken[$k]) || !$has($stay->city)) {
                    continue;
                }
                if (trim((string) $stay->city) === $city) {
                    $slots[$i] = $stay;
                    $taken[$k] = true;
                    break;
                }
            }
        }

        // ما تبقّى (إقامات بلا مدينة أو بمدينة غير معروفة) يملأ الفراغات بالترتيب
        $rest = [];
        foreach ($stays as $k => $stay) {
            if (!isset($taken[$k])) {
                $rest[] = $stay;
            }
        }
        foreach ($slots as $i => $slot) {
            if ($slot === null && $rest) {
                $slots[$i] = array_shift($rest);
            }
        }

        return $slots;
    };

    /* هل نرسم جدولاً؟ التأشيرة بلا جدول، وكذلك عرض بلا خيارات أو بلا أعمدة */
    $showTable = !$isVisa && $options->isNotEmpty() && (count($descCols) + count($shownRooms)) > 0;

    /* سعر التأشيرة للفرد */
    $visaPrice = null;
    if ($isVisa && is_numeric($offer->price_per_person ?? null) && (float) $offer->price_per_person > 0) {
        $visaPrice = (float) $offer->price_per_person;
    }

    /* حقائق التأشيرة: لا نطبع عنواناً بلا قيمة */
    $visaFacts = [];
    if ($isVisa) {
        foreach ([
            'صلاحية التأشيرة' => $offer->visa_validity ?? null,
            'عدد مرات الدخول' => $offer->visa_entries ?? null,
            'مدة الإصدار'     => $offer->visa_processing ?? null,
        ] as $label => $value) {
            if ($has($value)) {
                $visaFacts[$label] = trim((string) $value);
            }
        }
    }

    /* سطر تحت العنوان: «4 ليالٍ · طيران فلاي ناس» — لا يُطبع للتأشيرة إطلاقاً */
    $metaParts = [];
    if (!$isVisa) {
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

    /* ===== وضع «الورقة الرسمية الكاملة» =====
       حين تُرفع ورقة الشركة كاملةً، تصير خلفيةَ الصفحة ويُخفى شريطُ الترويسة
       وشريطُ التذييل لأنّ الورقة تحملهما أصلاً — فلا تتكرّر الهوية ولا تتعارض.
       نِسَب الترويسة والتذييل تُضبط من الإعدادات لأنّ كل ورقة تختلف. */
    $letterhead = $has($letterheadUri ?? null) ? $letterheadUri : null;
    $lhTop      = is_numeric($lhTop ?? null) ? (float) $lhTop : 21.0;
    $lhBottom   = is_numeric($lhBottom ?? null) ? (float) $lhBottom : 15.0;
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

        /* ===== وضع الورقة الرسمية: الورقة خلفية الصفحة كاملةً ===== */
        .poster--letterhead {
            border: 0;
            padding: 0;
            background-repeat: no-repeat;
            background-size: 100% 100%;   /* الورقة بمقاس A4 نفسه فلا تُقتطع */
            background-position: center;
        }
        /* عنوان العرض داخل مساحة الورقة البيضاء، لا فوق ترويستها */
        .lh-title {
            flex: 0 0 auto;
            padding: 0 3.2em 0.4em;
            text-align: center;
        }
        .lh-title h1 {
            margin: 0;
            font-size: 46px;
            font-weight: 700;
            color: #b8912f;
            line-height: 1.25;
        }
        .lh-title p {
            margin: 6px 0 0;
            font-size: 24px;
            color: #4a3d22;
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
        /* الترويسة المُجمّعة بالمدن: صفّ المدن فوق، وصفّ الأعمدة الفرعية تحته */
        table.prices thead th.group { font-size: 1.05em; letter-spacing: .5px; }
        /* العناوين الفرعية تُلفّ: مع مدينتين يبلغ الجدول 14 عموداً،
           و nowrap عليها كان يدفع الجدول خارج عرض الصفحة فيُقتطع */
        table.prices thead th.sub {
            font-size: 0.86em;
            font-weight: 700;
            background: #ecd9af;
            white-space: normal;
            padding-left: 0.3em;
            padding-right: 0.3em;
        }
        /* وضع ضيّق يُفعّله السكربت حين يبقى الجدول أعرض من الصفحة */
        table.prices--tight th,
        table.prices--tight td { padding: 0.5em 0.25em; }
        table.prices--tight thead th { white-space: normal; }
        table.prices--tight td.hotel { padding-right: 0.4em; }
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
        /* سطر تنبيه صغير تحت الجدول المُجمّع */
        .table-note { margin: 0.5em 0 0; font-size: 1.125em; color: #4a4a4a; text-align: right; }

        /* ===== كتلة التأشيرة (بديلة عن الجدول) ===== */
        .visa { text-align: right; }
        .visa__price {
            margin: 0.2em 0 0.7em;
            text-align: center;
            font-size: 2em;            /* ~32px */
            font-weight: 700;
            color: #b8912f;
            line-height: 1.3;
        }
        .visa__price span { direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; }
        /* الحقائق تتوزّع بالتساوي مهما كان عددها — فلا يبقى صندوق يتيم في سطر */
        .visa__facts {
            display: grid;
            gap: 0.6em;
            font-size: 1.5em;          /* ~24px */
        }
        .visa__facts--1 { grid-template-columns: 1fr; }
        .visa__facts--2 { grid-template-columns: 1fr 1fr; }
        .visa__facts--3 { grid-template-columns: 1fr 1fr 1fr; }
        .visa__fact {
            border: 1px solid #e0d6bd;
            background: #fbf7ee;
            border-radius: 8px;
            padding: 0.5em 0.8em;
        }
        .visa__label { display: block; font-size: 0.75em; color: #8a8375; margin-bottom: 0.15em; }
        .visa__value { font-weight: 700; line-height: 1.35; }

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
<div class="poster @if ($letterhead) poster--letterhead @endif"
     @if ($letterhead)
         style="background-image: url('{{ $letterhead }}'); padding: {{ $lhTop }}% 0 {{ $lhBottom }}%;"
     @endif>

@if ($letterhead)
    {{-- الورقة الرسمية تحمل ترويستها وتذييلها، فنكتفي بعنوان العرض داخل بياضها --}}
    <div class="lh-title">
        @if ($has($offer->title ?? null))
            <h1>{{ $offer->title }}</h1>
        @endif
        @if ($metaLine !== '')
            <p>{{ $metaLine }}</p>
        @endif
    </div>
@else

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
            {{-- التأشيرة بلا ليالٍ ولا طيران، فالسطر يبقى فارغاً ولا يُطبع --}}
            @if ($metaLine !== '')
                <p class="hero__meta">{{ $metaLine }}</p>
            @endif
        </div>
    </header>
@endif

    {{-- ===== 2+3) الجدول/التفاصيل والأقسام داخل صندوق يتقلّص ليَسَع الصفحة ===== --}}
    <div id="fit">
        <div id="fit-inner">

            @if ($isVisa)
                {{-- ===== 3) التأشيرة: سعر بارز ثم حقائق في عمودين، بلا جدول ===== --}}
                <div class="visa">
                    @if ($visaPrice !== null)
                        <p class="visa__price"><span>{{ $fmtPrice($visaPrice) }}</span> د.أ للفرد الواحد</p>
                    @endif

                    @if (count($visaFacts))
                        <div class="visa__facts visa__facts--{{ min(count($visaFacts), 3) }}">
                            @foreach ($visaFacts as $label => $value)
                                <div class="visa__fact">
                                    <span class="visa__label">{{ $label }}</span>
                                    <div class="visa__value">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            @elseif ($showTable && $multiCity)
                {{-- ===== 2) جدول مُجمّع بالمدن: الخيار الواحد سطر يضمّ فندقاً لكل مدينة ===== --}}
                <table class="prices">
                    <thead>
                        <tr>
                            @foreach ($cities as $city)
                                <th class="group" colspan="{{ count($descCols) }}">فندق {{ $city }}</th>
                            @endforeach
                            @foreach ($shownRooms as $label)
                                <th rowspan="2">{{ $label }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($cities as $city)
                                @foreach ($descCols as $label)
                                    <th class="sub">{{ $label }}</th>
                                @endforeach
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($options as $option)
                            @php $slots = $staysByCity($option); @endphp
                            <tr>
                                @foreach ($cities as $i => $city)
                                    @foreach ($descCols as $key => $label)
                                        @php $cell = $stayCell($slots[$i] ?? null, $key); @endphp
                                        <td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>
                                    @endforeach
                                @endforeach

                                @foreach ($shownRooms as $key => $label)
                                    @php $p = $rawPrice($option, $key); @endphp
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
                @if (count($cities) > 1)
                    <p class="table-note">السعر للفرد ويشمل الإقامة في الفندقين معاً.</p>
                @endif

            @elseif ($showTable)
                {{-- ===== 2) جدول بمدينة واحدة: الخيار الواحد سطر بإقامة واحدة ===== --}}
                <table class="prices">
                    <thead>
                        <tr>
                            @foreach ($descCols as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                            @foreach ($shownRooms as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($options as $option)
                            @php $stay = collect($option->stays ?? [])->first(); @endphp
                            <tr>
                                @foreach ($descCols as $key => $label)
                                    @php $cell = $stayCell($stay, $key); @endphp
                                    {{-- الاسم لا يُترك فارغاً في الجدول المفرد: شرطة مكانه --}}
                                    <td class="{{ $cell['class'] }}">{{ $cell['text'] !== '' ? $cell['text'] : ($key === 'name' ? '—' : '') }}</td>
                                @endforeach

                                @foreach ($shownRooms as $key => $label)
                                    @php $p = $rawPrice($option, $key); @endphp
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

                {{-- الشروط والمتطلبات (أساساً للتأشيرة) --}}
                @if ($has($offer->requirements ?? null))
                    <div class="sec">
                        <p class="sec__title">-الشروط والمتطلبات :</p>
                        <div class="sec__body">{!! nl2br(e(trim($offer->requirements))) !!}</div>
                    </div>
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

    {{-- ===== 4) التذييل: هواتف · عنوان · بريد =====
         يُخفى في وضع الورقة الرسمية لأنّ الورقة تحمل تذييلها الخاص --}}
    @if (!$letterhead && (count($phones) || $has($address) || count($emails)))
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

{{-- تقليص الجدول والأقسام حتى تسَع مقاس الصفحة الثابت --}}
<script>
(function () {
  var box = document.getElementById('fit');
  var inner = document.getElementById('fit-inner');
  var table = inner.querySelector('table.prices');
  var size = 100;

  // الجدول المُجمّع بالمدن قد يبلغ 14 عموداً فيتجاوز العرض لا الارتفاع فقط.
  // نقيس العرض على الجدول نفسه لأنّ scrollWidth للحاوية لا يرصد فيضان الجدول.
  function tooWide() {
    return table ? table.scrollWidth > table.clientWidth + 1 : false;
  }
  function tooTall() {
    return inner.scrollHeight > box.clientHeight;
  }

  while ((tooTall() || tooWide()) && size > 40) {
    size -= 2;
    inner.style.fontSize = size + '%';
  }

  // إن بقي الجدول عريضاً بعد بلوغ الحدّ الأدنى، نضيّق الحشو ثم نقلّص الجدول وحده
  if (tooWide()) {
    table.classList.add('prices--tight');
    var tSize = 100;
    while (tooWide() && tSize > 45) {
      tSize -= 3;
      table.style.fontSize = (1.375 * tSize / 100) + 'em';
    }
  }

  document.documentElement.setAttribute('data-ready', '1');
})();
</script>
</body>
</html>

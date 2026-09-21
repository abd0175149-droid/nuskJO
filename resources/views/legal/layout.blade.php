<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($co = \App\Services\CompanyInfo::all())
<title>@yield('title') — {{ $co['name'] }}</title>
    <meta name="description" content="@yield('desc')">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap">
    <style>
        :root{
            --bg:#faf8f4; --surface:#fff; --ink:#1e1c17; --ink2:#443f35;
            --muted:#6e675a; --line:#e3ded2; --gold:#8a6d22; --gold-soft:#f7f0de;
        }
        @media (prefers-color-scheme: dark){
            :root{ --bg:#131210; --surface:#1b1a16; --ink:#efeae0; --ink2:#cfc8ba;
                   --muted:#9a9384; --line:#312e27; --gold:#d9b76c; --gold-soft:#2a2418; }
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--ink);
             font-family:'Cairo',system-ui,-apple-system,sans-serif;
             font-size:16px;line-height:1.9;-webkit-font-smoothing:antialiased}
        .wrap{max-width:820px;margin:0 auto;padding:0 20px 80px}
        header{padding:44px 0 22px;border-bottom:1px solid var(--line)}
        .brand{font-size:13px;font-weight:700;color:var(--gold);letter-spacing:.04em}
        h1{font-size:clamp(26px,5vw,34px);margin:10px 0 6px;line-height:1.3}
        .updated{font-size:13px;color:var(--muted)}
        h2{font-size:20px;margin:34px 0 8px;color:var(--ink)}
        h3{font-size:16px;margin:20px 0 6px}
        p,li{color:var(--ink2)}
        ul{padding-inline-start:22px;margin:8px 0}
        li{margin:5px 0}
        a{color:var(--gold)}
        .box{background:var(--surface);border:1px solid var(--line);border-radius:12px;
             padding:16px 20px;margin:20px 0}
        .box.gold{background:var(--gold-soft);border-color:var(--gold)}
        table{border-collapse:collapse;width:100%;margin:12px 0;font-size:15px}
        th,td{text-align:right;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
        th{color:var(--muted);font-size:13px;font-weight:700}
        nav.legal{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
        nav.legal a{font-size:13px;text-decoration:none;border:1px solid var(--line);
                    border-radius:999px;padding:5px 13px;color:var(--ink2)}
        nav.legal a:hover{border-color:var(--gold);color:var(--gold)}
        footer{margin-top:50px;padding-top:20px;border-top:1px solid var(--line);
               font-size:13px;color:var(--muted)}
        .en{margin-top:44px;padding-top:24px;border-top:1px dashed var(--line);
            direction:ltr;text-align:left}
        .en h2,.en h3{margin-top:22px}
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand">{{ $co['name'] }}</div>
        <h1>@yield('title')</h1>
        <p class="updated">آخر تحديث: {{ $updated ?? '21 أيلول 2026' }}</p>
        <nav class="legal">
            <a href="/privacy">سياسة الخصوصية</a>
            <a href="/terms">الشروط والأحكام</a>
            <a href="/data-deletion">حذف البيانات</a>
        </nav>
    </header>

    @yield('content')

    <footer>
        © {{ date('Y') }} {{ $co['name'] }} — المملكة الأردنية الهاشمية.
        @if($co['address'])<br>{{ $co['address'] }}@endif
        @if($co['hours'])<br>الدوام: {{ $co['hours'] }}@endif
        <br>
        @if($co['phone'])هاتف: <a href="tel:{{ $co['phone'] }}" dir="ltr">{{ $co['phone'] }}</a>@endif
        @if($co['email']) · <a href="mailto:{{ $co['email'] }}">{{ $co['email'] }}</a>@endif
        @if($co['facebook']) · <a href="{{ $co['facebook'] }}" target="_blank" rel="noopener">فيسبوك</a>@endif
    </footer>
</div>
</body>
</html>

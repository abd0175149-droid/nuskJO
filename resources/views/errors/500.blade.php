<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>خطأ في النظام</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, "Noto Sans Arabic", sans-serif; background: #f9fafb; color: #1f2937; }
        main { max-width: 28rem; margin: 1rem; padding: 2rem; border-radius: 1rem; background: #fff; border: 1px solid #e5e7eb; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { color: #4b5563; line-height: 1.7; margin: .5rem 0; }
        code { direction: ltr; unicode-bidi: isolate; font-family: ui-monospace, monospace; background: #f3f4f6; padding: .1rem .4rem; border-radius: .375rem; }
        a { color: #a16207; font-weight: 700; }
        @media (prefers-color-scheme: dark) { body { background: #030712; color: #f3f4f6; } main { background: #111827; border-color: #374151; } p { color: #d1d5db; } code { background: #1f2937; } }
    </style>
</head>
<body>
<main>
    <h1>حدث خطأ غير متوقع</h1>
    <p>سُجّل الخطأ ويمكن للدعم الفني تتبّعه.</p>
    @if ($ref = \Illuminate\Support\Facades\Context::get('request_id'))
        <p>رقم المرجع: <code>{{ substr($ref, 0, 8) }}</code></p>
    @endif
    <p><a href="/">العودة إلى الرئيسية</a></p>
</main>
</body>
</html>

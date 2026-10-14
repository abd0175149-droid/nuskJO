<?php

/*
 * الوكلاء الموثوقون لعنوان الزائر (X-Forwarded-For) — يقرؤه وسيط TrustProxies وقت الطلب.
 * «*» يثق بأي مصدر: يبقى افتراضياً حتى يُربط المنفذ على 127.0.0.1 (docs/RELEASE.md «ربط المنفذ»)،
 * ثم TRUSTED_PROXIES=127.0.0.1,<بوابة شبكة الحاوية>/32 في .env.production. whatsapp:doctor ينبّه ما دام «*».
 */
return [
    'proxies' => env('TRUSTED_PROXIES', '*'),
];

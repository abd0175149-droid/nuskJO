# nuskJO

نظام إدارة وكالة نُسك للسياحة والسفر (الأردن): العروض والفواتير والمحاسبة والموظفون، ومعها طبقة واتساب للأعمال فيها صندوق محادثات للموظفين وبوت ذكاء اصطناعي ومساعد إداري.

- **الحزمة:** Laravel 13 وPHP 8.3، وInertia مع Vue 3 وVite، وقاعدة SQLite، وReverb للبث اللحظي، في حاوية Docker واحدة خلف Cloudflare.
- **قاعدة ثابتة:** واتساب عبر WhatsApp Cloud API الرسمية وحدها. لا whatsapp-web.js ولا Baileys ولا أي عميل غير رسمي، فهذا يخالف شروط واتساب ويعرّض رقم الشركة الوحيد للحظر. يحرسها `tests/Unit/Arch/OfficialChannelOnlyTest.php`.
- **الوثائق:** الفهرس في [docs/README.md](docs/README.md)، ومعه سياسة ما يدخل هذا المستودع العام.
- **الأمن:** للإبلاغ عن ثغرة اقرأ [SECURITY.md](SECURITY.md). لا تفتح issue عامة لثغرة.

## التشغيل المحلي

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm run build      # أو npm run dev أثناء التطوير
php artisan serve
```

## التشغيل (nuskJO)

- النشر: [docs/RELEASE.md](docs/RELEASE.md) و`scripts/deploy.sh`.
- النسخ الاحتياطي: [docs/BACKUP.md](docs/BACKUP.md).
- الاستعادة: [docs/RESTORE.md](docs/RESTORE.md).
- الطوارئ: كتيّبات [docs/RUNBOOK.md](docs/RUNBOOK.md)، والمخاطر ومهل الاستجابة [docs/RISKS.md](docs/RISKS.md).
- التغييرات وملاحظات الترقية: [CHANGELOG.md](CHANGELOG.md).

## الاختبار (nuskJO)

```bash
composer install
cp .env.example .env && php artisan key:generate
composer test          # أو: php artisan test
npm test              # اختبارات الواجهة: Vitest ثم node --test
```

كلاهما شرط قبل `git push`.

- **لا طلبات حقيقية:** `tests/TestCase.php` يفعّل `Http::preventStrayRequests()`، فكل نداء لميتا أو للنموذج يجب أن يُزيَّف بـ `Http::fake()` (الأدوات في `tests/Concerns/ConfiguresWhatsApp.php`). و`phpunit.xml` يفرض مفاتيح فارغة لواتساب والنماذج وFirebase.
- **تحقق إيجابي:** كل اختبار يثبت ما حدث (`Http::assertSent` أو حالة الرسالة أو الوظيفة المجدولة)، لأن الكود يبتلع الاستثناءات في مواضع عدة، فطلب نُسي تزييفه قد يُمنع بصمت ويبدو الاختبار ناجحاً.
- **حمولات ميتا:** في `tests/Fixtures/whatsapp/` بأرقام ميتا النموذجية فقط (مصادرها في `PROVENANCE.txt`). المستودع عام، فلا تُنسخ أي حمولة من الإنتاج؛ واختبار `FixturePiiGuardTest` يرفض أي رقم أردني أو جواز.
- **الثوابت:** قواعد سلوك البوت والقناة في `tests/Invariants.php` بمعرّفات `INV-xx`. ثابت جديد يُضاف هناك مع شرح «لماذا الإصلاح الظاهر خاطئ»، ويُوسم اختباره بـ `#[Group('INV-xx')]`؛ و`InvariantCatalogTest` يفشل إن بقي معرّف بلا اختبار.
- **سجل التدقيق:** كل مسار كتابة جديد يُصنَّف في `config/audit_routes.php` (فعل تدقيق أو `none: السبب`)، وكل حالة جديدة في `AuditAction` تُكتب في الكود أو تُذكر في `INTENTIONALLY_UNEMITTED`؛ `AuditRouteCoverageTest` و`AuditCoverageTest` يفشلان غير ذلك.
- **النشر:** `scripts/deploy.sh` يبني `Dockerfile.test` ويشغّل الاختبارات بلا شبكة قبل أي بناء للإنتاج، عدا مجموعة `horizon` المرتبطة بالتاريخ (تعمل في CI).
- **التوثيق محروس:** الجدولة والتنبيهات والأعلام و`.env.example` والقدرات والمسارات والأسرار تُفحص في `tests/Feature/Docs/` و`tests/Unit/Docs/`؛ ما تحدّثه مع كل تغيير في [docs/README.md](docs/README.md) «من يحدّث ماذا».

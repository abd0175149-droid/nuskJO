# قائمة النشر

كل نشر يمر بهذه الخطوات بالترتيب. `scripts/deploy.sh` ينفّذ السحب وبوابة الاختبارات والبناء والتشغيل؛ والباقي يدوي.

## قبل النشر

1. **وسم نقطة الرجوع:**
   ```bash
   cd /opt/nuskJO
   NAME=<اسم قصير للتغيير>; DATE=$(date +%Y%m%d)
   docker run --rm -v "$PWD":/repo -w /repo alpine/git tag "backup/pre-$NAME-$DATE"
   docker tag nuskjo-nuskjo-app:latest "nuskjo-nuskjo-app:backup-pre-$NAME"
   ```
2. **نسخة يدوية وسحبها مشفّرة:**
   ```bash
   docker exec -u www-data nuskjo-app php artisan backup:run --reason=manual --label="pre-$NAME"
   bash scripts/backup/collect.sh
   ```
3. **إن كان في التغيير هجرات:** ابنِ الصورة المرشّحة وجرّب هجراتها على بيانات اليوم دون لمس الإنتاج:
   ```bash
   docker compose build --build-arg GIT_COMMIT=$(docker run --rm -v "$PWD":/repo -w /repo alpine/git rev-parse --short HEAD) nuskjo-app
   docker tag nuskjo-nuskjo-app:latest nuskjo-nuskjo-app:candidate
   DRILL_IMAGE=nuskjo-nuskjo-app:candidate bash scripts/backup/drill.sh --rehearse
   ```
   فشلها يعني: لا تنشر.
4. اقرأ «ملاحظات الترقية» للإصدار في [CHANGELOG.md](../CHANGELOG.md).

## النشر

```bash
bash scripts/deploy.sh
```
يشغّل الاختبارات في حاوية بلا شبكة قبل البناء، ويتوقف إن فشلت. عند الإقلاع يأخذ التطبيق نسخة تلقائية قبل أي هجرة معلّقة (`BACKUP_PRE_MIGRATE=true`) ويرفض الهجرة إن فشلت النسخة.

**الصلاحيات الجديدة تصل بهجرات فقط.** لا تشغّل `RolePermissionSeeder` على الإنتاج: يعيد مزامنة صلاحيات الأدوار فيمسح ما خصّصه المدير. في الطوارئ: `docker exec -it nuskjo-app php artisan auth:restore-admin <البريد> --reset-password`.

## بعد النشر

- `docker ps` يُظهر الحاوية healthy (الفحص على `/up`).
- `docker exec -u www-data nuskjo-app php artisan migrate:status` بلا Pending.
- صفحة النسخ الاحتياطي: الأعداد معقولة وآخر نسخة (pre-migrate إن وُجدت هجرات) ناجحة.
- السطر `deployed` في `/opt/nuskJO-deploy.log`.

## التراجع

| ماذا | كيف |
|---|---|
| الكود والصورة | `docker tag nuskjo-nuskjo-app:backup-pre-$NAME nuskjo-nuskjo-app:latest && docker compose up -d nuskjo-app`، ثم أعِد الفرع إلى الوسم `backup/pre-$NAME-$DATE`. |
| القاعدة (إن أفسدتها هجرة) | `bash scripts/backup/restore.sh --from-spool <لقطة pre-migrate> --force`، ثم خطوات [RESTORE.md](RESTORE.md). |
| إعداد | أعِد قيمة المتغير في `.env.production` ثم `docker compose up -d nuskjo-app`. |

## فصل حجم القاعدة (مرة واحدة)

قبل الفصل، حجم القاعدة يغطي مجلد `database/` كله فيحجب ملفات الهجرات في الصورة الجديدة (فكانت تُنسخ يدوياً بـ docker cp). بعده تعيش القاعدة وحدها في `database/data/` على الحجم `nuskjo_nuskjo-dbdata`.

حين يصل تغيير `docker-compose.yml` الخاص بالفصل، يتوقف `scripts/deploy.sh` بعد البناء ويطلب هذه الخطوات بدل الإقلاع على حجم فارغ:

```bash
cd /opt/nuskJO
M=scripts/backup/move-db-volume.sh
ARGS="--compose-dir /opt/nuskJO --project nuskjo --service nuskjo-app --old nuskjo_nuskjo-database --new nuskjo_nuskjo-dbdata"
bash $M $ARGS --check-only     # (أ) هجرات ستعمل عند الإقلاع، (ب) هجرات دخلت بـ docker cp ولم تُحفظ، (ج) نسخ قديمة في الحجم (تُشفَّر ولا تُحذف)
bash $M $ARGS                  # في نافذة هادئة: نسخة وسحب مشفّر، إيقاف، نسخ القاعدة، مقارنة الأعداد، إقلاع، فحص
```

الحجم القديم يبقى كما هو 30 يوماً على الأقل. الرجوع (يحمل معه ما كُتب بعد التبديل): `bash $M $ARGS --reverse`، ثم `git revert` لتغيير الحجم في `docker-compose.yml` و`docker compose up -d nuskjo-app`. السكربت نفسه يعمل لمشاريع أخرى بالتخطيط نفسه بتغيير `--compose-dir --project --service --old --new`؛ وابدأ بالأقل أهمية.

## إطلاق حارس الصلاحيات (مرة واحدة)

1. قبل النشر: `AUTHZ_MODE=log` في `.env.production`. الافتراضي في الكود الرفض.
2. انشر كالمعتاد. يُفرض فوراً حتى في وضع التسجيل: الاعتماد والرفض، وفتح المعتمد للتعديل والحذف (للمدير)، وإدارة المستخدمين والأدوار والإعدادات، وإقفال الفترات.
3. أسبوعاً، يومياً: `docker exec -u www-data nuskjo-app php artisan authz:report --hours=24`. كل «would_deny» لموظف يحتاج الصفحة فعلاً: امنح دوره الصلاحية من **الإعدادات › الصلاحيات** (لا بالـ seeder). و«unmapped» تعني مساراً جديداً نُسي إعلان صلاحيته في routes/web.php: أصلحه في الكود.
4. ثم احذف المتغير (أو `AUTHZ_MODE=enforce`) و`docker compose up -d nuskjo-app` لإعادة `config:cache`.

## إطلاق نطاق صندوق الواتساب (مرة واحدة)

بعده يرى الموظف بلا «رؤية كل المحادثات» ما يحمل وسماً من اختصاصه أو ما وُكّل له فقط.
1. قبل النشر: املأ **الواتساب › توجيه المواضيع** بمختص أساسي لكل موضوع، وامنح `whatsapp.view_all` لمن يوزّع المحادثات، وقرّر من يُمنح `whatsapp.admin_chats` (محادثات أرقام الإدارة؛ دور المدير يملكها تلقائياً).
2. انشر، ثم الهجرة `2026_10_14_000005` باسم www-data. امنح `whatsapp.admin_chats` للأدوار المقررة من **الإعدادات › الصلاحيات**.
3. `docker exec -u www-data nuskjo-app php artisan whatsapp:fence-report`: كل موظف في «لا يرون أي محادثة» يحتاج اختصاصاً أو توكيلاً. أرسل للموظفين: حدّثوا الصفحة.
4. بعد يومين أو ثلاثة بلا شكاوى: أعد التقرير، ثم `WA_FENCE_QUOTES=true` في `.env.production` و`docker compose up -d nuskjo-app`.

## ترقية إصدار Graph (عند كل إصدار جديد)

التنبيه اليومي يبدأ قبل 60 يوماً من نهاية الإصدار، واللوحة في **إعدادات الواتساب › 🧭 الإصدارات والحماية** تعرض الحالة.
1. `docker exec -u www-data nuskjo-app php artisan whatsapp:graph-smoke --target=<الإصدار الهدف> --media` (قراءة فقط).
2. `WA_GRAPH_VERSION=<الهدف>` في `.env.production` ثم `docker compose up -d --force-recreate nuskjo-app`. جرّب نصاً وصورة وصوتاً وحالات التسليم، وراقب 48 ساعة.
3. بعدها ارفع إصدار حقول الويبهوك في App Dashboard › WhatsApp › Configuration، واكتب `WA_WEBHOOK_VERSION` بالقيمة نفسها، وأرسل رسالة واردة من هاتف اختبار.
4. التراجع: الإصدار السابق في `WA_GRAPH_VERSION` ثم force-recreate (يصلح حتى تاريخ نهايته فقط).

فحص مضيف الوسائط: أسبوع بـ `WA_MEDIA_HOST_MODE=log` بلا «WA media host outside allowlist» ولا «sha256 mismatch» في السجل، ثم `enforce`.
appsecret_proof (اختياري): `WA_APPSECRET_PROOF=true` أسبوعاً بلا APPSECRET_PROOF_INVALID، والتأكد أن لا مستهلك آخر لرموز التطبيق، ثم «Require App Secret» في اللوحة. التراجع: ألغِ الخيار في اللوحة أولاً ثم أطفئ المتغير.

## الأسرار خارج الصورة (مرة واحدة، قبل أول نشر لها)

1. **قبل النشر** طابق بصمة المفتاح الفعّال ببصمة الملف (8 خانات، لا المفتاح):
   `docker exec nuskjo-app php -r '$c=require "/var/www/html/bootstrap/cache/config.php"; echo substr(hash("sha256",$c["app"]["key"]),0,8);'`
   و`grep '^APP_KEY=' /opt/nuskJO/.env.production | cut -d= -f2- | tr -d '"\r' | sha256sum | cut -c1-8`.
   إن اختلفتا فالمفتاح الفعّال مخبوز في الصورة: انقله إلى `.env.production` قبل النشر، وإلا لن تُقرأ الإعدادات المشفّرة. واحفظ المفتاح في مدير كلمات المرور.
2. انشر. الإقلاع يرفض الآن مفتاحاً لا يُفك إلى 32 بايتاً أو `APP_DEBUG=true` (`BOOT_GUARD=warn` للطوارئ)، ويطبع بصمة المفتاح فقط. `docker compose logs nuskjo-app | grep fingerprint` يجب أن يطابق البصمة أعلاه، و`whatsapp:doctor` يطبع ملاحظات الصحة.
3. البناء يمر بـ `docker/check-context.sh` (ضمن deploy.sh)، وVITE_* تصل الواجهة كسرّ بناء. تحقق: `docker run --rm --entrypoint sh nuskjo-nuskjo-app -c 'ls -a /var/www/html | grep "^\.env"'` فارغ، وجرس الإشعارات اللحظي يعمل.

## ربط المنفذ على loopback (مرة واحدة، Demo أولاً)

1. تأكد: `docker version` 28 أو أحدث، ولا أحد يطرق 4020 مباشرة غير بوابة شبكة الحاوية (سجل nginx في `docker compose logs`)، وسجّل البوابة: `docker network inspect nuskjo_default -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}'`.
2. غيّر قاعدة النفق لـ nuskjo وحدها في Zero Trust إلى `http://127.0.0.1:4020` (النفق مشترك بين المشاريع: لا تستبدل قائمة ingress كلها).
3. أنشئ `/opt/nuskJO/.env` (لتعويض compose لا للتطبيق) فيه `NUSKJO_BIND_ADDRESS=127.0.0.1`، ثم `docker compose up -d nuskjo-app`. تحقق: `ss -ltnp | grep 4020` يعرض 127.0.0.1 فقط، والموقع والدخول والجرس اللحظي وويبهوك ميتا وبطاقات العروض تعمل.
4. بعد يوم مستقر: `TRUSTED_PROXIES=127.0.0.1,<البوابة>/32` في `.env.production` ثم `up -d`. تحقق: آخر صفوف `audit_logs.ip_address` عناوين عامة، والحضور بالشبكة يعمل، ولا `proxy.untrusted_forwarded` في السجل، و`whatsapp:doctor` بلا TRUST_ALL_PROXIES.
5. التراجع: احذف السطر من `/opt/nuskJO/.env` ثم `up -d`، وأعد قاعدة النفق.

## موثوقية رد الواتساب (مرة واحدة، على مراحل)

**النشر الأول (الوظيفة والهجرة `2026_10_14_000007` وWAL وفصل الطابور معاً):**
1. نسخة: `docker exec -u www-data nuskjo-app php artisan backup:run --reason=manual --label=pre-e07`.
2. أوقف التقاط وظائف جديدة واترك الجارية تنتهي: `docker exec -u www-data nuskjo-app php artisan queue:pause database:default`، ثم انتظر حتى يصير `docker exec -u www-data nuskjo-app sqlite3 /var/www/html/database/data/database.sqlite "SELECT count(*) FROM jobs WHERE reserved_at IS NOT NULL"` صفراً (دقيقتان على الأكثر).
3. انشر، ثم **فوراً** `docker exec -u www-data nuskjo-app php artisan queue:continue database:default`. الإيقاف محفوظ في كاش القاعدة فيبقى بعد النشر، وبدون هذا الأمر لا تُرسل الإشعارات. ما بقي من ردود في default يعالجه عامل default مرة واحدة.
4. تحقق: `PRAGMA journal_mode` يرجع `wal` (docs/RESTORE.md «WAL»)، و`supervisorctl status` داخل الحاوية يعرض `laravel-wa:laravel-wa_00` و`laravel-queue` يعملان، و`php artisan whatsapp:reliability` يعرض الأعلام.

**الأعلام بعدها، كلٌّ وحده** (`docker exec -u www-data nuskjo-app php artisan whatsapp:reliability <العلم> on|off`):
- `reconcile` بعد أسبوع هادئ، وبعد أن تبدو قائمة `whatsapp:reconcile-replies --dry-run` منطقية.
- `webhook_5xx` بعد WAL فقط.
- التراجع: `off` للعلم نفسه، بلا نشر.

**الرجوع عن فصل الطابور:** `WA_REPLY_QUEUE=default` في `.env.production` ثم `docker compose up -d nuskjo-app`، ثم صرّف ما بقي: `docker exec -u www-data nuskjo-app php artisan queue:work database --queue=wa-replies --stop-when-empty`.

**عامل رد ثانٍ (`numprocs=2` لـ laravel-wa وحده)**، وفقط إن تحققت كل الشروط:
- مرّ أسبوع كامل بعد WAL وفصل الطابور.
- `grep -c "database is locked"` على `storage/logs/laravel*.log` و`storage/logs/queue-*-error.log` يعطي صفراً.
- لا صفوف ProcessWhatsAppReply جديدة في `failed_jobs`.
- `docker stats nuskjo-app` يترك هامشاً للعامل الإضافي (نحو 60–80MB).

بعد رفع العدد: راقب «database is locked» 72 ساعة، وقِس في الذروة الزمن من `jobs.available_at` إلى بدء التنفيذ. للتراجع: `numprocs=1` ثم إعادة النشر.

لا تنشر أثناء نسخة يدوية جارية من صفحة النسخ: عامل default ينتظر 40 ثانية فقط عند الإيقاف.

## فحص الصحة وتنبيهات ميتا (مرة واحدة، على مراحل)

العلَمان في `/opt/nuskJO/.env` (ملف تعويض compose، لا `.env.production`)، وكل تغيير بعده `docker compose up -d nuskjo-app`.

1. **نشر صامت:** انشر كالمعتاد؛ العلَمان مطفآن، والهجرة `2026_10_14_000008` تعمل في الإقلاع. تحقق:
   - `docker exec -u www-data nuskjo-app php artisan ops:status`: نبضة المجدول ونبضتا العاملين أحدث من دقيقتين.
   - `docker exec -u www-data nuskjo-app php artisan schedule:list | grep -E "ops:|credentials|health-status"`.
2. **فحص الصحة:** `OPS_HEALTH_CHECKS=true` ثم `up -d`. تحقق:
   - `docker exec nuskjo-app curl -fsS -o /dev/null -w '%{http_code}\n' localhost:4020/up` يطبع 200.
   - بعد دقيقتين، `docker inspect -f '{{.State.Health.Status}}' nuskjo-app` يطبع healthy.
   - في وقت هادئ: `docker exec nuskjo-app supervisorctl stop laravel-queue`. خلال 7 دقائق تقريباً تصير الحالة unhealthy ويصل إشعار واحد «⛔ العامل الخلفي متوقف». ثم `supervisorctl start laravel-queue`.
3. **فحوص ميتا:** أدخل App ID في **الواتساب › الإعدادات › ربط قناة ميتا** (App settings › Basic في لوحة ميتا)، ثم `WA_META_CHECKS=true` و`up -d`. شغّل يدوياً:
   - `docker exec -u www-data nuskjo-app php artisan whatsapp:credentials-check`
   - `docker exec -u www-data nuskjo-app php artisan whatsapp:health-status`
   راجع بطاقة «🩺 صحة النظام والقناة». إن ظهر «معرّف التطبيق أو سرّه خطأ»، فتأكد أن App Secret المحفوظ للتطبيق نفسه الذي وُلّد منه رمز مستخدم النظام.
4. **المراقب الخارجي:** حين تتوقف القاعدة أو الحاوية كلها لا يستطيع التطبيق التنبيه. يطرق المراقب `https://nuskjo.grade.sbs/up` كل 5 دقائق ويرسل بريداً عند الفشل. لا يرى إلا up أو down.

**التراجع:** `OPS_HEALTH_CHECKS=false` و/أو `WA_META_CHECKS=false` ثم `up -d`. بعدها يعود /up إلى 200 دائماً، وتتوقف نداءات ميتا المجدولة. تنبيه أخطاء الاعتماد عند الإرسال يبقى، فهو من E06.

## عميل باسم مستخدم بلا رقم (مرة واحدة، على مراحل)

حين يفعّل عميل اسم مستخدم في واتساب ولم نتواصل معه خلال 30 يوماً، ترسل ميتا رسالته بلا رقم، ومعها معرّف خاص بنشاطنا (BSUID). كانت هذه الرسائل تُسقط. الآن تُحفظ في محادثة تُعرف بالمعرّف، وتظهر برقاقة «📵 رقم غير ظاهر».

1. **قبل النشر:** نسخة احتياطية: `backup:run --reason=manual --label=pre-e11a`. الهجرة `2026_10_14_000009` تعيد بناء wa_conversations لتقبل رقماً فارغاً، وتحفظ الفهارس والصفوف، وتستغرق ثوانٍ.
2. **بعد النشر:** يعمل الوضع `store` افتراضياً. تأكد بـ `docker exec -u www-data nuskjo-app php artisan whatsapp:identity-mode`. في هذا الوضع:
   - تُحفظ رسالة العميل بلا رقم، وتصير المحادثة «تدخّل»، ويُنبَّه المدير.
   - لا يُرسل إليه أحد بعد.
3. **قبل `staff`:** جرّب على رقم الاختبار في تطبيق ميتا التجريبي:
   - رسالة نصية بالحقل `recipient` إلى معرّف من أداة Test (App Dashboard › WhatsApp › Configuration › messages › Test).
   - احفظ حمولات السيناريوهات (بلا اسم مستخدم، باسم مستخدم بلا رقم، باسم مستخدم مع رقم) لتحل محل tests/Fixtures/whatsapp/username_*.json.
   ثم شغّل `whatsapp:identity-mode staff`. يرد الموظفون بالمعرّف، والبوت لا يرد.
4. **`full`** (بعد أسبوع هادئ): يرد البوت أيضاً. لا يكشف أي بيانات مالية بلا رقم، ويطلب من العميل كتابة رقمه إن سأل عن تسعير أو حجز.
- **Contact book:** أبقه مفعّلاً في Meta Business Suite › Business settings › Business info. تعطيله يحذف ما خزّنته ميتا من أرقام عملائنا.
- **التراجع:** `whatsapp:identity-mode store`. الرقم يبقى قابلاً للفراغ، ولا عودة عن الهجرة إلا بالاستعادة.

### الدفعة الثانية: الأنواع الواردة وطلب الرقم والربط اليدوي

الهجرة `2026_10_14_000012` إضافية (أعمدة الاقتباس والوسائط والإحالة ونص البحث ورقمنا المرسِل ورمز الخطأ وحالة الربط والدمج) وتمنح المدير `whatsapp.link_client`.

1. **قبل النشر:** نسخة `backup:run --reason=manual --label=pre-e11`، ثم احفظ المخطط:
   `docker exec -u www-data nuskjo-app php artisan whatsapp:verify-schema` (يحفظ JSON في `storage/app/reports` ويعيد 1 عند أي نقص؛ أعداد وأسماء فهارس فقط).
2. **النشر** ثم `whatsapp:verify-schema` مرة ثانية: عدد الصفوف وmax(id) كما هي، ولا فهرس ناقص، وintegrity_check = ok.
3. **التعبئة:** `whatsapp:backfill-identity --dry-run` (لا يكتب شيئاً)، ثم بلا `--dry-run`. يملأ المعرّف حيث جاء مع الرقم في حمولة واحدة، ولقب واتساب، والاقتباس والوسائط والإحالة ونص البحث. يمكن إعادة تشغيله بأمان.
4. **تقرير الروابط:** `storage/app/reports/wa-link-review-<التاريخ>.csv` (رقم المحادثة وكود العميل والسبب، بلا أرقام): روابط آلية قديمة يخالفها المطابق الجديد. لا يُفك شيء آلياً؛ راجعها من الصندوق بزر «🔗 تغيير الربط» أو «فك الربط».
5. **الوضع** صار من إعدادات الواتساب › 🪪 هوية العملاء (يُسجَّل في التدقيق)، والأمر `whatsapp:identity-mode` باقٍ. في `staff` يظهر للموظف زر «📱 طلب الرقم» للمحادثة بلا رقم داخل النافذة؛ وفي `full` يرسله البوت مع طلب التسعير.
6. **staging:** جرّب زر طلب الرقم من جهاز اختبار ثم مشاركة الرقم (تكتمل المحادثة وتُربط)، والربط اليدوي على عرض الهاتف.
- **التراجع:** `git revert` ثم النشر. الأعمدة إضافية يتجاهلها الكود القديم.

## سجل التدقيق (مرة واحدة، على مراحل)

سجل واحد لمن فعل ماذا ومتى: المال والصلاحيات والإعدادات والعروض والواتساب وأحداث حساب ميتا والدخول. للإلحاق فقط (القاعدة ترفض تعديل صف).
العلَمان في `/opt/nuskJO/.env` (ملف تعويض compose)، وكل تغيير بعده `docker compose up -d nuskjo-app`.

1. **قبل النشر:** نسخة `backup:run --reason=manual --label=pre-e09`. الهجرة `2026_10_14_000010` توسّع `audit_logs` (أعمدة وفهارس ومُشغّل الإلحاق) وتمنح المدير `audit.view` و`audit.export`.
2. **نشر صامت:** العلَمان مطفآن. يُكتب من اليوم الأول: الاعتماد والرفض والحذف والقيود والإقفال (داخل المعاملة نفسها: لا اعتماد بلا صف)، والمستخدمون والأدوار، وإعدادات البوت، وقفل الدخول، والإيقاف التلقائي للإرسال، والتفريغ.
3. **`AUDIT_EXTENDED=true`** (بعد يومين): يضيف الدخول والخروج والمحاولات الفاشلة (بالعيّنات)، وفتح المحادثات (صف لكل موظف ومحادثة كل 30 دقيقة)، وأحداث حساب ميتا، والتوكيل والوسوم، وما يفعله البوت ورقم الإدارة. راقب حجم القاعدة أسبوعاً.
4. **`AUDIT_PAGE=true`:** صفحة **النظام › 🧾 سجل التدقيق** بتبويبين وفلاتر وتصدير CSV (حتى 50,000 صف)، و«تاريخ التعديلات» في العرض والفاتورة، ورابط «السجل الكامل» من أرقام الإدارة.
5. **ميتا:** ليصل كل حدث حساب، اشترك في App Dashboard › WhatsApp › Configuration › Webhook fields في: `account_update` و`account_alerts` و`account_review_update` و`business_capability_update` و`phone_number_quality_update` و`security`.
- **الاحتفاظ:** أحداث الضجيج (دخول، محاولات فاشلة، فتح محادثة، توقيع مرفوض، رفض وصول) تُحذف بعد 90 يوماً يومياً 02:30 (`model:prune`)، ويُسجَّل عددها. الباقي لا يُحذف. و`app:reset-data` لم يعد يمسح السجل، بل يُسجَّل فيه.
- **عدّادات حد الدخول** صارت في كاش الملفات (`CACHE_LIMITER`، الافتراضي file).
- **التراجع:** العلَمان false ثم `up -d`. الهجرة إضافية بحتة.

## طبقة إعدادات الواتساب (مرة واحدة)

صفحة إعدادات البوت تحفظ ما تغيّر فقط برمز نسخة: إن حفظ غيرك (مدير آخر، أو المساعد الإداري، أو إيقاف آلي من ميتا) بعد فتحك الصفحة، يظهر شريط تعارض وتبقى تعديلاتك. والإيقاف الطارئ والأسرار لها أزرارها.

1. **قبل النشر:** نسخة `backup:run --reason=manual --label=pre-e10`. الهجرة `2026_10_14_000011` إضافية (رمز النسخة، وهاش رمز التحقق، وتواريخ التدوير، ومن أوقف الإرسال، وأعلام الميزات) وتضيف صلاحية `whatsapp.kill_switch` بلا منح.
2. **بعد النشر:** `docker exec -u www-data nuskjo-app php artisan whatsapp:doctor` يعرض جدول المصادر (بيئة / محفوظ / افتراضي / غير مضبوط) بلا قيم. ثم في الصفحة: احفظ حقلاً رقمياً واحداً، وجرّب «🛑 إيقاف كل الإرسال» ثم «استئناف» في وقت هادئ.
3. **رمز التحقق (اختياري، في ساعة هادئة):** «🎲 توليد رمز جديد» يعرضه مرة واحدة؛ الصقه فوراً في App Dashboard › WhatsApp › Configuration › Webhook › Edit ثم «Verify and save»، وتأكد أن «آخر مصافحة ناجحة» تحدّث. الرسائل الواردة لا تتأثر.
4. **بعد 48 ساعة هادئة:** `whatsapp:settings-finalize --dry-run` ثم بلا `--dry-run` (يمسح النص الصريح لرمز التحقق ويبقى هاشه، ويحذف مفاتيح أدوات مجهولة). `--reset-prompt` يعيد الموجّه إلى النص الافتراضي الحي إن كان نسخة مطابقة منه.
- **تدوير APP_KEY:** المفتاح القديم في `APP_PREVIOUS_KEYS` ثم `whatsapp:reencrypt-secrets` ثم `whatsapp:doctor` (لا `SECRET_ON_PREVIOUS_KEY`).
- **التراجع:** `git revert` للتغيير. الأعمدة إضافية يتجاهلها الكود القديم. بعد finalize يحتاج الكود القديم `WA_WEBHOOK_VERIFY_TOKEN` في البيئة للمصافحة؛ رسائل POST لا تتأثر.

## قاطع مزوّد الذكاء (مرة واحدة، على مراحل)

إن تعطّل مزوّد الذكاء (حصة منتهية، مفتاح مرفوض، خدمة غير متاحة): بدل رسالة خلل وإيقاف ساعة لكل عميل وإشعار عن كل محادثة، يُحوَّل كل عميل لموظف برسالة ثابتة، ويصل المدراء إشعار عند البدء وآخر عند العودة. والأدوات تقول «غير متاح» بدل «مسدّدة» حين لا حساب.

1. **قبل النشر:** نسخة `backup:run --reason=manual --label=pre-e12`. الهجرة `2026_10_14_000013` إضافية (أعمدة القاطع والمزوّد الاحتياطي، وجدول `wa_llm_incidents`). الوضع بعدها **مراقبة فقط (shadow)**: يسجّل الحوادث ولا يغيّر ما يراه العميل.
2. **بعد النشر:** `docker exec -u www-data nuskjo-app php artisan schedule:list` يُظهر `whatsapp:llm-probe` كل دقيقتين. إعدادات الواتساب › الفشل الآمن › 🔌 قاطع مزوّد الذكاء تعرض الحالة وآخر الحوادث.
3. **3–7 أيام مراقبة:** راجع الحوادث (النوع ورمز HTTP والمدة) واضبط العتبة والمدة.
4. **تمرين في الدوام** بعد إبلاغ الموظفين: «فتح يدوي» 5 دقائق، ثم رسالة من هاتف موظف (رقم غير إداري): رسالة تحويل واحدة، و«تدخّل»، وتوكيل، وإشعار واحد. ثم «إغلاق الآن»: إشعار عودة واحد.
5. **التفعيل:** الوضع «مفعّل». الرجوع الفوري بلا نشر: «إيقاف» فيعود السلوك القديم.
- **المزوّد الاحتياطي** (اختياري، فارغ افتراضياً): مزوّد ونموذج ومفتاح. مزوّد مختلف يرسل نص المحادثة لجهة ثانية ويُذكر في `/privacy` تلقائياً؛ قرار المالك قبل تفعيله.
- **الكلفة:** رسالة التحويل رسالة خدمة داخل النافذة (مدفوعة بعد 1000 شهرياً للرقم)، مرة لكل محادثة في الحادثة.
- **التراجع:** `git revert` ثم النشر. الأعمدة والجدول إضافية يتجاهلها الكود القديم.

## دورة حياة البيانات (مرة واحدة، على مراحل)

«حذف بياناتي» من العميل تفتح طلباً مسجّلاً تلقائياً (بصمة الرقم لا الرقم) مع إيصال فوري، والتنفيذ من سطر الأوامر بعد التحقق من الهوية. وتقليم دوري للجداول الجانبية بعد النسخة الليلية، وتقليم المحادثات القديمة معطّل حتى يقرّر المالك.

1. **قبل النشر (قراءة فقط):** `ssh mafia-prod "docker exec -i -u www-data nuskjo-app php" < implementation/tools/e13_preflight.php` (المستودع الخاص) — أعداد وأقدم التواريخ وما سيُستثنى. ثم نسخة `backup:run --reason=manual --label=pre-e13`. الهجرة `2026_10_14_000014` إضافية (جدولا طلبات الحذف والتجميع الشهري، وعمود erased_at، وصلاحية whatsapp.erase للمدير).
2. **بعد النشر:** `schedule:list` يُظهر whatsapp:usage-rollup وmodel:prune (03:30) وops:prune-cache وwhatsapp:prune (03:45) وwhatsapp:erasure-sla (09:10). التقليم الجانبي يعمل فقط بعد نسخة ليلية ناجحة خلال 26 ساعة.
3. **طلب حذف:** الصفحة **الواتساب › 🗑️ طلبات حذف البيانات** ← «تحقّقت من الهوية» ← على الخادم:
   `docker exec -u www-data nuskjo-app php artisan whatsapp:forget --request=ID` (معاينة بالأعداد ورمز) ثم الأمر نفسه مع `--token=…`. `--client` يمحو بطاقة العميل إن لم يكن له أثر مالي. يبقى ما يرتبط بفاتورة وصف «إيقاف».
4. **حق الاطّلاع:** `php artisan nusk:export-client --client=ID --format=html` (أو `--phone=` / `--request=`) يكتب ملفاً 0600 في storage/app/exports يُحذف بعد 7 أيام. `wa:export-config` لاستنساخ إعدادات البوت بلا أسرار.
5. **تقليم المحادثات (بقرار المالك):** `WA_RETENTION_DAYS=730` في /opt/nuskJO/.env و`up -d`، ثم `whatsapp:prune --dry-run` وراجع الأعداد، ثم العلم «حذف دوري بحسب مدة الاحتفاظ» من إعدادات الواتساب.
- **بعد أي استعادة:** `whatsapp:erasure-reapply` (docs/RESTORE.md).
- **النسخ:** ما يحذفه forget وprune يُطرح من فحص «الانكماش» في النسخة التالية، فلا يفشل النسخ بعد تقليم كبير.
- **التراجع:** `git revert` ثم النشر. الجداول والأعمدة إضافية. المحذوف لا يعود إلا من نسخة احتياطية.

## أرشيف المرفقات (صور العملاء ووثائقهم)

يحفظ الصور والمستندات التي يرسلها العميل (من Media API الرسمي) على قرص خاص قبل أن تنتهي عند ميتا بعد 7 أيام، ويعرضها في الصندوق لمن يملك «عرض وتنزيل مرفقات العملاء» وحده، مع تسجيل كل عرض وتنزيل. يُشحن **مطفأً** (العلم `media_archive`).

1. **قبل النشر (قراءة فقط):** `ssh mafia-prod "docker exec -i -u www-data nuskjo-app php" < implementation/tools/e14_preflight.php` (المستودع الخاص): الأعداد الشهرية وتقدير المساحة ومساحة القرص، ثم مرة مع `-e PROBE=1` لصيغة بصمة ميتا. ثم نسخة `backup:run --reason=manual --label=pre-e14`.
2. **النشر:** الهجرة `2026_10_14_000015` إضافية (أعمدة الحالة، وموافقة العميل، وصلاحية `whatsapp.media` للمدير). عامل جديد `laravel-media` في supervisord: `supervisorctl status` يُظهره RUNNING، و`stat -c '%a' storage/app/wa-media` يعطي 700. `schedule:list` يُظهر `whatsapp:media-prune` (04:00) و`whatsapp:media-sweep` (كل ساعة).
3. **قبل التفعيل (كلها):** اعتماد نص إشعار الحفظ ومدة الاحتفاظ؛ `WA_MEDIA_HOST_MODE=enforce` بعد أسبوع log نظيف (الأرشيف يرفض المضيف الغريب في الحالتين)؛ `whatsapp:forget` مجرَّب على الإنتاج؛ قرار نسخ المجلد (docs/BACKUP.md).
4. **التفعيل على Demo أولاً**، ثم الإنتاج: العلم «أرشفة مرفقات العملاء» من إعدادات الواتساب، و`WA_MEDIA_SHA_MODE=log` أسبوعاً (بطاقة «🗂️ أرشيف المرفقات» تعدّ ما حُفظ وما فشل)، ثم `enforce`. نصّا /privacy و/data-deletion عن المرفقات يظهران وحدهما ما دام العلم مفعّلاً. ثم العلم «إشعار العميل بحفظ ملفاته» بعد اعتماد نصه.
5. **الصلاحية:** امنح `whatsapp.media` من صفحة الأدوار لموظفي الحجوزات المسمّين فقط.
- **السقف:** `WA_MEDIA_QUOTA_MB` (5120): تنبيه عند 80%، وتتوقف الأرشفة الجديدة عند 100% حتى يُرفع أو تُقلَّم القديمة. `WA_MEDIA_RETENTION_DAYS` (120).
- **التراجع:** (أ) إطفاء العلم يوقف الأرشفة الجديدة فوراً ويبقي العرض. (ب) سحب `whatsapp.media` يوقف العرض. (ج) `git revert` ثم النشر؛ الأعمدة إضافية والملفات تبقى حتى يحذفها media-prune. (د) اشتباه تسرّب: أطفئ العلم واسحب الصلاحية وراجع «عرض مرفق عميل» في سجل التدقيق.

## أدوات البوت والمساعد الإداري (E15، على مراحل)

سجل واحد لأدوات البوت والمساعد الإداري، وكل استدعاء يمر بفحص الاسم والإذن ثم التحقق ثم التنفيذ بنطاق المحادثة. ما يتغيّر عند النشر بلا علم:
- `tools_used` في استهلاك البوت صار بصيغة `ok:اسم` أو `denied:اسم` أو `failed:اسم` (التحليلات تقرأ الصيغتين)، ونتائج الأدوات بسقف حجم، والهجرة `2026_10_14_000016` تضيف حجمها (إضافية).
- **التحويل لموظف لا يُطفأ** من الإعدادات ولا من رقم إدارة.
- بطاقة العميل لا تحمل رقم هاتفه ولا رصيده؛ الرصيد من أداة «رصيد ذمّة العميل» وحدها. **قبل النشر:** تأكد أنها مفعّلة في إعدادات البوت.
- ملاحظات العميل التي يحفظها البوت: 300 حرف، والأرقام الطويلة ورموز الجوازات تُحذف منها.
- **المساعد الإداري:** المعاينة يرسلها النظام بنصها، والتنفيذ بكلمة **«تأكيد»** وحدها (تمام ونعم وok لم تعد تأكيداً). أخبر أصحاب أرقام الإدارة بذلك قبل النشر. وأداة جديدة «المحادثات التي تنتظر موظفاً» (صلاحية تُمنح من صفحة أرقام الإدارة).

الأعلام (من إعدادات الواتساب، أو `WA_FEATURE_<KEY>` في البيئة للطوارئ؛ كلها مطفأة):
1. `tool_validation_enforce`: رفض مدخلات الأدوات غير الصالحة. قبله تُسجَّل في سجل الواتساب (`tool.validation`) وتُقصّ.
2. `admin_rbac_enforce`: لا ينفّذ رقم الإدارة إلا ما يملكه المستخدم المرتبط به بدوره، والرقم بلا مستخدم للقراءة فقط. قبله يُسجَّل ما كان سيُرفض في سجل إجراءات أرقام الإدارة بملخص يبدأ `denied_would_be`، وتعرض صفحة أرقام الإدارة التحذيرات. اربط كل رقم بمستخدمه وراجع التحذيرات قبل التفعيل.
3. `admin_readonly`: طوارئ، يوقف كل تعديل من أرقام الإدارة فوراً (`WA_FEATURE_ADMIN_READONLY=true` ثم `up -d`).
- **المراقبة أسبوعاً بعد النشر**، ثم الفرض يوماً بعد يوم: `tool_validation_enforce` ثم `admin_rbac_enforce`.
- **التراجع:** إطفاء العلم فوراً، أو `git revert` ثم النشر. العمود الجديد إضافي.

## مغادرة موظف

بالترتيب، في اليوم نفسه:
1. **المستخدمون:** عطّل الحساب (لا تحذفه، فسجل التدقيق يبقى مرتبطاً به). يخرج من كل أجهزته فوراً، وتتوقف إشعاراته.
2. **أرقام الإدارة في الواتساب:** رقمه المرتبط بحسابه يُعامَل كعميل تلقائياً بعد التعطيل؛ احذفه أو عطّله من الصفحة مع ذلك.
3. **المحادثات المسندة إليه:** انقلها لزميل من صندوق الوارد، وأزل اختصاصاته من «توجيه المواضيع». `whatsapp:fence-report` يُظهر ما بقي موكلاً له.
4. **مفاتيح خارج النظام:** إن كان يملك وصولاً إلى الخادم أو لوحة ميتا أو Cloudflare أو مدير كلمات المرور، اسحبه وغيّر ما رآه من أسرار.
5. إن كان مديراً: تأكد من بقاء مدير نشط آخر (النظام يمنع تعطيل آخر مدير).

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

## مغادرة موظف

بالترتيب، في اليوم نفسه:
1. **المستخدمون:** عطّل الحساب (لا تحذفه، فسجل التدقيق يبقى مرتبطاً به). يخرج من كل أجهزته فوراً، وتتوقف إشعاراته.
2. **أرقام الإدارة في الواتساب:** رقمه المرتبط بحسابه يُعامَل كعميل تلقائياً بعد التعطيل؛ احذفه أو عطّله من الصفحة مع ذلك.
3. **المحادثات المسندة إليه:** انقلها لزميل من صندوق الوارد، وأزل اختصاصاته من «توجيه المواضيع». `whatsapp:fence-report` يُظهر ما بقي موكلاً له.
4. **مفاتيح خارج النظام:** إن كان يملك وصولاً إلى الخادم أو لوحة ميتا أو Cloudflare أو مدير كلمات المرور، اسحبه وغيّر ما رآه من أسرار.
5. إن كان مديراً: تأكد من بقاء مدير نشط آخر (النظام يمنع تعطيل آخر مدير).

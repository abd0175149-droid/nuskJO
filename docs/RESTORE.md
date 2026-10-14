# الاستعادة

لا زر استعادة في الواجهة عن قصد. الاستعادة بسكربت على الخادم عبر SSH، وتنتهي بمراجعة يعتمدها المدير في **الإعدادات › 🛟 النسخ الاحتياطي**. البنية والمفاتيح في [BACKUP.md](BACKUP.md).

## قبل أن تبدأ

- الاستعادة تُرجع القاعدة كلها إلى وقت النسخة: كل ما أُدخل بعدها يخرج من النظام الحي (ويُحفظ في لقطة ما قبل الاستعادة).
- إن كانت المشكلة سجلاً واحداً أو بضعة سجلات، فالإصلاح اليدوي أفضل من الاستعادة.
- نافذة التوقف المتوقعة دقائق. رسائل واتساب التي تصل أثناء التوقف تعيدها ميتا تلقائياً (حتى 7 أيام)، فلا تغيّر رابط الويبهوك ولا رمز التحقق.

## الخطوات

**1. جرّب أولاً بلا أي تغيير:**
```bash
cd /opt/nuskJO
bash scripts/backup/restore.sh --from-spool <اسم اللقطة> --strict --dry-run
```
يفحص النسخة في حاوية بلا شبكة، ثم يعرض جدولاً يقارنها بالقاعدة الحية: كم صفاً في كل جدول، وكم أُنشئ بعد وقت النسخة (أي سيخرج من النظام الحي).

أسماء اللقطات المتاحة: `docker exec -u www-data nuskjo-app ls storage/app/backups`، أو الجدول في صفحة النسخ.

**2. الاستعادة:**
```bash
bash scripts/backup/restore.sh --from-spool <اسم اللقطة> --strict --force [--with-media]
```
- `--force` مطلوب إن كانت في القاعدة الحية بيانات أحدث من النسخة.
- `--with-media` يستعيد ملفات storage/app/public أيضاً. بدونه تبقى الوسائط الحالية كما هي.
- من أرشيف مشفّر: فك التشفير على جهازك (المفضّل) ثم ارفع المجلد بـ scp واستعمل `--dir <المجلد>`. أو على الخادم: `--archive <ملف.age> --identity <ملف المفتاح>` (يُنسخ المفتاح مؤقتاً إلى /dev/shm ويُحذف عند الخروج؛ احذف ملفك أيضاً).

ما يفعله السكربت بالترتيب: فحص النسخة، مقارنة بالحية، إيقاف التطبيق، **لقطة ما قبل الاستعادة** في `~/backups/nuskjo/<الطابع>-pre-restore/`، استبدال القاعدة دفعة واحدة، **الوضع الآمن**، الإقلاع، فحص أن لا جدول أقل من النسخة. ثم يطبع الخطوات التالية بالأسماء الفعلية.

**3. الوضع الآمن (تلقائي):** البوت مطفأ، والإرسال مقفل، والطوابير والجلسات القديمة فارغة (كل المستخدمين يسجّلون الدخول من جديد)، ومراجعة معلّقة. لا يمكن رفع قفل الإرسال من إعدادات البوت قبل اعتماد المراجعة.

**4. استرجاع رسائل واتساب التي وصلت بعد وقت النسخة** (ميتا لا تعيد إرسال ما استلمناه، ولا توجد طريقة لجلبه منها):
```bash
docker cp ~/backups/nuskjo/<الطابع>-pre-restore/database.sqlite nuskjo-app:/tmp/pre-restore.sqlite
docker exec -u root nuskjo-app chown www-data /tmp/pre-restore.sqlite
docker exec -u www-data nuskjo-app php artisan backup:replay-messages /tmp/pre-restore.sqlite --dry-run
docker exec -u www-data nuskjo-app php artisan backup:replay-messages /tmp/pre-restore.sqlite
```
تُدرج الرسائل في محادثاتها بأوقاتها الأصلية، بلا أي رد ولا إرسال، والتشغيل المكرر لا يكرر شيئاً. المحادثات التي فيها رسائل عملاء بلا رد تُعلَّم «تحتاج متابعة».

**5. تقرير ما يُعاد إدخاله يدوياً:**
```bash
docker exec -u www-data nuskjo-app php artisan backup:diff-report /tmp/pre-restore.sqlite
```
أرقام الفواتير والسندات والقيود وطلبات التسعير التي أُنشئت بعد النسخة وليست في النظام الآن. لا تُعاد آلياً لأن روابطها بعملاء ووكلاء قد لا تكون موجودة.

**6. المراجعة والاعتماد** في صفحة النسخ الاحتياطي، بنداً بنداً:
- أرقام الإدارة في المساعد الإداري.
- المستخدمون والأدوار وكلمات المرور ومفاتيح الدخول التي سُحبت بعد وقت النسخة (عادت مع الاستعادة).
- طلبات حذف بيانات العملاء المنفّذة بعد النسخة: نفّذها من جديد.
- إدخال ما في تقرير الفرق.

بعد الاعتماد: ارفع قفل الإرسال وشغّل البوت من **الواتساب › الإعدادات**.

**7. خلال 7 أيام:**
```bash
bash scripts/backup/restore.sh --finalize <الطابع>
```
يشفّر لقطة ما قبل الاستعادة إلى الأرشيف ويحذف نسختها الصريحة.

## الرجوع عن استعادة

لقطة ما قبل الاستعادة لقطة كاملة (فيها manifest.json):
```bash
bash scripts/backup/restore.sh --dir ~/backups/nuskjo/<الطابع>-pre-restore --force [--with-media]
```

## فقدان الخادم كاملاً

1. مضيف جديد عليه docker. انسخ المستودع (مفتاح نشر للقراءة) إلى `/opt/nuskJO`.
2. من مدير كلمات المرور: `.env.production` إلى `/opt/nuskJO/`، و`firebase-auth.json`.
3. `docker compose build nuskjo-app && docker compose up --no-start nuskjo-app` (ينشئ الحاوية والأحجام دون تشغيل).
4. على جهازك: فك أحدث أرشيف `age -d -i <المفتاح> <الملف> | tar -xzf -`، ثم ارفع المجلد الناتج بـ scp.
5. `bash scripts/backup/restore.sh --dir <المجلد> --force --with-media` (الحاوية لا تعمل بعد، فلا مقارنة، و`--force` مطلوب).
6. أكمل الخطوات 4–7 أعلاه، ثم إعداد النسخ على المضيف الجديد كما في BACKUP.md، ووجّه نفق Cloudflare إليه.

`firebase-auth.json` يوضع في الحجم بعد الإقلاع: `docker cp firebase-auth.json nuskjo-app:/var/www/html/storage/app/ && docker exec -u root nuskjo-app chown www-data /var/www/html/storage/app/firebase-auth.json`.

## إن تعطّل شيء في منتصف الاستعادة

السكربت يطبع عند أي فشل بعد إيقاف التطبيق أمرَي الرجوع. القاعدة الأصلية محفوظة في لقطة ما قبل الاستعادة قبل أي تغيير، والاستبدال يتم بملف مؤقت ثم إعادة تسمية، فلا تبقى قاعدة نصف مكتوبة. إن فشل الوضع الآمن، فلا تُقلع التطبيق قبل إصلاحه: الإرسال لن يكون مقفلاً.

## القاعدة في وضع WAL

منذ E07 تعمل القاعدة في وضع WAL (`DB_JOURNAL_MODE=wal` في docker-compose.yml). يبقى بجانب `database.sqlite` ملفان: `database.sqlite-wal` و`database.sqlite-shm`. آخر ما كُتب قد يكون في ملف -wal ولم يُنقل بعد إلى الملف الرئيسي.

- **التحقق:** `docker exec -u www-data nuskjo-app php artisan tinker --execute="echo DB::selectOne('PRAGMA journal_mode')->journal_mode;"` يطبع `wal`.
- **لا تنسخ الملف الحي بـ cp ولا docker cp.** نسخة الملف وحده تفقد ما في -wal، ونسخه مع -wal في لحظتين مختلفتين تعطي قاعدة تالفة. النسخ بـ `php artisan backup:run`، فهو يستعمل `VACUUM INTO` ثم يفحص النسخة بـ `integrity_check`. إن احتجت نسخة يدوية فبأداة sqlite3:
  ```bash
  docker exec -u www-data nuskjo-app sqlite3 /var/www/html/database/data/database.sqlite ".backup '/var/www/html/storage/app/backups/manual-$(date +%F).sqlite'"
  docker exec -u www-data nuskjo-app sqlite3 /var/www/html/storage/app/backups/manual-$(date +%F).sqlite "PRAGMA integrity_check;"
  ```
- **الاستعادة:** `restore.sh` يوقف التطبيق ويحذف `-wal` و`-shm` قبل وضع النسخة، ويعيد الملكية لـ www-data (82:82). إن استبدلت القاعدة يدوياً لأي سبب، فافعل الثلاثة بنفسك والتطبيق متوقف (`docker compose stop nuskjo-app`): ملف -wal قديم بجانب قاعدة جديدة يُطبَّق عليها عند أول فتح فيفسدها.
- **كل أمر artisan أو sqlite3 بـ `docker exec -u www-data`.** إن فتح root القاعدة أنشأ -wal و-shm مملوكين له، فتصير القاعدة للقراءة فقط عند التطبيق. الإقلاع يصحّح الملكية (entrypoint.sh)، لكن الأمر الخاطئ أثناء التشغيل يوقف الكتابة إلى أن يُعاد التشغيل.
- **الرجوع عن WAL** (نادراً): `DB_JOURNAL_MODE=delete` في docker-compose.yml ثم إعادة النشر. يُكتب الوضع في الملف عند أول فتح.

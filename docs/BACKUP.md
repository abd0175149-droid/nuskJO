# النسخ الاحتياطي

ميتا لا تحفظ نسخاً لبياناتنا ولا توفر أي وظيفة نسخ؛ النسخ مسؤوليتنا وحدنا. هذا الملف يشرح كيف تعمل، ومن يحمل المفاتيح، وما يجب فعله عند كل تنبيه. الاستعادة في [RESTORE.md](RESTORE.md)، والنشر في [RELEASE.md](RELEASE.md).

## البنية

```
داخل الحاوية (يومياً 02:30 بتوقيت عمّان، وقبل أي هجرة، وقبل تفريغ البيانات، و«نسخة الآن»)
  php artisan backup:run
  └─ storage/app/backups/<الطابع>-<السبب>/   ← لقطة متسقة + أرشيف الوسائط + manifest.json
       يُحفظ آخر 3، ودائماً آخر نسخة قبل هجرة وآخر نسخة قبل تفريغ

على الخادم (cron، 02:50)
  scripts/backup/collect.sh
  └─ ~/backups/nuskjo/archive/nuskjo-<لقطة>.tar.gz.age   ← مشفّرة بالمفتاح العام، ولا نسخة صريحة على القرص

على جهاز المالك (ويندوز، يومياً 09:00)
  scripts/backup/pull-offsite.ps1
  └─ %USERPROFILE%\Backups\nuskJO\archive\   ← الأرشيفات نفسها، مشفّرة

شهرياً (أول سبت 03:30)
  scripts/backup/drill.sh   ← تمرين استعادة معزول بلا شبكة: فحص، هجرات، دفاتر، فك الأسرار
```

كل لقطة فيها:
- `database.sqlite`: نسخة متسقة بـ `VACUUM INTO` أثناء التشغيل، فُحصت بـ `PRAGMA integrity_check`.
- `storage-public.tar.gz`: ملفات storage/app/public (يُتخطى إن تجاوز السقف، انظر «الحدود»).
- `manifest.json`: الوقت، والسبب، ورقم الإصدار، وأعداد الجداول الأساسية، والهجرات، وبصمة SHA-256 لكل ملف.

النسخة تُرفض قبل كتابتها إن بدت من «القاعدة الخطأ»: جدول أساسي فارغ، أو رسائل أقل من القاعدة الحية، أو انكماش مفاجئ أكثر من 10% عن آخر نسخة (`--allow-shrink` إن كان مقصوداً).

## المفاتيح (مهم)

| ماذا | أين | لماذا |
|---|---|---|
| مفتاح age الخاص (`AGE-SECRET-KEY-...`) | مدير كلمات المرور فقط، ونسخة ثانية خارج الجهاز | يفك كل الأرشيفات. **لا يوضع على الخادم أبداً.** |
| مفتاح age العام (`age1...`) | الخادم: `~/.config/nuskjo-backup/recipients.txt` | يشفّر فقط. يمكن إضافة مستلم ثانٍ (سطر ثانٍ) للضمان. |
| `.env.production` (ومنه `APP_KEY`) | مدير كلمات المرور، منفصلاً عن النسخ | النسخ لا تحويه. بدون `APP_KEY` نفسه لا تُفك رموز واتساب ومفاتيح الذكاء المحفوظة في القاعدة. |
| `storage/app/firebase-auth.json` | مدير كلمات المرور | لا تحويه النسخ. |

تمرين الاستعادة يطبع `app_key_fingerprint` (أول 12 حرفاً من SHA-256 للمفتاح). قارنه بنسختك:

```bash
printf %s 'base64:...المفتاح...' | sha256sum | cut -c1-12
```

## الإعداد لمرة واحدة

على جهاز المالك:
```bash
age-keygen -o nuskjo-backup.key     # احفظ الملف كله في مدير كلمات المرور ثم احذفه
```
السطر `# public key: age1...` هو المفتاح العام.

على الخادم (المستخدم الذي يدير docker، بلا sudo):
```bash
mkdir -p ~/backups/nuskjo/archive ~/.config/nuskjo-backup
chmod 700 ~/backups/nuskjo ~/.config/nuskjo-backup
echo 'age1...' > ~/.config/nuskjo-backup/recipients.txt
bash /opt/nuskJO/scripts/backup/collect.sh --dry-run
bash /opt/nuskJO/scripts/backup/collect.sh
timedatectl                      # ثم أضف أسطر scripts/backup/crontab.example إلى crontab -e
bash /opt/nuskJO/scripts/backup/drill.sh
```
بعد نجاح ما سبق فقط: `BACKUP_ENABLED=true` في `.env.production` (اللقطة اليومية والمراقب)، و`BACKUP_PRE_MIGRATE=true` (نسخة تلقائية قبل كل هجرة).

على جهاز المالك (ويندوز):
```powershell
winget install FiloSottile.age
[Environment]::SetEnvironmentVariable('NUSKJO_BACKUP_HOST', '<اسم مضيف SSH عندك>', 'User')
powershell -NoProfile -ExecutionPolicy Bypass -File <المستودع>\scripts\backup\pull-offsite.ps1
schtasks /Create /SC DAILY /ST 09:00 /TN "nuskJO backup pull" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File <المستودع>\scripts\backup\pull-offsite.ps1"
```
وشهرياً: صدّر المفتاح إلى ملف مؤقت، ثم `pull-offsite.ps1 -TestDecrypt -Identity <الملف>`، ثم احذف الملف.

## الاحتفاظ

| المكان | ما يُحفظ |
|---|---|
| داخل الحاوية | آخر 3 لقطات، وآخر لقطة قبل هجرة، وآخر لقطة قبل تفريغ |
| الخادم وجهاز المالك (المجدولة) | أحدث نسخة لكل يوم لآخر 7 أيام، ولكل أسبوع لآخر 4، ولكل شهر لآخر 6 |
| الخادم وجهاز المالك (الأحداث) | أحدث 5 من كل سبب (قبل هجرة، قبل تفريغ، يدوية، قبل استعادة)، وبحد أقصى 90 يوماً |

الحد الأقصى المعلن في صفحة الخصوصية: 6 أشهر ثم تُتلف. البيانات المحذوفة بطلب عميل قد تبقى في النسخ المشفّرة حتى انقضاء مدتها، ولا تعود إلى النظام الحي: الحذف يُعاد تطبيقه بعد أي استعادة (بند في قائمة المراجعة).

## الحدود (الخادم مشترك بين مشاريع)

| الإعداد | الافتراضي | الأثر |
|---|---|---|
| `BACKUP_MEDIA_MAX_MB` | 1024 | فوقه تُنسخ القاعدة وحدها وتُتخطى الوسائط، مع تنبيه. |
| `BACKUP_DISK_MIN_FREE_MB` | 5120 | لا لقطة إن كانت ستترك أقل من هذا فارغاً. |
| `DISK_WARN_PCT` / `DISK_HARD_PCT` (collect.sh) | 90 / 95 | تنبيه، ثم إيقاف سحب أرشيفات جديدة. |

النسخ التزايدي للوسائط (بالبصمة) شرط قبل إضافة مجلدات وسائط كبيرة إلى `backup.media_dirs`.

**مرفقات العملاء (`storage/app/wa-media`، E14) خارج النسخ عمداً حتى يقرّر المالك:** هي جوازات ووثائق تُحذف بعد مدة احتفاظها (120 يوماً مقترحاً) أو بطلب العميل، ونسخها يمدّ عمرها إلى عمر النسخ (6 أشهر) ويملأ القرص المشترك بلا نسخ تزايدي. عند فقد الخادم تبقى أوصافها في القاعدة (`media_status`) ويُطلب الملف من العميل ثانية. إن قرّر المالك نسخها: النسخ التزايدي أولاً، ثم إضافة المجلد إلى `media_dirs` مع استثناء `.tmp`.

## التنبيهات وما تفعله

كل التنبيهات تصل المدراء عبر الجرس وإشعار الجهاز، مرة واحدة يومياً لكل نوع. صفحة **الإعدادات › 🛟 النسخ الاحتياطي** تعرض الحالة نفسها.

| التنبيه | السبب المحتمل | ما تفعله |
|---|---|---|
| لا نسخة ناجحة منذ أكثر من يوم | `BACKUP_ENABLED` مطفأ، أو المجدول متوقف، أو النسخ يفشل | الجدول في صفحة النسخ يعرض الخطأ. جرّب «نسخة الآن». |
| فشل النسخ الاحتياطي | قرص، أو قاعدة تبدو خاطئة، أو انكماش | اقرأ الرسالة؛ لا تستعمل `--allow-shrink` إلا إن كان الانكماش مقصوداً. |
| لم تُسحب النسخ مشفّرة إلى المضيف | cron على الخادم، أو المفتاح العام، أو القرص | `bash scripts/backup/collect.sh` يدوياً وراجع `~/backups/nuskjo/collect.log`. |
| لم تُنقل نسخة إلى جهاز المالك منذ 8 أيام | الجهاز مطفأ أو المهمة متوقفة | شغّل `pull-offsite.ps1` يدوياً وراجع `pull.log`. |
| لم يُجرَ تمرين منذ 35 يوماً / فشل التمرين | cron، أو نسخة تالفة، أو APP_KEY لا يفك الأسرار | `bash scripts/backup/drill.sh` يدوياً، والتفاصيل في `~/backups/nuskjo/drill-last.log`. |
| القرص ممتلئ | نسخ أو صور docker قديمة | `docker image prune`، وراجع مجلد الأرشيف. |
| النسخ بلا وسائط | الوسائط تجاوزت السقف | ارفع `BACKUP_MEDIA_MAX_MB` إن سمح القرص، أو انقل الوسائط القديمة. |

## مرجع الأوامر

| الأمر | أين | ماذا |
|---|---|---|
| `php artisan backup:run --reason=manual --label=<وسم>` | الحاوية (`docker exec -u www-data nuskjo-app ...`) | لقطة الآن |
| `php artisan backup:verify <مجلد> [--strict]` | أي حاوية من الصورة | فحص لقطة |
| `php artisan backup:watchdog` | الحاوية | فحص التأخر الآن |
| `scripts/backup/collect.sh [--dry-run]` | الخادم | سحب مشفّر وتدوير |
| `scripts/backup/rotate.sh --dir=<مجلد> --dry-run --now=<وقت>` | الخادم | معاينة التدوير |
| `scripts/backup/drill.sh [--rehearse]` | الخادم | تمرين، أو تجربة صورة مرشّحة قبل النشر |
| `scripts/backup/restore.sh ...` | الخادم | الاستعادة ([RESTORE.md](RESTORE.md)) |
| `scripts/backup/pull-offsite.ps1` | جهاز المالك | النقل خارج الخادم |

نفّذ أوامر artisan دائماً بـ `-u www-data`، فتشغيلها باسم root يُنشئ ملفات قاعدة وسجلات لا يقرؤها التطبيق.

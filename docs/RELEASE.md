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

# ميزانية الحمل والقائمة الموحدة للمهام (E24)

nuskJO يعمل على خادم مشترك مع مشاريع أخرى، بقاعدة SQLite واحدة يكتب فيها الطابور والكاش والجلسات. لذلك لكل عامل ولكل مهمة مجدولة كلفة يجب أن تُعرف قبل إضافتها. هذه الوثيقة تملكها E24، و`tests/Feature/Ops/LoadBudgetTest.php` يحرسها.

## الميزانية

| البند | الحد | من أين |
|---|---|---|
| عمّال الطابور كلهم معاً (numprocs) | «4» | `ops.load_budget.max_workers` |
| أقل sleep لكل عامل | «3» ثوانٍ | `ops.load_budget.min_sleep` |
| مهام تعمل كل دقيقة | «6» | `ops.load_budget.max_per_minute` |

العمّال اليوم في `docker/supervisord.conf`:

| البرنامج | الطوابير | العدد | sleep |
|---|---|---|---|
| `laravel-queue` | default | 1 | 3 |
| `laravel-wa` | wa-replies | 1 | 3 |
| `laravel-media` | wa-media-out، wa-media | 1 | 5 |
| `laravel-integrations` | integrations (E40، خامل والعلم `integration_outbox` مطفأ) | 1 | 5 |

ومعهم `reverb` (البث، E28) و`laravel-schedule` (المجدول) بلا طابور.

## قاعدة الإضافة

- **عامل جديد أو عملية جديدة:** يُقاس استهلاك الحاوية قبله وبعده بـ `docker stats --no-stream nuskjo-app` على nuskJO نفسه في ساعة هادئة، ويُرفع الحد هنا بقرار مكتوب.
- **مهمة مجدولة جديدة:** تُضاف إلى الجدول أدناه في التغيير نفسه، وإلا فشل `LoadBudgetTest`. ما يعمل كل دقيقة يدخل في الحد أعلاه.
- الأفضل دائماً: مهمة أقل تكراراً، أو داخل أمر قائم، أو `Schedule::call` في عملية المجدول نفسها بدل عملية PHP جديدة.
- العلم `ops_tick` يجمع أمري الدقيقة (إعادة الويبهوك والمُصالِح) في عملية واحدة `ops:tick`، فيوفّر إقلاع PHP كاملاً كل دقيقة. يُفعَّل بعد قياس على nuskJO.

## القائمة الموحدة للمهام المجدولة

التوقيت UTC ما لم يُذكر «عمّان».

| المهمة | التكرار | الملحمة | الشرط |
|---|---|---|---|
| `ops:schedule-beat` | كل دقيقة (داخل المجدول) | E08 | — |
| `ops:queue-beat:default` | كل دقيقة (وظيفة) | E08 | — |
| `ops:queue-beat:wa-replies` | كل دقيقة (وظيفة) | E08 | — |
| `whatsapp:replay-webhooks` | كل دقيقة | E20 | العلم `ops_tick` مطفأ |
| `whatsapp:reconcile-replies` | كل دقيقة | E07 | العلم `ops_tick` مطفأ |
| `ops:tick` | كل دقيقة | E24 | العلم `ops_tick` |
| `whatsapp:llm-probe` | كل دقيقتين | E12 | القاطع مفتوح |
| `whatsapp:snooze-wake` | كل 5 دقائق | E17 | — |
| `whatsapp:ops-check` | كل 5 دقائق | E24 | — |
| `integrations:redrive` | كل 5 دقائق | E40 | العلم `integration_outbox` |
| `whatsapp:stuck-replies` | كل 10 دقائق | E17 | — |
| `whatsapp:reap-pending` | كل 10 دقائق | E19 | العلم `send_ledger` |
| `whatsapp:inbound-silence` | كل 15 دقيقة | E37 | — |
| `users:sync-last-seen` | كل 15 دقيقة | E27 | — |
| `whatsapp:health-expiry` | كل 15 دقيقة | E22 | العلم `channel_health` |
| `whatsapp:window-reminders` | كل 15 دقيقة | قبل الخطة | — |
| `whatsapp:health` | الدقيقة 7 و37 (الثانية بالعلم `channel_monitor`) | E23 (بدل `whatsapp:health-status` من E08) | `WA_META_CHECKS` |
| `whatsapp:voice-tmp-sweep` | كل ساعة عند :35 | E34 | — |
| `whatsapp:coexistence-history` | كل ساعة عند :35 (`--prune`) | E39 | العلم `coexistence_history_hold` |
| `whatsapp:media-sweep` | كل ساعة | E14 | — |
| `whatsapp:webhook-events-prune` | كل ساعة عند :15 | E20 | — |
| `whatsapp:outbound-media-cleanup` | كل ساعة عند :25 | E32 | العلم `outbound_media_remote_delete` |
| `backup:watchdog` | كل ساعة | E01 | `BACKUP_ENABLED` |
| `queue:prune-failed` | يومياً 00:00 | E08 | — |
| `accounting:reconcile` | يومياً 01:00 | قبل الخطة | — |
| `backup:run` | يومياً 02:30 عمّان | E01 | `BACKUP_ENABLED` |
| `model:prune` | يومياً 03:30 عمّان | E13 | نسخة حديثة |
| `ops:prune-cache` | يومياً 03:40 عمّان | E13 | — |
| `whatsapp:prune` | يومياً 03:45 عمّان | E13 | العلم `retention_purge` ونسخة حديثة |
| `whatsapp:media-prune` | يومياً 04:00 عمّان | E14 | نسخة حديثة |
| `whatsapp:fts-check` | يومياً 04:10 عمّان | E30 | — |
| `whatsapp:blocks-check` | يومياً 04:10 عمّان | E31 | العلم `wa_block` |
| `integrations:prune` | يومياً 04:20 عمّان | E40 | — |
| `whatsapp:doctor` | يومياً 07:50 عمّان (`--budget=20 --store`) | E23 | التنبيه بالعلم `channel_monitor` |
| `whatsapp:graph-version-check` | يومياً 08:00 عمّان | E05 | — |
| `whatsapp:credentials-check` | يومياً 08:00 عمّان | E08 | `WA_META_CHECKS` |
| `whatsapp:meta-analytics` | يومياً 08:20 عمّان | E24 | العلم `meta_analytics_reconcile` |
| `whatsapp:erasure-sla` | يومياً 09:10 عمّان | E13 | — |
| `whatsapp:usage-rollup` | شهرياً يوم 1 03:10 عمّان | E13 | — |
| `users:idle-report` | الأحد 08:47 عمّان | E27 | العلم `idle_report` |
| `whatsapp:qr-sync` | الأحد 04:25 عمّان | E38 | العلم `wa_qr_links` |

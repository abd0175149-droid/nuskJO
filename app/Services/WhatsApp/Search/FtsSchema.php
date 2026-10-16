<?php

namespace App\Services\WhatsApp\Search;

use App\Models\Setting;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\WaLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فهرس البحث في الرسائل (E30): جدول FTS5 بمحتوى خارجي (content='wa_messages') ومُجزِّئ trigram على body_norm،
 * وثلاثة triggers تبقيه مطابقاً للجدول. كل جمل SQL هنا، تستعملها الهجرة وwhatsapp:fts-check --repair بلا تكرار.
 *
 * الحذف النهائي: 'secure-delete' يزيل المحذوف من الفهرس فوراً، لكنه معطوب مع المحتوى الخارجي حتى SQLite 3.46.0
 * (بعد أي حذف يرى integrity-check الفهرس تالفاً؛ أُصلح في 3.46.1). لذلك يُجرَّب فعلياً على جدول مؤقت ولا يُفعَّل
 * إلا إن صحّ، وإلا أعاد afterBulkDelete() كتابة الفهرس ('optimize') بعد كل حذف جماعي (forget وprune)، فلا يبقى
 * أثر للنص المحذوف في الحالتين. بلا FTS5 أو trigram لا يُنشأ الفهرس، والبحث يعمل بـ LIKE على body_norm.
 */
final class FtsSchema
{
    public const TABLE = 'wa_messages_fts';

    /** الحذف والتحديث يمرّران القيمة القديمة نفسها التي فُهرست (COALESCE مثل الإدراج)، وإلا اختلّ فهرس المحتوى الخارجي */
    public const TRIGGERS = [
        'wa_messages_fts_ai' => "CREATE TRIGGER IF NOT EXISTS wa_messages_fts_ai AFTER INSERT ON wa_messages BEGIN
            INSERT INTO wa_messages_fts(rowid, body_norm) VALUES (new.id, COALESCE(new.body_norm, '')); END",
        'wa_messages_fts_ad' => "CREATE TRIGGER IF NOT EXISTS wa_messages_fts_ad AFTER DELETE ON wa_messages BEGIN
            INSERT INTO wa_messages_fts(wa_messages_fts, rowid, body_norm) VALUES ('delete', old.id, COALESCE(old.body_norm, '')); END",
        // حالة الصادر تتحدّث 2-3 مرات لكل رسالة: الفهرس يُلمس فقط حين يتغيّر النص المطبَّع
        'wa_messages_fts_au' => "CREATE TRIGGER IF NOT EXISTS wa_messages_fts_au AFTER UPDATE OF body_norm ON wa_messages
            WHEN old.body_norm IS NOT new.body_norm BEGIN
            INSERT INTO wa_messages_fts(wa_messages_fts, rowid, body_norm) VALUES ('delete', old.id, COALESCE(old.body_norm, ''));
            INSERT INTO wa_messages_fts(rowid, body_norm) VALUES (new.id, COALESCE(new.body_norm, '')); END",
    ];

    private const CREATE = "CREATE VIRTUAL TABLE IF NOT EXISTS wa_messages_fts USING fts5(body_norm, content='wa_messages', content_rowid='id', tokenize='trigram')";

    /** آخر فحص يومي (بطاقة صحة النظام وwhatsapp:doctor) */
    public const LAST_CHECK_KEY = 'wa:fts:last';

    /** @var array{fts5: bool, trigram: bool, secure_delete: bool, version: string}|null */
    private static ?array $support = null;

    private static ?bool $fake = null;

    /** للاختبارات: الفهرس «غير متاح» (أو متاح) بلا لمس القاعدة. null يعيد الفحص الحقيقي */
    public static function fake(?bool $available): void
    {
        self::$fake = $available;
    }

    /** للاختبارات: ما «تدعمه» SQLite (null يعيد التجربة الحقيقية في أول نداء) */
    public static function fakeSupport(?array $support): void
    {
        self::$support = $support === null ? null : $support + ['fts5' => false, 'trigram' => false, 'secure_delete' => false, 'version' => 'test'];
    }

    /**
     * الفهرس والـ triggers الثلاثة موجودة: البحث بـ MATCH، وإلا LIKE. بلا تخزين: استعلامان صغيران لكل بحث،
     * فلا يبقى جواب قديم بعد إصلاح أو إسقاط في عملية طويلة (عامل الطابور).
     */
    public static function available(): bool
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return DB::getDriverName() === 'sqlite' && Schema::hasTable(self::TABLE) && self::missingTriggers() === [];
    }

    /** ما تدعمه SQLite المضمّنة في PHP (يُجرَّب على جداول مؤقتة، مرة لكل عملية) */
    public static function support(): array
    {
        if (self::$support !== null) {
            return self::$support;
        }
        if (DB::getDriverName() !== 'sqlite') {
            return self::$support = ['fts5' => false, 'trigram' => false, 'secure_delete' => false, 'version' => ''];
        }
        $fts5 = self::probe(['CREATE VIRTUAL TABLE temp.wa_fts_probe USING fts5(x)']);
        $trigram = $fts5 && self::probe(["CREATE VIRTUAL TABLE temp.wa_fts_probe USING fts5(x, tokenize='trigram')"]);
        // كل مرحلة في معاملة (أو SAVEPOINT داخل معاملة قائمة): FTS5 يكتب المعلّق إلى القرص عندها، فيصل الحذف
        // إلى مقطع مخزّن كما في الإنتاج (رسالة قديمة تُحذف). بدون ذلك يُحذف من الذاكرة ولا يظهر العطب
        $secure = $trigram && self::probe([
            'CREATE TEMP TABLE wa_fts_probe_c (id INTEGER PRIMARY KEY, x TEXT)',
            "CREATE VIRTUAL TABLE temp.wa_fts_probe USING fts5(x, content='wa_fts_probe_c', content_rowid='id', tokenize='trigram')",
            "INSERT INTO temp.wa_fts_probe(wa_fts_probe, rank) VALUES ('secure-delete', 1)",
            ["INSERT INTO temp.wa_fts_probe_c(id, x) VALUES (1, 'abcdef')", "INSERT INTO temp.wa_fts_probe(rowid, x) VALUES (1, 'abcdef')"],
            ['DELETE FROM temp.wa_fts_probe_c WHERE id = 1', "INSERT INTO temp.wa_fts_probe(wa_fts_probe, rowid, x) VALUES ('delete', 1, 'abcdef')"],
            ["INSERT INTO temp.wa_fts_probe(wa_fts_probe, rank) VALUES ('integrity-check', 1)"],
        ]);

        return self::$support = ['fts5' => $fts5, 'trigram' => $trigram, 'secure_delete' => $secure, 'version' => self::sqliteVersion()];
    }

    /** FTS5 مع trigram: شرط إنشاء الفهرس */
    public static function supported(): bool
    {
        return self::support()['trigram'];
    }

    public static function unsupportedReason(): ?string
    {
        $s = self::support();

        return match (true) {
            DB::getDriverName() !== 'sqlite' => 'driver',
            !$s['fts5'] => 'no_fts5',
            !$s['trigram'] => 'no_trigram',
            default => null,
        };
    }

    public static function sqliteVersion(): string
    {
        return DB::getDriverName() === 'sqlite' ? (string) DB::selectOne('select sqlite_version() as v')->v : '';
    }

    /** @return list<string> */
    public static function missingTriggers(): array
    {
        $present = collect(DB::select("select name from sqlite_master where type = 'trigger' and name in ('".implode("','", array_keys(self::TRIGGERS))."')"))
            ->pluck('name')->all();

        return array_values(array_diff(array_keys(self::TRIGGERS), $present));
    }

    public static function secureDeleteOn(): bool
    {
        if (!Schema::hasTable(self::TABLE.'_config')) {
            return false;
        }

        return (int) DB::table(self::TABLE.'_config')->where('k', 'secure-delete')->value('v') === 1;
    }

    /** الفهرس سليم داخلياً ومطابق لجدول المحتوى (rank=1: يقارن بنصوص wa_messages) */
    public static function integrityOk(): bool
    {
        try {
            DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts, rank) VALUES ('integrity-check', 1)");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{indexed: int, messages: int} صف لكل رسالة في _docsize إن كانت الـ triggers تعمل */
    public static function counts(): array
    {
        return [
            'indexed' => Schema::hasTable(self::TABLE.'_docsize') ? (int) DB::table(self::TABLE.'_docsize')->count() : 0,
            'messages' => (int) DB::table('wa_messages')->count(),
        ];
    }

    /**
     * الإنشاء متساوي الأثر: الجدول، ثم 'secure-delete' إن صحّ، ثم الـ triggers، ثم إعادة البناء من wa_messages.
     * الترتيب داخل معاملة واحدة وبعد الـ triggers، فلا تبقى لحظة يُكتب فيها صف خارج الفهرس.
     */
    public static function install(): void
    {
        DB::transaction(function () {
            DB::statement(self::CREATE);
            self::applySecureDelete();
            foreach (self::TRIGGERS as $sql) {
                DB::statement($sql);
            }
            self::rebuild();
        });
    }

    /**
     * يعيد ما ينقص (الجدول والـ triggers)، ويضبط 'secure-delete' بحسب ما يصح هنا، ثم يعيد البناء. إن تعذّر البناء
     * (فهرس تالف) يُسقط الفهرس ويُنشأ من جديد. يعيد ما فُعل.
     *
     * @return list<string>
     */
    public static function repair(): array
    {
        if (!self::supported()) {
            return ['unsupported'];
        }
        $done = [];
        if (!Schema::hasTable(self::TABLE)) {
            $done[] = 'table';
        }
        foreach (self::missingTriggers() as $name) {
            $done[] = "trigger:{$name}";
        }
        $wanted = self::support()['secure_delete'];
        if (Schema::hasTable(self::TABLE) && self::secureDeleteOn() !== $wanted) {
            $done[] = 'secure_delete:'.($wanted ? 'on' : 'off');
        }
        try {
            self::install();
        } catch (\Throwable $e) {
            WaLog::warning('wa.fts.recreate', ['reason' => class_basename($e)]);
            DB::transaction(function () {
                self::drop();
                self::install();
            });
            $done[] = 'recreated';
        }
        $done[] = 'rebuild';

        return $done;
    }

    public static function rebuild(): void
    {
        DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts) VALUES ('rebuild')");
    }

    /** الـ triggers أولاً: جدول يُسقط وتبقى triggers تشير إليه يُفشل كل إدراج في wa_messages */
    public static function drop(): void
    {
        self::dropTriggers();
        try {
            DB::statement('DROP TABLE IF EXISTS '.self::TABLE);
        } catch (\Throwable $e) {
            // SQLite بلا FTS5 لا تُسقط الجدول الافتراضي؛ بلا triggers لا يمس الكتابة، ويُسقطه إصدار يدعمه
            WaLog::warning('wa.fts.drop_failed', ['reason' => class_basename($e)]);
        }
    }

    /**
     * triggers باقية وSQLite بلا FTS5 (صورة أقدم مثلاً): كل كتابة في wa_messages تفشل بـ «no such module».
     * إسقاطها يعيد الكتابة فوراً، والبحث يعود إلى LIKE.
     */
    public static function dropTriggers(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * بعد حذف جماعي من wa_messages (forget وprune وإعادة تطبيق المحو): مع 'secure-delete' لا شيء يُفعل، وبدونه
     * يُعاد كتابة الفهرس ('optimize') فتُمحى مداخل المحذوف من الملف. يعيد none | secure | optimized.
     */
    public static function afterBulkDelete(): string
    {
        if (!self::available()) {
            return 'none';
        }
        if (self::secureDeleteOn()) {
            return 'secure';
        }
        DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts) VALUES ('optimize')");

        return 'optimized';
    }

    /** نص بلا body_norm (قبل تعبئة E11، أو كُتب بالاستعلام الخام): يُحسب الآن. يعيد عدد الصفوف */
    public static function backfillNorm(): int
    {
        $n = 0;
        DB::table('wa_messages')->whereNotNull('body')->whereNull('body_norm')->select(['id', 'body'])
            ->chunkById(500, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    DB::table('wa_messages')->where('id', $row->id)->update(['body_norm' => TextNormalizer::forSearch($row->body)]);
                    $n++;
                }
            });

        return $n;
    }

    /**
     * يعيد حساب body_norm لكل الرسائل بقواعد TextNormalizer الحالية (بعد رفع VERSION): المتغيّر وحده يُكتب والـ trigger
     * يحدّث الفهرس لكل صف، ثم إعادة بناء احتياطاً، ثم يُسجَّل الإصدار. يعيد عدد الصفوف المتغيّرة.
     */
    public static function renormalize(): int
    {
        $changed = 0;
        DB::table('wa_messages')->select(['id', 'body', 'body_norm'])->chunkById(500, function ($rows) use (&$changed) {
            DB::transaction(function () use ($rows, &$changed) {
                foreach ($rows as $row) {
                    $norm = TextNormalizer::forSearch($row->body);
                    if ($norm !== $row->body_norm) {
                        DB::table('wa_messages')->where('id', $row->id)->update(['body_norm' => $norm]);
                        $changed++;
                    }
                }
            });
        });
        if (self::available()) {
            self::rebuild();
        }
        Setting::set('search_norm_version', (string) TextNormalizer::VERSION);

        return $changed;
    }

    /** الإصدار الذي طُبّعت به الصفوف (null قبل هجرة الفهرس) */
    public static function normVersion(): ?int
    {
        $v = Setting::get('search_norm_version');

        return $v === null ? null : (int) $v;
    }

    /**
     * عيّنة عشوائية: body_norm لا يطابق forSearch(body)، كأن حُدّث body بالاستعلام الخام متجاوزاً خطاف saving.
     * مع $fix يُعاد حسابها (والـ trigger يحدّث الفهرس). يعيد عدد المختلف.
     */
    public static function sampleDrift(int $size = 200, bool $fix = false): int
    {
        $drift = 0;
        foreach (DB::table('wa_messages')->inRandomOrder()->limit($size)->get(['id', 'body', 'body_norm']) as $row) {
            $expected = TextNormalizer::forSearch($row->body);
            if ($row->body_norm !== $expected) {
                $drift++;
                $fix && DB::table('wa_messages')->where('id', $row->id)->update(['body_norm' => $expected]);
            }
        }

        return $drift;
    }

    /** @return array{state: string, at: string, repaired_at: ?string, secure_delete: ?bool}|null */
    public static function lastCheck(): ?array
    {
        try {
            $last = OpsHealth::store()->get(self::LAST_CHECK_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($last) ? $last : null;
    }

    public static function recordCheck(string $state, bool $repaired, ?bool $secureDelete): void
    {
        $previous = self::lastCheck();
        try {
            OpsHealth::store()->forever(self::LAST_CHECK_KEY, [
                'state' => $state,
                'at' => now()->toIso8601String(),
                'repaired_at' => $repaired ? now()->toIso8601String() : ($previous['repaired_at'] ?? null),
                'secure_delete' => $secureDelete,
            ]);
        } catch (\Throwable) {
            // البطاقة ليست حرجة
        }
    }

    private static function applySecureDelete(): void
    {
        $on = self::support()['secure_delete'] ? 1 : 0;
        DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts, rank) VALUES ('secure-delete', {$on})");
    }

    /** @param  list<string|list<string>>  $statements بالترتيب على جداول temp (المصفوفة في معاملة واحدة)، ثم تُسقط دائماً */
    private static function probe(array $statements): bool
    {
        try {
            foreach ($statements as $sql) {
                is_array($sql)
                    ? DB::transaction(fn () => array_map(fn ($one) => DB::statement($one), $sql))
                    : DB::statement($sql);
            }

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            foreach (['DROP TABLE IF EXISTS temp.wa_fts_probe', 'DROP TABLE IF EXISTS temp.wa_fts_probe_c'] as $sql) {
                try {
                    DB::statement($sql);
                } catch (\Throwable) {
                    // جدول افتراضي بلا وحدة لا يُنشأ أصلاً
                }
            }
        }
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * حارس الهجرات (E30.2): كل الهجرات على قاعدة فارغة تُبقي triggers الفهرس. وهجرة لاحقة تعيد بناء wa_messages
 * (->change() أو مفتاح أجنبي أو dropPrimary) تُسقطها بصمت: يكشفها هذا الاختبار في CI، وwhatsapp:fts-check يومياً.
 */
class FtsTriggersSurviveMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const FTS_MIGRATION = '2026_10_14_000030_wa_messages_fts.php';

    public function test_all_migrations_on_an_empty_database_keep_the_triggers(): void
    {
        if (!FtsSchema::supported()) {
            $this->markTestSkipped('SQLite بلا FTS5/trigram: لا فهرس ولا triggers (LIKE)');
        }

        $this->assertSame([], FtsSchema::missingTriggers(), 'هجرة بعد فهرس البحث أسقطت triggers wa_messages');
        $this->assertTrue(Schema::hasTable(FtsSchema::TABLE));
    }

    /** حالة سلبية موثّقة: إعادة بناء الجدول (->change()) تُسقط الـ triggers، والفحص يكشفها والإصلاح يعيدها */
    public function test_rebuilding_wa_messages_drops_the_triggers_and_the_check_restores_them(): void
    {
        if (!FtsSchema::supported()) {
            $this->markTestSkipped('SQLite بلا FTS5/trigram');
        }

        Schema::table('wa_messages', fn (Blueprint $t) => $t->string('status', 20)->nullable()->change());

        $this->assertSame(array_keys(FtsSchema::TRIGGERS), FtsSchema::missingTriggers(), 'SQLite يعيد بناء الجدول عند ->change()');
        $this->assertSame(1, Artisan::call('whatsapp:fts-check'));
        $this->assertSame(0, Artisan::call('whatsapp:fts-check', ['--repair' => true]));
        $this->assertSame([], FtsSchema::missingTriggers());
    }

    /**
     * كل هجرة بعد فهرس البحث تمس wa_messages: إضافة أعمدة فقط. ما يعيد بناء الجدول (change، أو rename، أو drop، أو
     * مفتاح أجنبي أو أساسي) يُرفض ما لم تُصلح الهجرة نفسها الفهرس بعده (FtsSchema::repair()).
     */
    public function test_later_migrations_on_wa_messages_only_add_columns(): void
    {
        $offenders = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (strcmp(basename($file), self::FTS_MIGRATION) <= 0) {
                continue;
            }
            $code = (string) file_get_contents($file);
            if (str_contains($code, "'wa_messages'") && preg_match('/->change\(|renameColumn|dropColumn|->foreign\(|dropForeign|dropPrimary|->primary\(/', $code)
                && !str_contains($code, 'FtsSchema::repair')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'هجرة تعيد بناء wa_messages بعد فهرس البحث: أضف أعمدة فقط، أو استدعِ FtsSchema::repair() في آخرها');
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** هجرة E29 إضافية ومتكررة الأمان: تشغيلها مرة ثانية لا يفشل، والفهرس الجزئي للملاحظة الواحدة موجود، ولا تُدرج تصنيفات */
class LabelsPinsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_migration_twice_is_safe_and_the_partial_index_exists(): void
    {
        $migration = require database_path('migrations/2026_10_14_000029_wa_labels_and_thread_pins.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('wa_bot_settings', ['llm_terms_confirmed_at', 'llm_terms_confirmed_by', 'llm_terms_fp']));
        $sql = DB::table('sqlite_master')->where('type', 'index')->where('name', 'wa_thread_pins_one_note')->value('sql');
        $this->assertStringContainsString("WHERE kind = 'note'", (string) $sql);
        $this->assertTrue(Schema::hasIndex('wa_conversation_labels', ['conversation_id', 'label_id'], 'unique'));
        $this->assertSame(0, DB::table('wa_labels')->count(), 'القائمة الأولى يُدخلها المدير');
    }
}

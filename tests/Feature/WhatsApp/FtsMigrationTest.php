<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Setting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * هجرة فهرس البحث (E30.1): متكررة الأمان، والـ triggers الثلاثة تبقي FTS5 مطابقاً لـ wa_messages، وتحديث الحالة
 * وحده لا يلمس الفهرس، و'secure-delete' يُفعَّل فقط حيث يصح فعلاً في SQLite المضمّنة.
 */
class FtsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_14_000030_wa_messages_fts.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (!FtsSchema::supported()) {
            $this->markTestSkipped('SQLite '.FtsSchema::sqliteVersion().' بلا FTS5/trigram: البحث بـ LIKE (MessageSearchFallbackTest)');
        }
    }

    private function indexed(string $term): array
    {
        return DB::table('wa_messages_fts')->whereRaw('wa_messages_fts MATCH ?', ['"'.$term.'"'])->pluck('rowid')->map(fn ($id) => (int) $id)->all();
    }

    /** بصمة ما يخزّنه FTS5: أي كتابة في الفهرس تغيّرها */
    private function indexFingerprint(): string
    {
        return md5(json_encode([DB::table('wa_messages_fts_data')->orderBy('id')->get()->map(fn ($r) => [$r->id, bin2hex($r->block)]),
            DB::table('wa_messages_fts_idx')->orderBy('segid')->orderBy('term')->get(), DB::table('wa_messages_fts_docsize')->orderBy('id')->get()]));
    }

    public function test_running_twice_keeps_one_index_with_its_triggers_and_settings(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('wa_messages_fts'));
        $this->assertSame([], FtsSchema::missingTriggers());
        $this->assertTrue(FtsSchema::available());
        $this->assertSame(FtsSchema::support()['secure_delete'], FtsSchema::secureDeleteOn(),
            "'secure-delete' مفعّل حيث يصح فقط (SQLite ".FtsSchema::sqliteVersion().')');
        $this->assertTrue(FtsSchema::integrityOk());
        $this->assertSame('1', Setting::get('search_norm_version'));
    }

    public function test_secure_delete_is_on_where_sqlite_handles_it(): void
    {
        if (!FtsSchema::support()['secure_delete']) {
            $this->markTestSkipped("'secure-delete' معطوب مع المحتوى الخارجي في SQLite ".FtsSchema::sqliteVersion().' (يُصلح في 3.46.1): البديل optimize بعد الحذف');
        }

        $this->assertTrue(FtsSchema::secureDeleteOn());
    }

    public function test_rows_written_through_eloquent_follow_the_index(): void
    {
        $conv = WaConversation::factory()->create();
        $m = WaMessage::factory()->create(['conversation_id' => $conv->id, 'body' => 'نريد العُمرة في رمضان']);

        $this->assertSame([$m->id], $this->indexed('عمره'));

        $m->update(['body' => 'بل نريد تأشيرة زيارة']);
        $this->assertSame([], $this->indexed('عمره'));
        $this->assertSame([$m->id], $this->indexed('تاشيره'));

        $m->delete();
        $this->assertSame([], $this->indexed('تاشيره'));
        $this->assertTrue(FtsSchema::integrityOk());
    }

    public function test_a_status_update_does_not_touch_the_index(): void
    {
        $m = WaMessage::factory()->outbound()->create(['conversation_id' => WaConversation::factory()->create()->id, 'body' => 'وصلت الحجوزات']);
        $before = $this->indexFingerprint();

        $m->update(['status' => 'delivered']);
        DB::table('wa_messages')->where('id', $m->id)->update(['status' => 'read', 'updated_at' => now()]);

        $this->assertSame($before, $this->indexFingerprint());
        $m->update(['body' => 'نص مختلف تماماً']);
        $this->assertNotSame($before, $this->indexFingerprint());
    }

    public function test_rows_without_a_normalised_body_are_filled_before_the_rebuild(): void
    {
        $conv = WaConversation::factory()->create();
        $id = DB::table('wa_messages')->insertGetId(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'customer',
            'msg_type' => 'text', 'body' => 'زعفران أصلي', 'body_norm' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->indexed('زعفران'));

        (require database_path(self::MIGRATION))->up();

        $this->assertSame('زعفران اصلي', DB::table('wa_messages')->where('id', $id)->value('body_norm'));
        $this->assertSame([$id], $this->indexed('زعفران'));
    }

    public function test_down_removes_index_and_triggers_and_messages_still_save(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();

        $this->assertFalse(Schema::hasTable('wa_messages_fts'));
        $this->assertSame(array_keys(FtsSchema::TRIGGERS), FtsSchema::missingTriggers());
        $this->assertFalse(FtsSchema::available());
        WaMessage::factory()->create(['conversation_id' => WaConversation::factory()->create()->id, 'body' => 'بعد التراجع']);

        $migration->up();
        $this->assertSame(1, count($this->indexed('التراجع')), 'إعادة البناء تفهرس ما كُتب أثناء غياب الفهرس');
    }
}

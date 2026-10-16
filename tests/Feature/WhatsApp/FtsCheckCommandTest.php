<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * whatsapp:fts-check (E30.2): يكشف trigger ناقصاً واختلال الفهرس والتطبيع، ويخرج بـ 1، ومع --repair يصلح ويدقّق
 * ويُشعر المدراء مرة في اليوم. whatsapp:search-renormalize بعد رفع إصدار التطبيع، وwhatsapp:verify-schema يرى الفهرس.
 */
class FtsCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!FtsSchema::supported()) {
            $this->markTestSkipped('SQLite '.FtsSchema::sqliteVersion().' بلا FTS5/trigram');
        }
        $conv = WaConversation::factory()->create();
        WaMessage::factory()->count(3)->create(['conversation_id' => $conv->id]);
    }

    protected function tearDown(): void
    {
        FtsSchema::fakeSupport(null);
        parent::tearDown();
    }

    private function check(array $options = []): array
    {
        $code = Artisan::call('whatsapp:fts-check', $options + ['--json' => true]);

        return [$code, json_decode(trim(Artisan::output()), true)];
    }

    public function test_a_healthy_index_passes_and_is_recorded_for_the_health_card(): void
    {
        [$code, $report] = $this->check();

        $this->assertSame(0, $code);
        $this->assertSame([], $report['problems']);
        $this->assertSame(['indexed' => 3, 'messages' => 3], $report['counts']);
        $this->assertSame('ok', FtsSchema::lastCheck()['state']);
    }

    public function test_a_missing_trigger_fails_and_repair_restores_it_with_one_audit_row_and_one_notice(): void
    {
        $admin = User::factory()->withPermissions([], 'admin')->create();
        DB::statement('DROP TRIGGER wa_messages_fts_ai');
        WaMessage::factory()->create(['conversation_id' => WaConversation::factory()->create()->id, 'body' => 'رسالة فاتها الفهرس']);

        [$code, $report] = $this->check();
        $this->assertSame(1, $code);
        $this->assertContains('missing_trigger:wa_messages_fts_ai', $report['problems']);
        $this->assertContains('count_mismatch', $report['problems']);
        $this->assertSame('failed', FtsSchema::lastCheck()['state']);

        [$code, $report] = $this->check(['--repair' => true]);
        $this->assertSame(0, $code, json_encode($report['problems'] ?? null));
        $this->assertSame([], FtsSchema::missingTriggers());
        $this->assertSame(['indexed' => 4, 'messages' => 4], $report['counts']);
        $this->assertContains('trigger:wa_messages_fts_ai', $report['actions']);
        $this->assertSame('repaired', FtsSchema::lastCheck()['state']);

        $audit = AuditLog::where('action', AuditAction::WaSearchIndexRepair->value)->sole();
        $this->assertSame('warn', $audit->severity);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', '🔎 فهرس البحث أُصلح')->count());

        DB::statement('DROP TRIGGER wa_messages_fts_ad');
        $this->check(['--repair' => true]);
        $this->assertSame(1, Notification::where('title', '🔎 فهرس البحث أُصلح')->count(), 'إشعار واحد في اليوم');
        $this->assertSame(2, AuditLog::where('action', AuditAction::WaSearchIndexRepair->value)->count());
    }

    public function test_a_body_written_around_eloquent_is_caught_by_the_sample_and_fixed(): void
    {
        $m = WaMessage::first();
        DB::table('wa_messages')->where('id', $m->id)->update(['body' => 'نص كُتب بالاستعلام الخام']);

        [$code, $report] = $this->check();
        $this->assertSame(1, $code);
        $this->assertContains('norm_drift', $report['problems']);

        $this->check(['--repair' => true]);
        $this->assertSame('نص كتب بالاستعلام الخام', DB::table('wa_messages')->where('id', $m->id)->value('body_norm'));
        $this->assertSame([$m->id], DB::table('wa_messages_fts')->whereRaw('wa_messages_fts MATCH ?', ['"الخام"'])->pluck('rowid')->map(fn ($v) => (int) $v)->all());
    }

    public function test_secure_delete_follows_what_this_sqlite_supports(): void
    {
        $works = FtsSchema::support()['secure_delete'];
        DB::statement("INSERT INTO wa_messages_fts(wa_messages_fts, rank) VALUES ('secure-delete', ".($works ? 0 : 1).')');

        [$code, $report] = $this->check();
        $this->assertSame(1, $code);
        $this->assertContains('secure_delete', $report['problems']);

        $this->check(['--repair' => true]);
        $this->assertSame($works, FtsSchema::secureDeleteOn());
    }

    public function test_triggers_left_without_fts5_are_dropped_so_messages_keep_saving(): void
    {
        FtsSchema::fakeSupport(['fts5' => false, 'trigram' => false]);

        [$code, $report] = $this->check();
        $this->assertSame(1, $code);
        $this->assertSame('unsupported', $report['state']);
        $this->assertContains('orphan_triggers', $report['problems']);

        [$code] = $this->check(['--repair' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(array_keys(FtsSchema::TRIGGERS), FtsSchema::missingTriggers());
    }

    public function test_renormalize_applies_new_rules_to_old_rows(): void
    {
        $m = WaMessage::first();
        DB::table('wa_messages')->where('id', $m->id)->update(['body' => 'العُمرة', 'body_norm' => 'قديم']);
        Setting::set('search_norm_version', '0');

        $this->assertSame(0, Artisan::call('whatsapp:search-renormalize'));
        $this->assertSame('العمره', DB::table('wa_messages')->where('id', $m->id)->value('body_norm'));
        $this->assertSame('1', Setting::get('search_norm_version'));
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaSearchIndexRepair->value)->sole()->changes['rows']);
        $this->assertTrue(FtsSchema::integrityOk());

        Artisan::call('whatsapp:search-renormalize');
        $this->assertStringContainsString('مطبَّق', Artisan::output());
    }

    public function test_a_stale_normalisation_version_is_a_problem_the_repair_fixes(): void
    {
        Setting::set('search_norm_version', '0');

        [$code, $report] = $this->check();
        $this->assertSame(1, $code);
        $this->assertContains('norm_version', $report['problems']);

        [$code] = $this->check(['--repair' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('1', Setting::get('search_norm_version'));
    }

    public function test_verify_schema_reports_a_missing_trigger(): void
    {
        DB::statement('DROP TRIGGER wa_messages_fts_au');

        $this->assertSame(1, Artisan::call('whatsapp:verify-schema', ['--json' => true]));
        $out = Artisan::output();
        $report = json_decode(substr($out, 0, strrpos($out, '}') + 1), true);
        $this->assertSame('gaps', $report['search_index']['state']);
        $this->assertSame(['wa_messages_fts_au'], $report['search_index']['missing_triggers']);
        File::delete(File::glob(storage_path('app/reports/wa-schema-*.json')));
    }
}

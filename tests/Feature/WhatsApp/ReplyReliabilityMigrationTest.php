<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** هجرة موثوقية الرد متكررة الأمان، وتعبئتها لا تُنتج ردوداً بأثر رجعي، والأعلام تُبدَّل وقت التشغيل. */
class ReplyReliabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marks_answered_inbound_only_and_runs_twice(): void
    {
        $answered = WaConversation::factory()->create();
        $in1 = WaMessage::factory()->inbound()->create(['conversation_id' => $answered->id]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $answered->id]);
        $waiting = WaConversation::factory()->create();
        $in2 = WaMessage::factory()->inbound()->create(['conversation_id' => $waiting->id]);
        $out2 = WaMessage::factory()->outbound()->create(['conversation_id' => $waiting->id]);
        $in3 = WaMessage::factory()->inbound()->create(['conversation_id' => $waiting->id]);
        $silent = WaConversation::factory()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $silent->id]);
        DB::table('wa_conversations')->update(['bot_answered_in_id' => null]);

        $migration = require database_path('migrations/2026_10_14_000007_whatsapp_reply_reliability.php');
        $migration->up();
        $migration->up();

        $this->assertSame($in1->id, $answered->fresh()->bot_answered_in_id);
        $this->assertSame($in2->id, $waiting->fresh()->bot_answered_in_id, 'الوارد بعد آخر صادر يبقى معلّقاً كما في البوابة القديمة');
        $this->assertLessThan($in3->id, $waiting->fresh()->bot_answered_in_id);
        $this->assertGreaterThan($in2->id, $out2->id);
        $this->assertNull($silent->fresh()->bot_answered_in_id);
    }

    public function test_flags_have_defaults_and_can_be_switched_at_runtime(): void
    {
        $settings = WaBotSetting::current();
        $this->assertTrue($settings->flag('watermark'));
        $this->assertFalse($settings->flag('reconcile'));
        $this->assertSame(10, (int) $settings->fresh()->max_reply_age_minutes);

        $this->artisan('whatsapp:reliability', ['flag' => 'reconcile', 'state' => 'on'])->assertExitCode(0);
        $this->artisan('whatsapp:reliability', ['flag' => 'nonsense', 'state' => 'on'])->assertExitCode(1);

        $this->assertTrue(WaBotSetting::current()->flag('reconcile'));
        $this->assertTrue(WaBotSetting::current()->flag('watermark'), 'بقية الأعلام على افتراضيها');
    }
}

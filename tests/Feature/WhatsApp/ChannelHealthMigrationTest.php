<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * هجرة صحة القناة (E22): تشغيلها مرتين لا يفشل، والقفل الآلي القديم ينتقل إلى BLOCKED (legacy) دون أي لحظة إرسال،
 * واليدوي والفارغ والاستعادة تبقى في wa_suspended. والتراجع يعيد الحجب قفلاً ولا يرفعه.
 */
class ChannelHealthMigrationTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_000022_wa_channel_health_state.php');
    }

    private function send(): array
    {
        return WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'مرحباً', 'system', sendKey: SendKey::optout('wamid.MIG.'.uniqid()));
    }

    public function test_running_twice_is_harmless_and_creates_the_table_and_columns(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('wa_account_events'));
        $this->assertTrue(Schema::hasColumns('wa_bot_settings', ['wa_health', 'wa_health_reason', 'wa_health_source', 'wa_health_until',
            'wa_health_checked_at', 'wa_health_changed_at', 'wa_health_unblock_suggested', 'wa_health_meta']));
        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_an_automatic_lock_moves_to_blocked_and_sending_stays_refused_before_and_after(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        WaBotSetting::current()->forceFill(['wa_suspended_source' => 'meta_event', 'wa_suspended_reason' => 'DISABLED_UPDATE'])->save();
        Http::fake();
        $this->assertSame(WhatsAppChannel::ERR_SUSPENDED, $this->send()['code']);
        $version = (int) WaBotSetting::current()->fresh()->settings_version;

        $this->migration()->up();
        $this->migration()->up();

        $s = WaBotSetting::current()->fresh();
        $this->assertSame(['BLOCKED', 'legacy'], [$s->wa_health, $s->wa_health_source]);
        $this->assertStringContainsString('قفل آلي سابق: DISABLED_UPDATE', $s->wa_health_reason);
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertNull($s->wa_suspended_source);
        $this->assertSame($version + 1, (int) $s->settings_version);
        $this->assertSame(WhatsAppChannel::ERR_CHANNEL_BLOCKED, $this->send()['code']);
        Http::assertNothingSent();
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaHealthChange->value)->count());
    }

    public function test_a_graph_error_lock_moves_too(): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        WaBotSetting::current()->forceFill(['wa_suspended_source' => 'graph_error', 'wa_suspended_reason' => 'رمز ميتا 368'])->save();

        $this->migration()->up();

        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    /** @return array<string, array{0: ?string}> */
    public static function humanLocks(): array
    {
        return ['manual' => ['manual'], 'empty (old, unknown)' => [null], 'restore safe mode' => ['restore']];
    }

    #[DataProvider('humanLocks')]
    public function test_manual_unknown_and_restore_locks_stay_as_they_are(?string $source): void
    {
        $this->configureChannel(['wa_suspended' => true]);
        WaBotSetting::current()->forceFill(['wa_suspended_source' => $source])->save();

        $this->migration()->up();

        $s = WaBotSetting::current()->fresh();
        $this->assertTrue((bool) $s->wa_suspended);
        $this->assertSame($source, $s->wa_suspended_source);
        $this->assertSame('UNKNOWN', $s->wa_health);
    }

    public function test_rolling_back_turns_a_block_into_a_lock_instead_of_lifting_it(): void
    {
        $this->configureChannel();
        WaBotSetting::current()->forceFill(['wa_health' => 'BLOCKED', 'wa_health_reason' => 'ban:DISABLE'])->save();

        $this->migration()->down();
        SettingsWriter::flushColumns();

        $s = WaBotSetting::current()->fresh();
        $this->assertTrue((bool) $s->wa_suspended);
        $this->assertSame('meta_event', $s->wa_suspended_source);
        $this->assertFalse(Schema::hasTable('wa_account_events'));

        $this->migration()->up();
        SettingsWriter::flushColumns();
    }
}

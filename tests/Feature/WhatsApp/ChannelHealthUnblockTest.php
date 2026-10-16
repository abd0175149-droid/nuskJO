<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Events\WhatsApp\ChannelHealthChanged;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * رفع الحجب يدوياً (E22): سبب وكتابة «رفع» وتدقيق، ويُرفض إن كانت ميتا ما زالت تحجب (فحص حديث خلال 10 دقائق، وإلا
 * يُفحص الآن). لا رفع للرمز الباطل من هنا، والمفتاح الطارئ لا يرفع الحجب. كل المسارات لصاحب whatsapp.settings وحده.
 */
class ChannelHealthUnblockTest extends TestCase
{
    use ConfiguresWhatsApp;
    use PatchesWhatsAppSettings;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.channel_health' => true, 'ops.meta_checks' => true]);
        $this->configureChannel();
        $this->owner = User::factory()->admin()->create(['name' => 'المدير المناوب']);
        WaBotSetting::current()->forceFill(['wa_health' => 'BLOCKED', 'wa_health_source' => 'webhook', 'wa_health_reason' => 'ban:DISABLE'])->save();
    }

    private function lastPoll(string $can, int $minutesAgo = 1, ?string $solution = null): void
    {
        WaBotSetting::current()->forceFill(['wa_health_meta' => array_filter([
            'last_poll' => ['can_send_message' => $can, 'at' => now()->subMinutes($minutesAgo)->toIso8601String()],
            'possible_solution' => $solution,
        ])])->save();
    }

    private function unblock(array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/whatsapp/channel-health/unblock', $data + [
            'reason' => 'راجعنا بريد ميتا ورُفع القيد', 'confirm_text' => 'رفع',
        ]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/'.$name)), true);
    }

    public function test_reason_and_typed_confirmation_are_required(): void
    {
        $this->unblock(['confirm_text' => ''])->assertStatus(422)->assertJsonValidationErrors('confirm_text');
        $this->unblock(['confirm_text' => 'نعم'])->assertStatus(422)->assertJsonValidationErrors('confirm_text');
        $this->unblock(['reason' => 'قصير'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_a_recent_check_that_still_says_blocked_refuses_with_metas_solution(): void
    {
        $this->lastPoll('BLOCKED', 3, 'Visit business settings and start verification.');
        Http::fake();

        $this->unblock()->assertStatus(409)
            ->assertJsonPath('message', 'ميتا ما زالت تحجب الرقم — الرفع لن يفيد')
            ->assertJsonPath('possible_solution', 'Visit business settings and start verification.');

        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
        Http::assertNothingSent();
    }

    public function test_an_old_check_is_redone_now_and_a_clear_answer_lifts_with_an_audit_row(): void
    {
        $this->lastPoll('BLOCKED', 30);
        Http::fake(['graph.facebook.com/*' => Http::response($this->fixture('health_available.json'))]);

        $this->unblock()->assertOk()->assertJsonPath('channelHealth.state', 'AVAILABLE');

        $s = WaBotSetting::current()->fresh();
        $this->assertSame(['AVAILABLE', 'manual'], [$s->wa_health, $s->wa_health_source]);
        $this->assertStringContainsString('راجعنا بريد ميتا', $s->wa_health_reason);
        $row = AuditLog::where('action', AuditAction::WaHealthUnblock->value)->sole();
        $this->assertSame([$this->owner->id, 'warn', 'راجعنا بريد ميتا ورُفع القيد', 'BLOCKED'], [$row->user_id, $row->severity, $row->changes['reason'], $row->changes['from']]);
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaHealthChange->value)->count());
        $this->assertStringContainsString('المدير المناوب', Notification::where('title', '✅ رُفع حجب إرسال الواتساب')->value('body'));
        Http::assertSentCount(1);
    }

    public function test_a_limited_answer_lifts_to_limited(): void
    {
        $this->lastPoll('LIMITED');

        $this->unblock()->assertOk();

        $this->assertSame('LIMITED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_an_unreachable_check_refuses(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

        $this->unblock()->assertStatus(409);

        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_only_a_block_can_be_lifted_here(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'TOKEN_INVALID'])->save();
        $this->unblock()->assertStatus(422)->assertJsonValidationErrors('state');

        WaBotSetting::current()->forceFill(['wa_health' => 'AVAILABLE'])->save();
        $this->unblock()->assertStatus(422);
    }

    public function test_a_block_moved_from_the_old_suspension_can_be_lifted_with_the_flag_off(): void
    {
        config(['features.env.channel_health' => false]);
        WaBotSetting::current()->forceFill(['wa_health_source' => 'legacy'])->save();
        $this->lastPoll('AVAILABLE');

        $this->unblock()->assertOk();

        $this->assertSame('AVAILABLE', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_the_kill_switch_does_not_lift_a_block(): void
    {
        $this->killSwitch($this->owner, 'on')->assertOk();
        $this->killSwitch($this->owner, 'off')->assertOk();

        $s = WaBotSetting::current()->fresh();
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertSame('BLOCKED', $s->wa_health);
    }

    public function test_the_kill_switch_reports_the_machine_state_on_the_other_side_once_per_change(): void
    {
        Event::fake([ChannelHealthChanged::class]);

        $this->killSwitch($this->owner, 'on')->assertOk();
        $this->killSwitch($this->owner, 'off')->assertOk();

        Event::assertDispatchedTimes(ChannelHealthChanged::class, 2);
        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->source] === ['BLOCKED', 'SUSPENDED_MANUAL', 'manual']);
        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->reasonCode] === ['SUSPENDED_MANUAL', 'BLOCKED', 'manual']);
    }

    public function test_acknowledge_clears_the_suggestion_only_and_is_audited(): void
    {
        WaBotSetting::current()->forceFill(['wa_health_unblock_suggested' => true])->save();

        $this->actingAs($this->owner)->postJson('/api/whatsapp/channel-health/acknowledge')->assertOk()
            ->assertJsonPath('channelHealth.unblock_suggested', false)->assertJsonPath('channelHealth.state', 'BLOCKED');

        $this->assertSame(1, AuditLog::where('action', AuditAction::WaHealthAck->value)->count());
    }

    public function test_every_route_needs_whatsapp_settings(): void
    {
        $viewer = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'staff')->create();
        $event = WaAccountEvent::create(['field' => 'security', 'event' => 'PIN_CHANGED', 'severity' => 'warn', 'effect' => 'alert',
            'fingerprint' => str_repeat('a', 64), 'payload' => ['event' => 'PIN_CHANGED'], 'created_at' => now()]);

        $this->actingAs($viewer)->getJson('/api/whatsapp/channel-health')->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/whatsapp/channel-health/events/{$event->id}")->assertForbidden();
        $this->unblock([], $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/whatsapp/channel-health/acknowledge')->assertForbidden();
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_one_events_payload_is_shown_to_the_manager_after_redaction(): void
    {
        config(['services.whatsapp.waba_id' => '102290129340398']);
        WaBotSetting::current()->forceFill(['wa_display_phone' => '15550783881'])->save();
        $this->postMetaWebhook('security_pin_reset.json')->assertOk();
        $event = WaAccountEvent::sole();

        $res = $this->actingAs($this->owner)->getJson("/api/whatsapp/channel-health/events/{$event->id}")->assertOk();

        $this->assertSame('PIN_RESET_REQUEST', $res->json('payload.event'));
        $this->assertSame(['id', 'field', 'event', 'severity', 'effect', 'occurred_at', 'created_at', 'payload'], array_keys($res->json()));
        // الحمولة لا تُرسل في قائمة البطاقة، ومفتاح سري فيها يُحجب
        $this->assertArrayNotHasKey('payload', $this->actingAs($this->owner)->getJson('/api/whatsapp/channel-health')->json('channelHealth.events.0'));
        $event->forceFill(['payload' => ['event' => 'X', 'access_token' => 'EAAsecretvalue123']])->save();
        $this->assertSame('[redacted]', $this->actingAs($this->owner)->getJson("/api/whatsapp/channel-health/events/{$event->id}")->json('payload.access_token'));
    }
}

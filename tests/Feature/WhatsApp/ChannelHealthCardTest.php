<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\MetaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * بطاقة «صحة القناة» وشارة الصندوق (E22): props البطاقة بلا رمز ولا سر ولا معرّفات كيانات ولا حمولة خام، وprops الصندوق
 * لمن يملك whatsapp.view وحده بلا السبب التفصيلي ولا رمز الخطأ.
 */
class ChannelHealthCardTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.channel_health' => true, 'ops.meta_checks' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel(['wa_token' => 'EAAsecretTokenValue1234567890'])->forceFill(['wa_display_phone' => '15550783881'])->save();
    }

    private function blockedByMeta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/health_blocked.json')), true))]);
        app(MetaHealth::class)->channel();
        $this->postMetaWebhook('security_pin_reset.json')->assertOk();
    }

    public function test_the_settings_card_carries_the_state_without_secrets_ids_or_raw_payloads(): void
    {
        $this->blockedByMeta();
        $owner = User::factory()->admin()->create();

        $response = $this->actingAs($owner)->get('/whatsapp/settings')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('channelHealth.state', 'BLOCKED')
            ->where('channelHealth.label', 'محجوبة من ميتا')
            ->where('channelHealth.source', 'poll')
            ->where('channelHealth.blocks', true)
            ->where('channelHealth.meta.quality_rating', 'RED')
            ->where('channelHealth.events.0.event', 'PIN_RESET_REQUEST')
            ->etc());
        $card = json_encode($response->viewData('page')['props']['channelHealth'], JSON_UNESCAPED_UNICODE);
        foreach (['EAAsecretTokenValue', '102290129340398', '106540352242922', '1234567890', 'payload', 'fingerprint'] as $leak) {
            $this->assertStringNotContainsString($leak, $card, $leak);
        }
    }

    public function test_the_inbox_shows_a_generic_badge_without_reason_or_code_to_staff(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'BLOCKED', 'wa_health_reason' => 'poll:141010 — The Business has not passed business verification.',
            'wa_health_until' => now()->addDay(), 'wa_last_error' => ['meta_code' => 368, 'error_code' => 'ACCOUNT_RESTRICTED', 'category' => 'integrity', 'at' => now()->toIso8601String()]])->save();
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();

        $response = $this->actingAs($staff)->get('/whatsapp/inbox')->assertOk();

        $status = $response->viewData('page')['props']['status'];
        $this->assertSame(['BLOCKED', 'محجوبة من ميتا', true], [$status['health'], $status['health_label'], $status['health_blocks']]);
        $this->assertNotNull($status['health_until']);
        $json = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        foreach (['141010', 'business verification', 'ACCOUNT_RESTRICTED', 'channelHealth'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_the_inbox_summary_card_shows_the_channel_as_blocked(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'BLOCKED'])->save();
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();

        $this->assertSame('blocked', \App\Services\WhatsApp\InboxSummary::for($staff)['channel']['state']);
    }

    public function test_the_settings_page_has_no_card_data_for_staff(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();

        $this->actingAs($staff)->get('/whatsapp/settings')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/whatsapp/meta-check')->assertForbidden();
    }

    public function test_check_now_returns_the_card_and_counts_as_human(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'TOKEN_INVALID'])->save();
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '', 'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management'], 'expires_at' => 0]]),
            'graph.facebook.com/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/health_available.json')), true)),
        ]);
        $owner = User::factory()->admin()->create();

        $this->actingAs($owner)->postJson('/api/whatsapp/meta-check')->assertOk()->assertJsonPath('channelHealth.state', 'AVAILABLE');
    }

    public function test_the_events_list_is_limited_to_thirty_rows(): void
    {
        foreach (range(1, 35) as $i) {
            WaAccountEvent::create(['field' => 'account_alerts', 'event' => 'E'.$i, 'severity' => 'info', 'effect' => 'none',
                'fingerprint' => hash('sha256', (string) $i), 'payload' => ['x' => $i], 'created_at' => now()]);
        }

        $events = ChannelHealth::panel()['events'];

        $this->assertCount(30, $events);
        $this->assertSame('E35', $events[0]['event']);
    }
}

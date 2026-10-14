<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بطاقة «صحة النظام والقناة» وحقل App ID وزر «افحص الآن»: خلف whatsapp.settings في الخادم،
 * بلا رمز ولا سر في props، والزر 6 في الدقيقة. وشريط الصندوق عام بلا تفاصيل.
 */
class SettingsOpsCardTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel(['wa_app_id' => '1234567890', 'wa_token' => 'EAAtokenvalue1234567890abcdef']);
        $this->owner = User::factory()->withPermissions(['whatsapp.settings', 'whatsapp.view'], 'owner')->create();
    }

    public function test_the_card_and_the_check_need_the_settings_permission(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();

        $this->actingAs($agent)->get('/whatsapp/settings')->assertForbidden();
        $this->actingAs($agent)->postJson('/api/whatsapp/meta-check')->assertForbidden();
    }

    public function test_the_page_carries_ops_and_meta_without_any_secret(): void
    {
        $response = $this->actingAs($this->owner)->get('/whatsapp/settings');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('ops.codes')
            ->has('ops.queues')
            ->where('meta.ready', true)
            ->where('settings.wa_app_id', '1234567890')
            ->where('settings.env_app_id', false));
        $html = $response->getContent();
        $this->assertStringNotContainsString('EAAtokenvalue1234567890abcdef', $html);
        $this->assertStringNotContainsString(self::TEST_APP_SECRET, $html);
    }

    public function test_the_app_id_must_be_digits(): void
    {
        $this->actingAs($this->owner)->put('/whatsapp/settings', ['wa_app_id' => 'abc'])
            ->assertSessionHasErrors(['wa_app_id' => 'معرّف التطبيق أرقام فقط (من App settings › Basic)']);

        $this->actingAs($this->owner)->put('/whatsapp/settings', ['wa_app_id' => '9876543210'])->assertSessionHasNoErrors();
        $this->assertSame('9876543210', WaBotSetting::current()->wa_app_id);
    }

    public function test_check_now_runs_both_checks_and_returns_the_summary_only(): void
    {
        Http::fake(['graph.facebook.com/*' => function ($request) {
            return str_contains($request->url(), 'debug_token')
                ? Http::response(['data' => ['app_id' => '1234567890', 'is_valid' => true, 'expires_at' => 0, 'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management']]])
                : Http::response(['health_status' => ['can_send_message' => 'AVAILABLE', 'entities' => []], 'quality_rating' => 'GREEN']);
        }]);

        $response = $this->actingAs($this->owner)->postJson('/api/whatsapp/meta-check')->assertOk();

        $response->assertJsonPath('meta.credentials.state', 'ok')->assertJsonPath('meta.channel.state', 'ok');
        $this->assertStringNotContainsString('EAAtokenvalue', $response->getContent());
    }

    public function test_check_now_is_limited_to_six_a_minute(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 1]], 500)]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->owner)->postJson('/api/whatsapp/meta-check')->assertOk();
        }
        $this->actingAs($this->owner)->postJson('/api/whatsapp/meta-check')->assertStatus(429);
    }

    public function test_the_inbox_shows_a_generic_banner_when_meta_blocks_the_channel(): void
    {
        $this->actingAs($this->owner)->get('/whatsapp/inbox')
            ->assertInertia(fn (Assert $page) => $page->where('status.meta_blocked', false));

        WaBotSetting::current()->forceFill(['wa_channel_health' => ['state' => 'critical', 'can_send_message' => 'BLOCKED']])->save();

        $this->actingAs($this->owner)->get('/whatsapp/inbox')
            ->assertInertia(fn (Assert $page) => $page->where('status.meta_blocked', true));
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بطاقتا «صحة النظام» و«📱 أرقام واتساب» في الإعدادات (منذ OpenWA في 2026-11-06 بدل بطاقة ميتا وزر «افحص الآن»):
 * خلف whatsapp.settings في الخادم، والبوابة للقراءة بنداء واحد بالمفتاح الرئيس، بلا مفتاح ولا سر ولا معرّف جلسة في props.
 */
class SettingsOpsCardTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings', 'whatsapp.view'], 'owner')->create();
    }

    public function test_the_card_needs_the_settings_permission_and_the_meta_check_is_gone(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();

        $this->actingAs($agent)->get('/whatsapp/settings')->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/whatsapp/meta-check')->assertNotFound();
    }

    public function test_the_page_carries_ops_and_the_gateway_without_any_secret(): void
    {
        $this->fakeGateway(['nuskjo-openwa:2785/api/health' => Http::response(['status' => 'ok', 'version' => '0.24.0'])]);
        $this->makeChannel(['status' => 'disconnected']);

        $response = $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('ops.codes')
            ->has('ops.queues')
            ->where('gateway.reachable', true)
            ->where('gateway.version', '0.24.0')
            ->where('gateway.channels', 2)
            ->where('gateway.linked', 1)
            ->missing('meta'));
        $html = $response->getContent();
        foreach (['key-1', 'key-2', 'sess-1', self::TEST_WEBHOOK_SECRET, 'admin-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_an_unreachable_gateway_shows_its_code_only(): void
    {
        $this->fakeGateway(['nuskjo-openwa:2785/api/health' => Http::response(['message' => 'down'], 503)]);

        $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('gateway.reachable', false)->where('gateway.version', null)->whereNot('gateway.error', null));
    }
}

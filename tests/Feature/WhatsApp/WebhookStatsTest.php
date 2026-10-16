<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\WebhookIntake;
use App\Services\WhatsApp\WebhookStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * بطاقة «استقبال الويبهوك» وقسم whatsapp:doctor (E20.9): أعداد وأعمار وأسماء حقول فقط، وتحذير أحمر لما ينتظر
 * يدوياً أو للاحتفاظ المتجاوز. لا جسم ولا رقم في أي مكان.
 */
class WebhookStatsTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.inbound_event_log' => true]);
    }

    private function dead(): WaWebhookEvent
    {
        // بايتات مختلفة عن text.json المرسَل (الهاش فريد)
        [$event] = WebhookIntake::store(json_encode(json_decode((string) file_get_contents($this->fixturePath('text.json')), true)));
        $event->forceFill(['status' => WaWebhookEvent::DEAD, 'attempts' => 3])->save();

        return $event;
    }

    public function test_the_settings_page_carries_counts_and_never_a_body(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings', 'whatsapp.view'], 'owner')->create();
        $this->postMetaWebhook('text.json')->assertOk();
        $this->dead();

        $response = $this->actingAs($owner)->get('/whatsapp/settings');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('webhookIntake.log.on', true)
            ->where('webhookIntake.fence.mode', 'log')
            ->where('webhookIntake.dead_open', 1)
            ->where('webhookIntake.limits.body_hours', 72)
            ->has('webhookIntake.fence.counts.waba_mismatch')
            ->where('settings.wa_waba_detected', '102290129340398'));
        $this->assertContains('dead', $response->viewData('page')['props']['webhookIntake']['warnings']);
        $props = json_encode($response->viewData('page')['props']['webhookIntake']);
        $this->assertStringNotContainsString('16505551234', $props);
        $this->assertStringNotContainsString('raw_body', $props);
    }

    public function test_doctor_reports_the_webhook_section_and_fails_strict_on_dead_rows(): void
    {
        $this->assertSame(0, Artisan::call('whatsapp:doctor'));
        $this->assertStringContainsString('الويبهوك: الحفظ قبل الإقرار مفعّل (all)', Artisan::output());

        $this->dead();
        $this->assertSame(1, Artisan::call('whatsapp:doctor', ['--strict' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('webhook_dead', $out);
        $this->assertStringNotContainsString('16505551234', $out);
    }

    public function test_the_webhook_route_check_passes_and_nginx_is_read(): void
    {
        $codes = array_column(WebhookStats::checks(WebhookStats::summary(true)), 'code');

        $this->assertNotContains('webhook_in_session', $codes);
        $this->assertNotContains('webhook_route_missing', $codes);
        $this->assertNotContains('nginx_webhook_block', $codes);
        $this->assertNotContains('nginx_keepalive', $codes);
    }
}

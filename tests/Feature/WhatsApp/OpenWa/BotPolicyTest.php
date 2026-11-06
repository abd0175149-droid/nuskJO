<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\Client;
use App\Models\WaConversation;
use App\Services\WhatsApp\Bot\SenderProfile;
use App\Services\WhatsApp\Bot\ToolPolicy;
use App\Services\WhatsApp\BotTools;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** أدوات البوت حسب ملف المرسل ووضع الرقم */
class BotPolicyTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    private function names(WaConversation $conv): array
    {
        return array_column(BotTools::declarations(WaBotSetting::current(), $conv), 'name');
    }

    public function test_guest_has_no_account_tools(): void
    {
        $this->configureChannel();
        $conv = WaConversation::factory()->create(['phone' => '962791112233', 'channel_id' => $this->testChannel()->id]);
        $this->assertSame('guest', SenderProfile::for($conv));
        $names = $this->names($conv);
        $this->assertContains('get_offers', $names);
        $this->assertContains('handoff_to_human', $names);
        $this->assertNotContains('get_my_balance', $names);
        $this->assertNotContains('resend_invoice_pdf', $names);
    }

    public function test_linked_client_gets_account_tools(): void
    {
        $this->configureChannel();
        $client = Client::factory()->create(['phone' => '0791112233']);
        $conv = WaConversation::factory()->create(['phone' => '962791112233', 'client_id' => $client->id, 'channel_id' => $this->testChannel()->id]);
        $this->assertSame('client', SenderProfile::for($conv));
        $names = $this->names($conv);
        foreach (['get_my_balance', 'resend_invoice_pdf', 'get_trip_details', 'manage_my_notifications'] as $t) {
            $this->assertContains($t, $names);
        }
    }

    public function test_limited_number_only_hands_off(): void
    {
        $this->configureChannel();
        $this->testChannel()->forceFill(['bot_mode' => 'limited'])->save();
        $conv = WaConversation::factory()->create(['phone' => '962791112233', 'channel_id' => $this->testChannel()->id]);
        $names = $this->names($conv);
        $this->assertContains('handoff_to_human', $names);
        $this->assertNotContains('get_offers', $names);
        $this->assertNotContains('request_quote', $names);
    }

    public function test_handoff_cannot_be_disabled_by_overrides(): void
    {
        WaBotSetting::current()->forceFill(['tool_profiles' => json_encode(['handoff_to_human' => ['guest' => false], 'get_offers' => ['guest' => false]])])->save();
        $this->assertTrue(ToolPolicy::allowed('handoff_to_human', 'guest'));
        $this->assertFalse(ToolPolicy::allowed('get_offers', 'guest'));
        $this->assertFalse(ToolPolicy::allowed('handoff_to_human', 'guest', 'off'));
    }
}

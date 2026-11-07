<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * whatsapp:resolve-lids: رقم جهة @lid من البوابة يملأ المحادثة، وما لا رقم له يُتخطى يوماً فلا يحجز رأس القائمة عن البقية.
 */
class ResolveLidsTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    public function test_a_contact_without_a_phone_does_not_starve_the_rest(): void
    {
        $this->configureChannel();
        $this->fakeGateway([
            'nuskjo-openwa:2785/api/sessions/*/contacts/*/phone' => function ($request) {
                $phone = str_contains($request->url(), rawurlencode('222@lid')) ? '962791110222' : null;

                return Http::response(['phone' => $phone]);
            },
        ]);
        $ch = $this->testChannel();
        // الأحدث لا رقم له، فيأتي أولاً في كل دورة لولا التخطي
        $none = WaConversation::forceCreate(['channel_id' => $ch->id, 'lid' => '111@lid', 'jid' => '111@lid', 'status' => 'closed', 'last_message_at' => now()]);
        $known = WaConversation::forceCreate(['channel_id' => $ch->id, 'lid' => '222@lid', 'jid' => '222@lid', 'status' => 'closed', 'last_message_at' => now()->subDay()]);

        $this->artisan('whatsapp:resolve-lids', ['--limit' => 1])->assertSuccessful();
        $this->assertNull($known->refresh()->phone);

        $this->artisan('whatsapp:resolve-lids', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('962791110222', $known->refresh()->phone);
        $this->assertNull($none->refresh()->phone);
    }
}

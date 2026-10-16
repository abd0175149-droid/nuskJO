<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOptout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حمولات ميتا التعاقدية (E24، نظير check-upstream-surface): كل حمولة في tests/Fixtures/whatsapp تمر بالمسار الحقيقي
 * موقّعة، فلا تكسر شيئاً، وتعطي الحقول المتوقعة بالشكلين القديم (مع conversation) والجديد (v24.0، بلا conversation).
 * يُشغَّل قبل أي ترقية لـ WA_GRAPH_VERSION: حمولة جديدة من المرجع تُضاف هنا مع مصدرها في PROVENANCE.txt.
 */
class MetaPayloadContractTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $this->configureChannel();
    }

    private function outboundFor(string $fixture): WaMessage
    {
        $wamid = json_decode((string) file_get_contents($this->fixturePath($fixture)), true)['entry'][0]['changes'][0]['value']['statuses'][0]['id'];

        return WaMessage::factory()->outbound()->create(['conversation_id' => WaConversation::factory()->windowOpen()->create()->id, 'wamid' => $wamid, 'status' => 'sent']);
    }

    public static function statusContracts(): array
    {
        $cases = [];
        foreach ([true, false] as $v2) {
            $mode = $v2 ? 'atomic' : 'legacy';
            $cases["v21 sent with conversation and pricing ({$mode})"] = [$v2, 'status_sent_pricing_v21.json',
                ['status' => 'sent', 'pricing_billable' => true, 'pricing_model' => 'PMP', 'pricing_category' => 'service', 'pricing_type' => 'regular']];
            $cases["v24 delivered without conversation ({$mode})"] = [$v2, 'status_delivered_pricing_v24.json',
                ['status' => 'delivered', 'pricing_billable' => true, 'pricing_model' => 'PMP', 'pricing_category' => 'service', 'pricing_type' => 'regular']];
            $cases["read without delivered ({$mode})"] = [$v2, 'status_read_no_delivered.json',
                ['status' => 'read', 'pricing_billable' => false, 'pricing_category' => 'service', 'pricing_type' => 'free_customer_service']];
            $cases["failed 131047 window ({$mode})"] = [$v2, 'status_failed_131047.json',
                ['status' => 'failed', 'meta_code' => 131047, 'error_category' => 'window']];
            $cases["failed 368 restricted ({$mode})"] = [$v2, 'status_failed_368.json',
                ['status' => 'failed', 'meta_code' => 368, 'error_category' => 'integrity']];
        }

        return $cases;
    }

    #[DataProvider('statusContracts')]
    public function test_status_payloads_parse_to_the_expected_columns(bool $v2, string $fixture, array $expected): void
    {
        config(['features.env.delivery_status_v2' => $v2]);
        $row = $this->outboundFor($fixture);

        $this->postMetaWebhook($fixture)->assertOk();

        $row->refresh();
        foreach ($expected as $column => $value) {
            $this->assertSame($value, $row->{$column}, "{$fixture}: {$column}");
        }
    }

    public function test_a_customer_restriction_account_event_moves_channel_health(): void
    {
        config(['features.env.channel_health' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        WaBotSetting::current()->forceFill(['wa_display_phone' => '15550783881'])->save();

        $this->postMetaWebhook('account_update_restriction_customer.json')->assertOk();

        $this->assertSame('BLOCKED', WaBotSetting::current()->wa_health);
        Http::assertNothingSent();
    }

    public function test_a_marketing_stop_preference_is_recorded(): void
    {
        config(['features.env.user_preferences_optout' => true]);

        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();

        $this->assertSame('16505551234', WaOptout::sole()->phone);
        Http::assertNothingSent();
    }

    public function test_every_contract_fixture_is_documented(): void
    {
        $provenance = (string) file_get_contents($this->fixturePath('PROVENANCE.txt'));
        foreach (['status_sent_pricing_v21.json', 'status_delivered_pricing_v24.json', 'status_failed_131047.json', 'status_failed_368.json',
            'status_read_no_delivered.json', 'account_update_restriction_customer.json', 'user_preferences_stop.json'] as $fixture) {
            $this->assertFileExists($this->fixturePath($fixture));
            $this->assertStringContainsString($fixture, $provenance);
        }
        // v24.0 يحذف كائن conversation، وpricing باقٍ: الشكلان معاً في المجموعة
        $old = json_decode((string) file_get_contents($this->fixturePath('status_sent_pricing_v21.json')), true)['entry'][0]['changes'][0]['value']['statuses'][0];
        $new = json_decode((string) file_get_contents($this->fixturePath('status_delivered_pricing_v24.json')), true)['entry'][0]['changes'][0]['value']['statuses'][0];
        $this->assertArrayHasKey('conversation', $old);
        $this->assertArrayNotHasKey('conversation', $new);
        $this->assertSame(array_keys($old['pricing']), array_keys($new['pricing']));
    }
}

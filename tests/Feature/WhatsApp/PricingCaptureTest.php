<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حفظ كائن pricing من ويبهوك الحالة (E24): الشكل القديم (مع conversation) وشكل v24.0 (بلا conversation) يعطيان الأعمدة
 * نفسها، وأول ظهور هو المعتمد فلا تكتب إعادة بفئة أخرى فوقه. في المسارين: الحالات الذرية (E21) والقديم.
 */
class PricingCaptureTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const V21 = 'status_sent_pricing_v21.json';

    private const V24 = 'status_delivered_pricing_v24.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
    }

    private function rowFor(string $fixture, string $status = 'sent'): WaMessage
    {
        $wamid = json_decode((string) file_get_contents($this->fixturePath($fixture)), true)['entry'][0]['changes'][0]['value']['statuses'][0]['id'];

        return WaMessage::factory()->outbound()->create([
            'conversation_id' => WaConversation::factory()->windowOpen()->create()->id, 'wamid' => $wamid, 'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function pricing(WaMessage $m): array
    {
        $m->refresh();

        return ['billable' => $m->pricing_billable, 'model' => $m->pricing_model, 'category' => $m->pricing_category, 'type' => $m->pricing_type];
    }

    public static function paths(): array
    {
        return ['atomic statuses (E21)' => [true], 'legacy path' => [false]];
    }

    #[DataProvider('paths')]
    public function test_old_and_new_payload_shapes_give_the_same_columns(bool $v2): void
    {
        config(['features.env.delivery_status_v2' => $v2]);
        $old = $this->rowFor(self::V21);
        $new = $this->rowFor(self::V24);

        $this->postMetaWebhook(self::V21)->assertOk();
        $this->postMetaWebhook(self::V24)->assertOk();

        $expected = ['billable' => true, 'model' => 'PMP', 'category' => 'service', 'type' => 'regular'];
        $this->assertSame($expected, $this->pricing($old));
        $this->assertSame($expected, $this->pricing($new));
        $this->assertSame('delivered', $new->status);
    }

    #[DataProvider('paths')]
    public function test_the_first_pricing_wins_over_a_replay_with_another_category(bool $v2): void
    {
        config(['features.env.delivery_status_v2' => $v2]);
        $m = $this->rowFor(self::V21);
        $this->postMetaWebhook(self::V21)->assertOk();

        $raw = json_decode((string) file_get_contents($this->fixturePath(self::V21)), true);
        $raw['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'delivered';
        $raw['entry'][0]['changes'][0]['value']['statuses'][0]['pricing'] = ['billable' => false, 'pricing_model' => 'PMP', 'category' => 'utility', 'type' => 'free_entry_point'];
        $this->postRawWebhook(json_encode($raw))->assertOk();

        $this->assertSame(['billable' => true, 'model' => 'PMP', 'category' => 'service', 'type' => 'regular'], $this->pricing($m));
        $this->assertSame('delivered', $m->status, 'الحالة تتقدّم، والتسعير الأول باقٍ');
    }

    public function test_a_status_without_pricing_leaves_the_columns_empty_and_values_are_clipped(): void
    {
        config(['features.env.delivery_status_v2' => true]);
        $m = $this->rowFor(self::V21);
        $raw = json_decode((string) file_get_contents($this->fixturePath(self::V21)), true);
        unset($raw['entry'][0]['changes'][0]['value']['statuses'][0]['pricing']);
        $this->postRawWebhook(json_encode($raw))->assertOk();
        $this->assertSame(['billable' => null, 'model' => null, 'category' => null, 'type' => null], $this->pricing($m));

        $raw['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'read';
        $raw['entry'][0]['changes'][0]['value']['statuses'][0]['pricing'] = ['billable' => 'true', 'pricing_model' => str_repeat('P', 40), 'category' => 'service<script>', 'type' => str_repeat('t', 60)];
        $this->postRawWebhook(json_encode($raw))->assertOk();
        $p = $this->pricing($m);
        $this->assertTrue($p['billable']);
        $this->assertSame(16, strlen($p['model']));
        $this->assertSame('servicescript', $p['category']);
        $this->assertSame(32, strlen($p['type']));
    }
}

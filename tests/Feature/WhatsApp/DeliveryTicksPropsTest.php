<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MetaErrorCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حقول علامات التسليم في الخيط (E21 T8، مع delivery_status_v2): status وstatus_at وmulti_fail لكل صادر، ورقم ميتا
 * ورمزنا وسببه وإجراؤه للفاشل (أسماء E18 نفسها: error وaction). لا payload ولا details، ولا رقم من تفاصيل ميتا.
 */
class DeliveryTicksPropsTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $agent;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.delivery_status_v2' => true, 'features.env.meta_error_v2' => true]);
        $this->agent = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.view_all']);
        $this->conv = $this->conversationTagged();
    }

    private function out(string $wamid, array $attrs = []): WaMessage
    {
        return WaMessage::factory()->outbound()->create($attrs + ['conversation_id' => $this->conv->id, 'wamid' => $wamid]);
    }

    private function postStatus(string $wamid, string $status, int $ts, array $extra = []): void
    {
        $this->postRawWebhook((string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [['id' => $wamid, 'status' => $status, 'timestamp' => (string) $ts, 'recipient_id' => '16505551234'] + $extra],
        ]]]]]]))->assertOk();
    }

    private function thread(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages?".http_build_query($query));
    }

    /** @return list<string> كل مفتاح في الرد على أي عمق */
    private function keys(array $data): array
    {
        $out = [];
        $walk = function (array $a) use (&$walk, &$out) {
            foreach ($a as $k => $v) {
                $out[] = (string) $k;
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);

        return $out;
    }

    public function test_the_thread_carries_ticks_and_failure_fields_and_nothing_raw(): void
    {
        $delivered = $this->out('wamid.E21.D');
        $multi = $this->out('wamid.E21.M');
        $failed = $this->out('wamid.E21.F');
        $this->postStatus('wamid.E21.D', 'delivered', 1760425803);
        $this->postStatus('wamid.E21.M', 'read', 1760425860);
        $this->postStatus('wamid.E21.M', 'failed', 1760425870, ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]);
        $this->postStatus('wamid.E21.F', 'failed', 1760425880, ['errors' => [['code' => 131026, 'title' => 'Message undeliverable',
            'error_data' => ['details' => 'Message failed to send to 16505551234 because the app is outdated.']]]]);

        $res = $this->thread()->assertOk();
        $byId = collect($res->json('messages'))->keyBy('id');

        $this->assertSame(['delivered', '07:10', false], [$byId[$delivered->id]['status'], $byId[$delivered->id]['status_at'], $byId[$delivered->id]['multi_fail']]);
        $this->assertSame(['read', true], [$byId[$multi->id]['status'], $byId[$multi->id]['multi_fail']]);
        $f = $byId[$failed->id];
        $meaning = MetaErrorCatalog::classify(131026);
        $this->assertSame(['failed', 131026, 'RECIPIENT_UNREACHABLE', $meaning['message'], $meaning['action'], false],
            [$f['status'], $f['meta_code'], $f['error_code'], $f['error'], $f['action'], $f['multi_fail']]);

        $keys = $this->keys($res->json());
        $this->assertNotContains('payload', $keys);
        $this->assertNotContains('details', $keys);
        $this->assertNotContains('statusHistory', $keys);
        $this->assertStringNotContainsString('16505551234', $res->getContent());
        $this->assertStringContainsString('•••', (string) data_get($failed->fresh()->payload, '_meta_error.details'), 'التفاصيل مقنّعة للدعم وحدها');
    }

    public function test_the_poll_returns_the_same_fields_for_messages_whose_status_changed(): void
    {
        $row = $this->out('wamid.E21.P');
        $first = $this->thread()->assertOk();

        $this->travel(10)->seconds();
        $this->postStatus('wamid.E21.P', 'read', 1760425860);

        $update = collect($this->thread(['after' => $row->id, 'since' => $first->json('synced_at')])->assertOk()->json('updates'))->firstWhere('id', $row->id);
        $this->assertSame(['read', '07:11', false], [$update['status'], $update['status_at'], $update['multi_fail']]);
        $this->assertArrayNotHasKey('payload', $update);
    }

    public function test_the_fields_are_absent_with_the_flag_off(): void
    {
        config(['features.env.delivery_status_v2' => false]);
        $this->out('wamid.E21.Q');

        $m = $this->thread()->assertOk()->json('messages.0');

        $this->assertArrayNotHasKey('status_at', $m);
        $this->assertArrayNotHasKey('multi_fail', $m);
    }

    public function test_incoming_messages_carry_no_ticks(): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id]);

        $this->assertArrayNotHasKey('multi_fail', $this->thread()->assertOk()->json('messages.0'));
    }

    public function test_the_thread_stays_behind_the_permission_and_the_fence(): void
    {
        $this->out('wamid.E21.R');
        $noView = User::factory()->withPermissions(['whatsapp.reply', 'whatsapp.view_all'], 'other')->create();
        $narrow = $this->staffWith('narrow', ['whatsapp.view'], ['visa']);

        $this->thread([], $noView)->assertForbidden();
        $this->thread([], $narrow)->assertNotFound();
    }
}

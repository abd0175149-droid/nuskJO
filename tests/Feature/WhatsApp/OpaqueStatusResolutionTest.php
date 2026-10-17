<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حسم «غير مؤكد» من ويبهوك ميتا (E19.7): صف بلا معرّف يُطابق برقمه في biz_opaque_callback_data ببادئة هذه النسخة،
 * فيأخذ المعرّف ويرتفع رتيباً، وتُرفع شارة send_unknown إن لم يبق صف مشكوك. البادئة الغريبة تُتجاهل.
 */
class OpaqueStatusResolutionTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBJPUEFRVUVMRURHRVIwMDAxAA==';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
    }

    private function postStatus(string $fixture, string $opaque): void
    {
        $raw = str_replace('"nj:m:1"', json_encode($opaque), (string) file_get_contents($this->fixturePath($fixture)));
        $this->postRawWebhook($raw)->assertOk();
    }

    private function unknownRow(WaConversation $conv, array $attrs = []): WaMessage
    {
        return WaMessage::factory()->outbound()->create($attrs + ['conversation_id' => $conv->id, 'status' => 'unknown', 'wamid' => null,
            'send_key' => 'reply:'.$conv->id.':5:text', 'error_code' => 'HTTP_TIMEOUT', 'error_message' => 'انتهت مهلة ميتا']);
    }

    public function test_an_unknown_row_becomes_sent_then_read_and_never_goes_back(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = $this->unknownRow($conv);

        $this->postStatus('status_sent_with_opaque.json', 'nj:m:'.$row->id);
        $row->refresh();
        $this->assertSame(['sent', self::WAMID, null, null], [$row->status, $row->wamid, $row->error_code, $row->error_message]);
        $this->assertNotNull($row->sent_at);

        $this->postStatus('status_read_with_opaque.json', 'nj:m:'.$row->id);
        $this->assertSame('read', $row->refresh()->status);

        $this->postStatus('status_sent_with_opaque.json', 'nj:m:'.$row->id);
        $this->assertSame('read', $row->refresh()->status, 'الحالة لا تتراجع');
        $this->assertSame(1, WaMessage::count());
    }

    public function test_another_installation_prefix_is_ignored(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = $this->unknownRow($conv);

        $this->postStatus('status_sent_with_opaque.json', 'zz:m:'.$row->id);

        $this->assertSame(['unknown', null], [$row->refresh()->status, $row->wamid]);
    }

    public function test_a_row_with_another_wamid_is_not_overwritten(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'sent', 'wamid' => 'wamid.OTHER']);

        $this->postStatus('status_read_with_opaque.json', 'nj:m:'.$row->id);

        $this->assertSame(['sent', 'wamid.OTHER'], [$row->refresh()->status, $row->wamid]);
    }

    public function test_a_reaped_row_is_resolved_and_the_send_unknown_badge_lifts_when_nothing_is_left(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'attention_reason' => 'send_unknown']);
        $a = $this->unknownRow($conv, ['payload' => ['_reaped' => now()->toIso8601String()]]);
        $b = $this->unknownRow($conv, ['send_key' => 'reply:'.$conv->id.':6:text']);

        $this->postStatus('status_sent_with_opaque.json', 'nj:m:'.$a->id);

        $this->assertArrayNotHasKey('_reaped', $a->refresh()->payload);
        $this->assertTrue($conv->refresh()->needs_attention, 'ما زال صف مشكوك');

        $b->forceFill(['status' => 'sent', 'wamid' => 'wamid.B'])->save();
        WaMessage::whereKey($a->id)->update(['status' => 'unknown', 'wamid' => null]);
        $this->postStatus('status_sent_with_opaque.json', 'nj:m:'.$a->id);

        $conv->refresh();
        $this->assertFalse($conv->needs_attention);
        $this->assertNull($conv->attention_reason);
    }

    public function test_another_attention_reason_is_left_alone(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'attention_reason' => 'handoff']);
        $row = $this->unknownRow($conv);

        $this->postStatus('status_sent_with_opaque.json', 'nj:m:'.$row->id);

        $this->assertSame([true, 'handoff'], [$conv->refresh()->needs_attention, $conv->attention_reason]);
    }
}

<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * محادثة قديمة بلا رقم (channel_id فارغ، قبل الأرقام المتعددة): أول وارد من العميل على رقم يتبنّاها، كما يتبنّاها الإرسال
 * (WhatsAppChannel::channelFor)، فلا تنقسم محادثته. ومحادثته على الرقم إن وُجدت تغلب، والمدموجة لا تُتبنّى.
 */
class ChannellessAdoptionTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
    }

    public function test_the_first_inbound_adopts_the_channelless_conversation(): void
    {
        $legacy = WaConversation::factory()->create(['channel_id' => null, 'phone' => '16505551234', 'wa_phone' => '16505551234', 'unread_count' => 0]);

        $this->postOpenWa('message.received', $this->incoming('16505551234', 'مرحبا'))->assertOk();

        $this->assertSame(1, WaConversation::count(), 'لا محادثة ثانية');
        $this->assertSame([$this->testChannel()->id, 1], [$legacy->fresh()->channel_id, $legacy->fresh()->unread_count]);
        $this->assertSame($legacy->id, WaMessage::sole()->conversation_id);
    }

    public function test_a_conversation_already_on_the_channel_wins_and_merged_ones_are_left(): void
    {
        WaConversation::factory()->create(['channel_id' => null, 'phone' => '16505551234', 'wa_phone' => '16505551234']);
        WaConversation::factory()->create(['channel_id' => null, 'phone' => '16505559876', 'wa_phone' => '16505559876', 'status' => 'merged']);
        $current = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);

        $this->postOpenWa('message.received', $this->incoming('16505551234', 'مرحبا'))->assertOk();
        $this->postOpenWa('message.received', $this->incoming('16505559876', 'مرحبا'))->assertOk();

        $this->assertSame($current->id, WaMessage::where('body', 'مرحبا')->orderBy('id')->first()->conversation_id);
        $this->assertSame(1, WaConversation::whereNull('channel_id')->where('phone', '16505551234')->count(), 'القديمة تبقى');
        $this->assertSame(1, WaConversation::where('phone', '16505559876')->where('status', 'merged')->whereNull('channel_id')->count());
        $this->assertSame(1, WaConversation::where('phone', '16505559876')->where('channel_id', $this->testChannel()->id)->count());
    }
}

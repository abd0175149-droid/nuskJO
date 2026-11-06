<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * العزل (E20.6): البوابة ترسل حدثاً لكل طلب، فالحدث الفاشل يبقى صفه failed للإعادة بمعرّفه واسم صنفه فقط، ولا يمس
 * ما بعده. وتعارض المعرّف الفريد تكرار ناجح، والحالة المكررة لا تُلحق مرتين.
 */
class WebhookIsolationTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    /** wamid => الاستثناء الذي يُرمى عند إدراج الرسالة */
    private array $failOn = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.inbound_event_log' => true]);

        WaMessage::creating(function (WaMessage $m) {
            if (isset($this->failOn[$m->wamid])) {
                throw $this->failOn[$m->wamid];
            }
        });
    }

    private function outbound(string $wamid): WaMessage
    {
        $conv = WaConversation::firstOrCreate(['phone' => '16505551234'], ['wa_phone' => '16505551234', 'last_inbound_at' => now()]);

        return WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => $wamid, 'status' => 'sent']);
    }

    /** ما كانت ميتا ترسله في حمولة واحدة: إيصالان ثم رسالتان، كل منها حدث مستقل */
    private function postBatch(): void
    {
        $this->postOpenWa('message.ack', ['messageId' => 'out.1', 'status' => 'delivered', 'timestamp' => 1760425741])->assertOk();
        $this->postOpenWa('message.ack', ['messageId' => 'out.2', 'status' => 'read', 'timestamp' => 1760425742])->assertOk();
        $this->postOpenWa('message.received', $this->incoming('16505551234', 'First message in the batch.', ['id' => 'batch.1']))
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->postOpenWa('message.received', $this->incoming('16505551234', 'Second message in the batch.', ['id' => 'batch.2']))
            ->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_a_failing_message_does_not_drop_the_rest_of_the_batch(): void
    {
        $out1 = $this->outbound('out.1');
        $out2 = $this->outbound('out.2');
        $this->failOn['batch.1'] = new \RuntimeException('سري: نص لا يُسجَّل');

        $this->postBatch();

        $this->assertFalse(WaMessage::where('wamid', 'batch.1')->exists());
        $this->assertTrue(WaMessage::where('wamid', 'batch.2')->exists());
        $this->assertSame('delivered', $out1->fresh()->status);
        $this->assertSame('read', $out2->fresh()->status);
        Queue::assertPushed(ProcessWhatsAppReply::class);

        $this->assertSame(3, WaWebhookEvent::where('status', WaWebhookEvent::PROCESSED)->count());
        $event = WaWebhookEvent::where('status', WaWebhookEvent::FAILED)->sole();
        $this->assertSame([['kind' => 'event', 'ref' => 'batch.1', 'error' => 'RuntimeException']], $event->item_errors);
        $this->assertStringNotContainsString('سري', json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('message.received:1', $event->field_summary);
        $this->assertNotNull($event->getRawOriginal('raw_body'));
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $event->next_attempt_at->timestamp, 2);
    }

    public function test_a_unique_conflict_on_the_wamid_is_a_successful_duplicate(): void
    {
        // نسخة متزامنة خزّنت الرسالة بين فحص الوجود والإدراج: الفهرس الفريد يرفض الثانية
        $this->failOn['batch.1'] = new UniqueConstraintViolationException('sqlite', 'insert', [],
            new \PDOException('UNIQUE constraint failed: wa_messages.channel_id, wa_messages.wamid'));

        $this->postBatch();

        $this->assertSame(0, WaWebhookEvent::where('status', '!=', WaWebhookEvent::PROCESSED)->count(), 'التعارض تكرار ناجح لا خطأ عنصر');
        $this->assertSame(0, WaWebhookEvent::whereNotNull('item_errors')->count());
        $this->assertSame(0, WaWebhookEvent::whereNotNull('raw_body')->count());
        $this->assertTrue(WaMessage::where('wamid', 'batch.2')->exists());
    }

    public function test_a_repeated_status_is_recorded_once_in_the_history(): void
    {
        $out = $this->outbound('out.1');
        $ack = ['messageId' => 'out.1', 'status' => 'delivered', 'timestamp' => 1760425741];

        // البوابة تعيد الحدث بتوصيلة ومفتاح جديدين: البصمة لا تمنعه، والتاريخ يمنع تكراره
        $this->postOpenWa('message.ack', $ack)->assertOk();
        $this->postOpenWa('message.ack', $ack)->assertOk();

        $this->assertSame(['delivered'], array_column($out->fresh()->payload['statusHistory'], 's'));
    }
}

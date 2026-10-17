<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\Settings\SettingsWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * العزل (E20.6): عنصر فاشل في دفعة لا يُسقط ما بعده، ويُسجَّل بمعرّفه واسم صنفه فقط. وتعارض wamid تكرار ناجح،
 * وإعادة حدث الحساب لا تكرر الإشعار ولا الإيقاف، والحالة المكررة لا تُلحق مرتين.
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

    public function test_a_failing_message_does_not_drop_the_rest_of_the_batch(): void
    {
        $out1 = $this->outbound('wamid.FIXTURE.out.1');
        $out2 = $this->outbound('wamid.FIXTURE.out.2');
        $this->failOn['wamid.FIXTURE.batch.2'] = new \RuntimeException('سري: نص لا يُسجَّل');

        $this->postMetaWebhook('batch_mixed.json')->assertOk()->assertExactJson(['ok' => true]);

        $this->assertTrue(WaMessage::where('wamid', 'wamid.FIXTURE.batch.1')->exists());
        $this->assertFalse(WaMessage::where('wamid', 'wamid.FIXTURE.batch.2')->exists());
        $this->assertSame('delivered', $out1->fresh()->status);
        $this->assertSame('read', $out2->fresh()->status);
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);

        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::FAILED, $event->status);
        $this->assertSame([['kind' => 'message', 'ref' => 'wamid.FIXTURE.batch.2', 'error' => 'RuntimeException']], $event->item_errors);
        $this->assertStringNotContainsString('سري', json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('statuses:2,messages:2', $event->field_summary);
        $this->assertNotNull($event->getRawOriginal('raw_body'));
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $event->next_attempt_at->timestamp, 2);
    }

    public function test_a_unique_conflict_on_the_wamid_is_a_successful_duplicate(): void
    {
        // نسخة متزامنة من ميتا خزّنت الرسالة بين فحص الوجود والإدراج: الفهرس الفريد يرفض الثانية
        $this->failOn['wamid.FIXTURE.batch.1'] = new UniqueConstraintViolationException('sqlite', 'insert', [], new \PDOException('UNIQUE constraint failed: wa_messages.wamid'));

        $this->postMetaWebhook('batch_mixed.json')->assertOk();

        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::PROCESSED, $event->status, 'التعارض تكرار ناجح لا خطأ عنصر');
        $this->assertNull($event->item_errors);
        $this->assertNull($event->getRawOriginal('raw_body'));
        $this->assertTrue(WaMessage::where('wamid', 'wamid.FIXTURE.batch.2')->exists());
    }

    public function test_a_replayed_account_ban_notifies_and_suspends_once(): void
    {
        $admin = User::factory()->admin()->create();

        $this->postMetaWebhook('account_update_ban.json')->assertOk();
        $this->assertTrue((bool) WaBotSetting::current()->wa_suspended);
        $notified = Notification::where('user_id', $admin->id)->count();
        $this->assertSame(1, $notified);

        // المدير راجع واستأنف، ثم أعادت ميتا الحمولة نفسها (بجسم مختلف البايتات فلا يمنعها الهاش)
        SettingsWriter::apply(['wa_suspended' => false], null, null, audit: 'none');
        $raw = json_encode(json_decode((string) file_get_contents($this->fixturePath('account_update_ban.json')), true));
        $this->postRawWebhook($raw)->assertOk();

        $this->assertFalse((bool) WaBotSetting::current()->wa_suspended, 'لا إيقاف ثانٍ من إعادة الحدث نفسه');
        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
    }

    public function test_a_repeated_status_is_recorded_once_in_the_history(): void
    {
        $out = $this->outbound('wamid.FIXTURE.out.1');
        $payload = json_decode((string) file_get_contents($this->fixturePath('batch_mixed.json')), true);
        $payload['entry'][0]['changes'][0]['value']['messages'] = [];
        $payload['entry'][0]['changes'][0]['value']['statuses'] = [$payload['entry'][0]['changes'][0]['value']['statuses'][0]];

        $this->postRawWebhook(json_encode($payload))->assertOk();
        $this->postRawWebhook(json_encode($payload, JSON_PRETTY_PRINT))->assertOk();

        $this->assertSame(['delivered'], array_column($out->fresh()->payload['statusHistory'], 'status'));
    }
}

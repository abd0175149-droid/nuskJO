<?php

namespace Tests\Feature\WhatsApp;

use App\Events\NewNotification;
use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MetaErrorCatalog;
use App\Services\WhatsApp\StatusApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * حالات التسليم الذرية (E21، العلم delivery_status_v2): الحالة لا تتراجع أياً كان ترتيب الوصول، وstatus_at وقت الحدث،
 * والتاريخ يُلحق بلا تكرار، وfailed المتأخرة تبقى في التاريخ، وأعمدة E18 كما هي. المصدر منذ 2026-11-06 أحداث
 * message.ack وmessage.failed من البوابة (حمولات ميتا القديمة تمر بمحوّل SignsMetaWebhooks).
 */
class StatusApplierTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    /** wamid حمولات status_sent/delivered/read.json */
    private const WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI0MkZERjYwREJFODBFQTIxNDEA';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.delivery_status_v2' => true]);
        $this->assertTrue(StatusApplier::enabled());
    }

    private function outbound(array $attrs = []): WaMessage
    {
        $conv = WaConversation::factory()->windowOpen()->create();

        return WaMessage::factory()->outbound()->create($attrs + ['conversation_id' => $conv->id, 'wamid' => self::WAMID]);
    }

    private function history(WaMessage $m): array
    {
        return (array) ($m->fresh()->payload['statusHistory'] ?? []);
    }

    public function test_a_late_delivered_never_overwrites_read_and_status_at_is_metas_time(): void
    {
        $out = $this->outbound();

        $this->postMetaWebhook('status_read.json')->assertOk();
        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $out->refresh();
        $this->assertSame('read', $out->status);
        $this->assertSame(Carbon::createFromTimestamp(1760425860)->toDateTimeString(), $out->status_at->toDateTimeString(), 'وقت ميتا لا وقت الوصول');
        $this->assertSame([['s' => 'read', 't' => 1760425860, 'c' => null], ['s' => 'delivered', 't' => 1760425803, 'c' => null]], $this->history($out));
    }

    public function test_the_same_status_twice_is_one_history_item_and_one_event(): void
    {
        Event::fake([NewNotification::class, WaMessageStatusChanged::class]);
        $out = $this->outbound();

        $this->postMetaWebhook('status_delivered.json')->assertOk();
        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertSame('delivered', $out->fresh()->status);
        $this->assertCount(1, $this->history($out));
        Event::assertDispatchedTimes(WaMessageStatusChanged::class, 1);
        Event::assertDispatched(WaMessageStatusChanged::class, fn ($e) => $e->messageId === $out->id && $e->status === 'delivered' && $e->metaTs === 1760425803);
    }

    public function test_a_failed_event_on_a_sent_row_fills_the_error_columns_once(): void
    {
        config(['features.env.meta_error_v2' => true]);
        Event::fake([NewNotification::class, WaMessageFailed::class]);
        $out = $this->outbound(['wamid' => 'true_16505551234@c.us_FAILED1']);
        $failed = ['messageId' => 'true_16505551234@c.us_FAILED1', 'status' => 'failed', 'timestamp' => 1760425900];

        $this->postOpenWa('message.failed', $failed)->assertOk();

        $out->refresh();
        $meaning = MetaErrorCatalog::byCode('DELIVERY_FAILED');
        $this->assertSame(['failed', null, 'DELIVERY_FAILED', 'quality', $meaning['message']],
            [$out->status, $out->meta_code, $out->error_code, $out->error_category, $out->error_message]);
        Event::assertDispatched(WaMessageFailed::class, fn ($e) => $e->origin === 'webhook' && $e->metaCode === null && $e->scope === 'recipient');

        // إعادة البوابة للحدث نفسه: لا حدث ثانٍ
        $this->postOpenWa('message.failed', $failed)->assertOk();
        Event::assertDispatchedTimes(WaMessageFailed::class, 1);
    }

    public function test_a_failure_after_read_stays_in_the_history_and_the_thread_shows_multi_fail(): void
    {
        $out = $this->outbound(['source' => 'staff']);
        $this->postMetaWebhook('status_read.json')->assertOk();

        $this->postOpenWa('message.failed', ['messageId' => self::WAMID, 'status' => 'failed', 'timestamp' => 1760425900])->assertOk();

        $out->refresh();
        $this->assertSame('read', $out->status);
        $this->assertNull($out->error_code);
        $this->assertSame(['read', 'failed'], array_column($this->history($out), 's'));
        $this->assertNull($this->history($out)[1]['c'], 'لا رقم ميتا من البوابة');

        $agent = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.view_all']);
        $this->actingAs($agent)->getJson("/api/whatsapp/conversations/{$out->conversation_id}/messages")->assertOk()
            ->assertJsonPath('messages.0.multi_fail', true)
            ->assertJsonPath('messages.0.status', 'read');
    }

    public function test_positive_evidence_after_a_failure_wins_and_clears_the_reason(): void
    {
        $out = $this->outbound(['status' => 'failed', 'error_code' => 'RECIPIENT_UNREACHABLE', 'error_category' => 'caller', 'error_message' => 'تعذّر التسليم', 'meta_code' => 131026]);

        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $out->refresh();
        $this->assertSame(['delivered', null, null, null], [$out->status, $out->error_code, $out->error_category, $out->error_message]);
    }

    public function test_a_reaped_unknown_row_becomes_delivered_and_loses_its_mark(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'attention_reason' => 'send_unknown']);
        // مهلة بعد الإرسال ثم حسم الصدى معرّفه (EchoHandler): الصف unknown بمعرّف حتى يصل الإيصال
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'unknown', 'wamid' => 'true_16505551234@c.us_REAPED1',
            'error_code' => 'GW_TIMEOUT', 'error_message' => 'انتهت مهلة البوابة', 'payload' => ['_reaped' => now()->toIso8601String(), 'keep' => 1]]);

        $this->postOpenWa('message.ack', ['messageId' => 'true_16505551234@c.us_REAPED1', 'status' => 'delivered', 'timestamp' => 1760425803])->assertOk();

        $row->refresh();
        $this->assertSame(['delivered', null, null], [$row->status, $row->error_code, $row->error_message]);
        $this->assertArrayNotHasKey('_reaped', $row->payload);
        $this->assertSame(1, $row->payload['keep']);
        $this->assertNotNull($row->sent_at);
        $this->assertFalse($conv->fresh()->needs_attention, 'لا صف مشكوك بعده');
    }

    public function test_every_arrival_order_ends_on_read(): void
    {
        $statuses = ['sent' => 100, 'delivered' => 200, 'read' => 300, 'failed' => 400];
        $orders = [[]];
        foreach (array_keys($statuses) as $s) {
            $next = [];
            foreach ($orders as $o) {
                for ($i = 0; $i <= count($o); $i++) {
                    $next[] = [...array_slice($o, 0, $i), $s, ...array_slice($o, $i)];
                }
            }
            $orders = $next;
        }
        $this->assertCount(24, $orders);

        foreach ($orders as $n => $order) {
            $row = $this->outbound(['wamid' => "wamid.PERM.{$n}"]);
            foreach ($order as $s) {
                StatusApplier::apply(['id' => "wamid.PERM.{$n}", 'status' => $s, 'timestamp' => (string) (1760425000 + $statuses[$s])]
                    + ($s === 'failed' ? ['errors' => [['code' => 131026]]] : []));
            }
            $row->refresh();
            $this->assertSame('read', $row->status, implode('→', $order));
            $this->assertCount(4, $this->history($row), implode('→', $order));
        }
    }

    public function test_a_status_without_timestamp_uses_now_and_records_no_meta_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 09:30:00'));
        $out = $this->outbound();

        StatusApplier::apply(['id' => self::WAMID, 'status' => 'delivered']);

        $out->refresh();
        $this->assertSame('2026-10-16 09:30:00', $out->status_at->toDateTimeString());
        $this->assertSame([['s' => 'delivered', 't' => null, 'c' => null]], $this->history($out));
    }

    public function test_a_future_timestamp_is_capped_at_now(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 09:30:00'));
        $out = $this->outbound();

        StatusApplier::apply(['id' => self::WAMID, 'status' => 'delivered', 'timestamp' => (string) now()->addHour()->getTimestamp()]);

        $this->assertSame('2026-10-16 09:30:00', $out->fresh()->status_at->toDateTimeString());
    }

    public function test_unknown_values_and_rank_zero_are_never_written(): void
    {
        $this->captureLogs();
        $out = $this->outbound(['status' => null]);

        foreach (['deleted', 'deleted', 'queued', 'unknown'] as $s) {
            StatusApplier::apply(['id' => self::WAMID, 'status' => $s, 'timestamp' => '1760425800']);
        }

        $this->assertNull($out->fresh()->status);
        $this->assertSame(1, collect($this->logged)->where('message', 'wa.status.unknown_value')->count(), 'سطر واحد في الساعة');
    }

    public function test_the_history_is_capped(): void
    {
        $out = $this->outbound();
        for ($i = 1; $i <= 15; $i++) {
            StatusApplier::apply(['id' => self::WAMID, 'status' => 'failed', 'timestamp' => (string) (1760425000 + $i), 'errors' => [['code' => 131026]]]);
        }

        $this->assertCount(12, $this->history($out));
    }

    public function test_old_history_items_stay_readable(): void
    {
        $out = $this->outbound(['payload' => ['statusHistory' => [['status' => 'sent', 'at' => '2026-10-15T10:00:00+00:00']]]]);

        $this->postMetaWebhook('status_read.json')->assertOk();

        $this->assertSame([['status' => 'sent', 'at' => '2026-10-15T10:00:00+00:00'], ['s' => 'read', 't' => 1760425860, 'c' => null]], $this->history($out));
    }

    public function test_a_failed_bot_reply_marks_the_conversation_for_staff(): void
    {
        $out = $this->outbound(['wamid' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBIxMzEwMjZVTkRFTElWRVJBQkxFAA==']);

        $this->postMetaWebhook('status_failed_131026.json')->assertOk();

        $conv = WaConversation::find($out->conversation_id);
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('send_failed', $conv->attention_reason);
    }

    public function test_an_inbound_message_with_the_same_id_is_never_touched(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $in = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID]);

        $this->postMetaWebhook('status_read.json')->assertOk();

        $this->assertSame('received', $in->fresh()->status);
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Events\NewNotification;
use App\Events\WhatsApp\WaMessageFailed;
use App\Events\WhatsApp\WaMessageStatusChanged;
use App\Models\User;
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
 * حالات التسليم الذرية (E21، العلم delivery_status_v2): الحالة لا تتراجع أياً كان ترتيب الوصول، وstatus_at وقت ميتا،
 * والتاريخ يُلحق بلا تكرار، وfailed المتأخرة تبقى في التاريخ، ومطابقة biz_opaque (E19) وأعمدة E18 كما هي.
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

    private const OPAQUE_WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBJPUEFRVUVMRURHRVIwMDAxAA==';

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

    /** حمولة حالة واحدة بشكل المرجع */
    private function statusPayload(string $wamid, string $status, ?int $ts, array $extra = []): string
    {
        $st = ['id' => $wamid, 'status' => $status, 'recipient_id' => '16505551234'] + ($ts !== null ? ['timestamp' => (string) $ts] : []) + $extra;

        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::TEST_PHONE_NUMBER_ID],
            'statuses' => [$st],
        ]]]]]]);
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

    public function test_failed_131047_on_a_sent_row_fills_the_error_columns_once(): void
    {
        config(['features.env.meta_error_v2' => true]);
        Event::fake([NewNotification::class, WaMessageFailed::class]);
        $out = $this->outbound(['wamid' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI4NjAzMUVGQzhCN0UxMTc1NkQA']);

        $this->postMetaWebhook('status_failed_131047.json')->assertOk();

        $out->refresh();
        $meaning = MetaErrorCatalog::classify(131047);
        $this->assertSame(['failed', 131047, 'WINDOW_EXPIRED', 'window', $meaning['message']],
            [$out->status, $out->meta_code, $out->error_code, $out->error_category, $out->error_message]);
        $this->assertSame(1, (int) Cache::get('wa:meta:cat:window:'.now()->format('Ymd')), 'المفاعل مرة واحدة');
        // تفاصيل ميتا لا تُكتب في أي عمود ولا تُعرض: payload._meta_error مقنّعة للدعم وحدها (E18)
        $row = (array) DB::table('wa_messages')->where('id', $out->id)->first();
        unset($row['payload']);
        $this->assertStringNotContainsString('more than 24 hours', json_encode($row));
        Event::assertDispatched(WaMessageFailed::class, fn ($e) => $e->origin === 'webhook' && $e->metaCode === 131047 && $e->scope === 'recipient');

        // إعادة ميتا للحمولة نفسها: لا عدّ ثانٍ ولا حدث ثانٍ
        $this->postMetaWebhook('status_failed_131047.json')->assertOk();
        $this->assertSame(1, (int) Cache::get('wa:meta:cat:window:'.now()->format('Ymd')));
        Event::assertDispatchedTimes(WaMessageFailed::class, 1);
    }

    public function test_a_failure_after_read_stays_in_the_history_and_the_thread_shows_multi_fail(): void
    {
        $out = $this->outbound(['source' => 'staff']);
        $this->postMetaWebhook('status_read.json')->assertOk();

        $this->postRawWebhook($this->statusPayload(self::WAMID, 'failed', 1760425900, ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]))->assertOk();

        $out->refresh();
        $this->assertSame('read', $out->status);
        $this->assertNull($out->error_code);
        $this->assertSame(['read', 'failed'], array_column($this->history($out), 's'));
        $this->assertSame(131026, $this->history($out)[1]['c']);

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

    public function test_a_queued_row_is_resolved_by_its_opaque_value_and_another_prefix_matches_nothing(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $queued = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'queued', 'wamid' => null]);
        $foreign = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'queued', 'wamid' => null]);

        $raw = (string) file_get_contents($this->fixturePath('status_sent_with_opaque.json'));
        $this->postRawWebhook(str_replace('"nj:m:1"', '"tw:m:'.$foreign->id.'"', $raw))->assertOk();
        $this->postRawWebhook(str_replace('"nj:m:1"', '"nj:m:'.$queued->id.'"', $raw))->assertOk();

        $queued->refresh();
        $this->assertSame(['sent', self::OPAQUE_WAMID], [$queued->status, $queued->wamid]);
        $this->assertNotNull($queued->sent_at);
        $this->assertSame(['queued', null], [$foreign->fresh()->status, $foreign->fresh()->wamid]);
        $this->assertSame(2, WaMessage::count(), 'لا صف جديد');
    }

    public function test_a_reaped_unknown_row_becomes_delivered_and_loses_its_mark(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'attention_reason' => 'send_unknown']);
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'unknown', 'wamid' => null,
            'error_code' => 'HTTP_TIMEOUT', 'error_message' => 'انتهت مهلة ميتا', 'payload' => ['_reaped' => now()->toIso8601String(), 'keep' => 1]]);

        $raw = (string) file_get_contents($this->fixturePath('status_delivered_with_biz_opaque.json'));
        $this->postRawWebhook(str_replace('"nj:m:1"', '"nj:m:'.$row->id.'"', $raw))->assertOk();

        $row->refresh();
        $this->assertSame(['delivered', self::OPAQUE_WAMID, null, null], [$row->status, $row->wamid, $row->error_code, $row->error_message]);
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

    public function test_with_the_flag_off_the_legacy_path_runs(): void
    {
        config(['features.env.delivery_status_v2' => false]);
        $out = $this->outbound();

        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $out->refresh();
        $this->assertSame('delivered', $out->status);
        $this->assertNull($out->status_at);
        $this->assertSame('delivered', $this->history($out)[0]['status']);
    }

    public function test_a_status_from_meta_v24_without_a_conversation_object_applies(): void
    {
        $out = $this->outbound(['wamid' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBJFMjFSRUFEVjI0Tk9DT05WAA==']);

        $this->postMetaWebhook('status_read_v24_no_conversation.json')->assertOk();

        $this->assertSame('read', $out->fresh()->status);
    }

    public function test_an_inbound_message_with_the_same_id_is_never_touched(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $in = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID]);

        $this->postMetaWebhook('status_read.json')->assertOk();

        $this->assertSame('received', $in->fresh()->status);
    }
}

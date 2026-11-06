<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الحفظ قبل الإقرار (E20.5): الجسم مشفّر في wa_webhook_events بهاش فريد، يُمحى فور النجاح، والإعادة من البوابة
 * ترد 200 بلا معالجة ثانية. 503 لفشل الحفظ العابر وحده (خلف webhook_5xx)، والمعطوب 200 ولا يُعاد.
 * الأجسام هنا أحداث OpenWA كما ترسلها البوابة بايتاً ببايت (2026-11-06).
 */
class WebhookEventLogTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.inbound_event_log' => true]);
    }

    /** حدث message.received ثابت البايتات (البصمة على الجسم الخام) */
    private function raw(string $id = 'false_16505551234@c.us_AAA1'): string
    {
        return (string) json_encode([
            'event' => 'message.received', 'timestamp' => '2026-11-06T10:00:00.000Z', 'sessionId' => $this->testChannel()->openwa_session_id,
            'idempotencyKey' => 'idem-'.$id, 'deliveryId' => 'd-1', 'data' => $this->incoming('16505551234', 'Does it come in another color?', ['id' => $id]),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postBody(string $raw)
    {
        return $this->postOpenWaRaw($raw, $this->testChannel());
    }

    public function test_a_processed_payload_keeps_only_its_hash_and_counts(): void
    {
        $raw = $this->raw();
        $this->postBody($raw)->assertOk()->assertExactJson(['ok' => true]);

        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::PROCESSED, $event->status);
        $this->assertSame(hash('sha256', $raw), $event->body_sha256);
        $this->assertNull($event->getRawOriginal('raw_body'));
        $this->assertSame('message.received:1', $event->field_summary);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_a_retry_of_the_same_body_answers_at_once_without_processing_again(): void
    {
        $raw = $this->raw();
        $this->postBody($raw)->assertOk();
        WaMessage::query()->delete();   // لو عولجت ثانية لعادت الرسالة
        \Illuminate\Support\Facades\Cache::flush();   // البصمة وحدها تحمي، لا مفتاح التكرار

        $this->postBody($raw)->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(1, WaWebhookEvent::count());
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_the_stored_body_is_encrypted_and_never_serialized(): void
    {
        WaMessage::creating(fn () => throw new \RuntimeException('boom'));   // يبقى الجسم محفوظاً
        $this->postBody($this->raw())->assertOk();

        $row = (array) DB::table('wa_webhook_events')->first();
        $this->assertNotNull($row['raw_body']);
        $this->assertStringNotContainsString('16505551234', $row['raw_body']);
        $this->assertStringNotContainsString('message.received', $row['raw_body']);

        $event = WaWebhookEvent::sole();
        $this->assertSame($this->raw(), $event->raw_body);
        $this->assertArrayNotHasKey('raw_body', $event->toArray());
        $this->assertStringNotContainsString('raw_body', $event->toJson());
    }

    public function test_a_transient_store_failure_answers_503_and_stores_nothing(): void
    {
        $this->configureChannel(['reliability' => ['webhook_5xx' => true]]);
        // insertOrIgnore لا يمر بأحداث النموذج: القاعدة نفسها ترفض
        $this->failInsertsInto('wa_webhook_events');

        $this->postBody($this->raw())->assertStatus(503)->assertHeader('Retry-After', '30');

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
    }

    public function test_without_the_5xx_flag_a_store_failure_still_processes_the_payload(): void
    {
        $this->failInsertsInto('wa_webhook_events');

        $this->postBody($this->raw())->assertOk();

        $this->assertSame(1, WaMessage::count());
        $this->assertSame(0, WaWebhookEvent::count());
    }

    public function test_a_malformed_body_answers_200_is_kept_encrypted_and_never_replayed(): void
    {
        $this->postBody('{"event": [')->assertOk();

        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::MALFORMED, $event->status);
        $this->assertNotNull($event->getRawOriginal('raw_body'));

        $this->travel(10)->minutes();
        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(WaWebhookEvent::MALFORMED, $event->fresh()->status);
        $this->assertSame(1, $event->fresh()->attempts);
    }

    public function test_with_the_flag_off_nothing_is_stored(): void
    {
        config(['features.env.inbound_event_log' => false]);

        $this->postBody($this->raw())->assertOk();

        $this->assertSame(0, WaWebhookEvent::count());
        $this->assertSame(1, WaMessage::count());
    }

    public function test_failures_mode_stores_the_body_only_when_an_item_fails(): void
    {
        config(['whatsapp.webhook.log_mode' => 'failures']);

        $this->postBody($this->raw())->assertOk();
        $this->assertSame(0, WaWebhookEvent::count());

        WaMessage::creating(fn (WaMessage $m) => $m->wamid === 'false_16505551234@c.us_BBB2' ? throw new \RuntimeException('boom') : null);
        $this->postBody($this->raw('false_16505551234@c.us_BBB2'))->assertOk();

        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::FAILED, $event->status);
        $this->assertNotNull($event->getRawOriginal('raw_body'));
    }

    /** القاعدة ترفض الإدراج في الجدول (قرص ممتلئ): عطل عابر كما يراه الإنتاج */
    private function failInsertsInto(string $table): void
    {
        DB::connection()->beforeExecuting(function (string $query) use ($table) {
            if (str_starts_with(strtolower($query), 'insert') && str_contains($query, '"'.$table.'"')) {
                throw new QueryException('sqlite', $query, [], new \PDOException('database or disk is full'));
            }
        });
    }
}

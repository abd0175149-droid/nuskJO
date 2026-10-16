<?php

namespace Tests\Feature\Integrations;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\Client;
use App\Models\IntegrationOutboxRow;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\WhatsApp\Tools\ToolInvoker;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsIntegrations;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * التسجيل في الصندوق الصادر (E40.3): داخل معاملة الحدث نفسه، صف لكل وجهة نشطة مشتركة، والمعاملة المتراجعة لا تترك
 * صفاً ولا وظيفة، والتسجيل المكرر لا يكرر، ومع العلم مطفأ أو بلا وجهات لا شيء. وفشل التسجيل لا يُفشل طلب العميل.
 */
class OutboxRecordingTest extends TestCase
{
    use BuildsIntegrations;
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableOutbox();
    }

    private function pendingInvoice(): Invoice
    {
        $creator = User::factory()->create();
        $client = Client::factory()->create(['code' => 'CLT-777', 'is_active' => true]);
        $id = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-T-'.uniqid(), 'client_id' => $client->id, 'exchange_rate_snapshot' => 0.19,
            'invoice_date' => '2026-10-14', 'trip_date' => '2026-11-20', 'created_by' => $creator->id, 'status' => 'pending',
            'total_sell_jod' => 250, 'total_cost_jod' => 200, 'profit_jod' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::findOrFail($id);
    }

    public function test_an_invoice_approval_records_one_row_per_subscribed_active_endpoint(): void
    {
        Queue::fake();
        $this->seed(ChartOfAccountsSeeder::class);
        $a = $this->endpoint();
        $b = $this->endpoint(['name' => 'second', 'events' => ['invoice.approved'], 'include_amounts' => true]);
        $this->endpoint(['name' => 'quotes only', 'events' => ['quote_request.created']]);
        $this->endpoint(['name' => 'paused', 'is_active' => false]);
        $invoice = $this->pendingInvoice();

        $this->actingAs(User::factory()->admin()->create())->post("/invoices/{$invoice->id}/approve")->assertSessionHas('success');

        $invoice->refresh();
        $key = "invoice_{$invoice->id}_approved_{$invoice->approved_at->getTimestamp()}";
        $rows = IntegrationOutboxRow::orderBy('endpoint_id')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('endpoint_id')->all());
        $this->assertSame([$key, $key], $rows->pluck('idempotency_key')->all());
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all());

        $data = $rows[0]->payload['data'];
        $this->assertSame(['invoice_number', 'invoice_date', 'trip_date', 'offer_id', 'client_code', 'approved_at'], array_keys($data));
        $this->assertSame(['2026-10-14', '2026-11-20', 'CLT-777'], [$data['invoice_date'], $data['trip_date'], $data['client_code']]);
        $this->assertSame(250, (int) $rows[1]->payload['data']['total_sell_jod'], 'المبلغ للوجهة التي فعّلته وحدها');
        $this->assertSame(['type' => 'invoice.approved', 'id' => $key, 'schema' => 1], array_intersect_key($rows[0]->payload, array_flip(['type', 'id', 'schema'])));
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('profit', json_encode($row->payload));
            $this->assertStringNotContainsString('cost', json_encode($row->payload));
        }

        Queue::assertPushedOn('integrations', DeliverIntegrationWebhook::class);
        Queue::assertPushed(DeliverIntegrationWebhook::class, 2);
    }

    public function test_a_failed_accounting_entry_leaves_no_outbox_row_and_no_job(): void
    {
        Queue::fake();
        $this->endpoint();
        $invoice = $this->pendingInvoice();   // بلا شجرة حسابات: القيد يفشل فتتراجع المعاملة كلها

        $this->actingAs(User::factory()->admin()->create())->post("/invoices/{$invoice->id}/approve")->assertSessionHas('error');

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, IntegrationOutboxRow::count());
        Queue::assertNotPushed(DeliverIntegrationWebhook::class);
    }

    public function test_a_rolled_back_transaction_records_and_dispatches_nothing_and_a_committed_one_queues_after_commit(): void
    {
        config(['queue.default' => 'database']);
        $this->endpoint();

        try {
            DB::transaction(function () {
                IntegrationOutbox::record('invoice.approved', 'invoice_1_approved_1', ['invoice_number' => 'INV-1']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, IntegrationOutboxRow::count());
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(function () {
            IntegrationOutbox::record('invoice.approved', 'invoice_1_approved_2', ['invoice_number' => 'INV-1']);
            $this->assertSame(0, DB::table('jobs')->count(), 'لا وظيفة قبل الالتزام');
        });
        $this->assertSame(1, IntegrationOutboxRow::count());
        $this->assertSame(['integrations'], DB::table('jobs')->pluck('queue')->all());
    }

    public function test_recording_the_same_event_twice_keeps_one_row_and_one_job(): void
    {
        Queue::fake();
        $this->endpoint();

        $first = IntegrationOutbox::record('invoice.approved', 'invoice_1_approved_1', ['invoice_number' => 'INV-1']);
        $second = IntegrationOutbox::record('invoice.approved', 'invoice_1_approved_1', ['invoice_number' => 'INV-1']);

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, IntegrationOutboxRow::count());
        Queue::assertPushed(DeliverIntegrationWebhook::class, 1);
    }

    public function test_nothing_is_written_with_the_flag_off_or_without_a_subscribed_endpoint(): void
    {
        Queue::fake();
        IntegrationOutbox::record('invoice.approved', 'k1', ['invoice_number' => 'INV-1']);
        $this->assertSame(0, IntegrationOutboxRow::count(), 'بلا وجهات');

        $this->endpoint(['events' => ['quote_request.created']]);
        IntegrationOutbox::record('invoice.approved', 'k2', ['invoice_number' => 'INV-1']);
        $this->assertSame(0, IntegrationOutboxRow::count(), 'وجهة غير مشتركة');

        $this->endpoint();
        config(['features.env.integration_outbox' => false]);
        IntegrationOutbox::record('invoice.approved', 'k3', ['invoice_number' => 'INV-1']);
        $this->assertSame(0, IntegrationOutboxRow::count(), 'العلم مطفأ');
        Queue::assertNothingPushed();
    }

    public function test_a_bot_quote_is_recorded_without_customer_text_or_phone(): void
    {
        Queue::fake();
        $this->configureChannel();
        $this->fakeGraph();
        $this->endpoint();
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234']);
        DB::table('wa_conversation_topics')->insert(['conversation_id' => $conv->id, 'topic' => 'flights', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'بدي تسعيرة']);

        $out = ToolInvoker::run('request_quote', ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة', 'adults' => 2, 'details' => 'تفصيل خاص بالعميل'], $conv->fresh(), ['request_quote'])['output'];

        $qr = QuoteRequest::where('request_number', $out['request_number'])->sole();
        $row = IntegrationOutboxRow::sole();
        $this->assertSame("quote_{$qr->id}_created", $row->idempotency_key);
        $this->assertSame(['request_number', 'type', 'status', 'offer_id', 'offer_title', 'topics', 'created_at'], array_keys($row->payload['data']));
        $this->assertSame(['flights'], $row->payload['data']['topics']);
        $json = json_encode($row->payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('تفصيل خاص', $json);
        $this->assertStringNotContainsString('16505551234', $json);
    }

    public function test_a_failing_record_does_not_block_the_quote_or_the_handoff(): void
    {
        Queue::fake();
        $this->configureChannel();
        $this->fakeGraph();
        $this->endpoint();
        $this->captureLogs();
        Schema::drop('integration_outbox');   // أي عطل في الكتابة
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'bot_enabled' => true]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'بدي تسعيرة']);

        $out = ToolInvoker::run('request_quote', ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة', 'adults' => 1], $conv->fresh(), ['request_quote'])['output'];

        $this->assertArrayHasKey('request_number', $out);
        $this->assertSame(1, QuoteRequest::count());
        $this->assertFalse((bool) $conv->fresh()->bot_enabled, 'التحويل لموظف تم');
        $this->assertLogged('error', 'integrations.record_failed');
    }
}

<?php

namespace Tests\Feature\Invoices;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Rendering\HeadlessChromium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * إرسال الفاتورة على واتساب (E32.9): المعتمدة وحدها، إلى محادثة عميلها نفسه ضمن نطاق الموظف، بتأكيد المستلم ومفتاح،
 * مستنداً بالمعرّف باسم «فاتورة-رقم.pdf»، والحدث في سجل الفاتورة. وتنزيل PDF للمراجعة خلف العلم (والمدير يعاينه قبله).
 */
class InvoiceSendWhatsAppTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const KEY = '1b6f0e2a-8c3d-4e5f-a6b7-c8d9e0f1a2b3';

    private User $staff;

    private Client $client;

    private Invoice $invoice;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        config(['features.env.invoice_whatsapp_send' => true]);
        HeadlessChromium::fake(fn (string $html, string $out) => file_put_contents($out, "%PDF-1.4\n%%EOF\n"));
        $this->staff = User::factory()->withPermissions(['invoices.view', 'whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'], 'sales')->create();
        $this->client = Client::factory()->create(['name' => 'سعيد الخطيب', 'phone' => '+16505551234']);
        $this->invoice = $this->invoice('approved');
        $this->conv = WaConversation::factory()->windowOpen()->create(['client_id' => $this->client->id, 'phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    protected function tearDown(): void
    {
        HeadlessChromium::fake(null);
        parent::tearDown();
    }

    private function invoice(string $status, ?int $clientId = null): Invoice
    {
        static $n = 0;
        $n++;
        $id = DB::table('invoices')->insertGetId(['invoice_number' => "INV-2026-00{$n}", 'client_id' => $clientId ?? $this->client->id,
            'client_phone' => '0016505551234', 'exchange_rate_snapshot' => 0.189, 'invoice_date' => now()->toDateString(), 'trip_date' => now()->addMonth()->toDateString(),
            'status' => $status, 'total_sell_jod' => 300, 'total_jod' => 300, 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);

        return Invoice::findOrFail($id);
    }

    private function sendInvoice(array $data = [], ?Invoice $invoice = null, ?User $as = null, ?string $key = self::KEY)
    {
        $invoice ??= $this->invoice;

        return $this->actingAs($as ?? $this->staff)->postJson("/api/invoices/{$invoice->id}/send-whatsapp",
            $data + ['conversation_id' => $this->conv->id, 'confirm_conversation_id' => $this->conv->id],
            $key !== null ? ['Idempotency-Key' => $key] : []);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '4490709327384033']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.1']]]),
        ]);
    }

    public function test_an_approved_invoice_goes_as_a_document_and_lands_in_its_trail(): void
    {
        $this->fakeMeta();

        $this->sendInvoice(['caption' => 'تفضّل فاتورتك'])->assertStatus(202)->assertJson(['ok' => true, 'status' => 'queued', 'conversation_id' => $this->conv->id]);

        $row = WaMessage::sole();
        $name = 'فاتورة-'.$this->invoice->invoice_number.'.pdf';
        $this->assertSame(['sent', 'document', $name, $this->invoice->id, 'invoice'],
            [$row->status, $row->msg_type, $row->media_filename, $row->payload['_invoice_id'], $row->payload['_doc_kind']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages')
            && $r['document'] === ['id' => '4490709327384033', 'filename' => $name, 'caption' => 'تفضّل فاتورتك']);
        $this->assertMatchesRegularExpression('#^outbound/#', $row->media_path);

        $trail = AuditLog::where('action', 'wa.invoice_sent')->sole();
        $this->assertSame(['invoice', $this->invoice->id, $this->invoice->invoice_number, $this->staff->id],
            [$trail->entity_type, $trail->entity_id, $trail->entity_label, $trail->user_id]);
        $this->assertSame(1, AuditLog::where('action', 'wa.file_sent')->count());
    }

    public function test_the_default_caption_is_built_by_code(): void
    {
        $this->fakeMeta();

        $this->sendInvoice()->assertStatus(202);

        $this->assertStringStartsWith('فاتورتك رقم '.$this->invoice->invoice_number.' من ', WaMessage::sole()->payload['document']['caption']);
    }

    public function test_only_approved_invoices_are_sent(): void
    {
        Http::fake();

        $this->sendInvoice(invoice: $this->invoice('pending'))->assertStatus(422)->assertJsonPath('code', 'INVOICE_NOT_SENDABLE');

        $this->assertSame(0, WaMessage::count());
        Http::assertNothingSent();
    }

    public function test_another_clients_conversation_is_refused(): void
    {
        Http::fake();
        $other = WaConversation::factory()->windowOpen()->create(['client_id' => Client::factory()->create()->id]);

        $this->sendInvoice(['conversation_id' => $other->id, 'confirm_conversation_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'NOT_CLIENT_CONVERSATION');
        $this->sendInvoice(['confirm_conversation_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_MISMATCH');

        Http::assertNothingSent();
    }

    public function test_permissions_and_flag_are_enforced(): void
    {
        Http::fake();
        $noReply = User::factory()->withPermissions(['invoices.view', 'whatsapp.view'], 'accountant')->create();

        $this->sendInvoice(as: $noReply)->assertForbidden();
        $this->sendInvoice(key: null)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        config(['features.env.invoice_whatsapp_send' => false]);
        $this->sendInvoice()->assertNotFound();
        $this->actingAs($this->staff)->getJson("/api/invoices/{$this->invoice->id}/whatsapp-targets")->assertNotFound();
    }

    public function test_targets_show_name_last_four_and_window_only(): void
    {
        $response = $this->actingAs($this->staff)->getJson("/api/invoices/{$this->invoice->id}/whatsapp-targets")->assertOk();

        $this->assertSame([['conversation_id' => $this->conv->id, 'name' => 'سعيد الخطيب', 'last4' => '1234', 'client_code' => $this->client->code,
            'window_open' => true, 'window_left' => $this->conv->windowMinutesLeft()]], $response->json('targets'));
        $this->assertStringNotContainsString('16505551234', $response->getContent());
        $this->assertSame('فاتورة-'.$this->invoice->invoice_number.'.pdf', $response->json('filename'));
        $this->assertNull($response->json('message'));
    }

    public function test_no_open_conversation_explains_why(): void
    {
        $this->conv->update(['last_inbound_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->staff)->getJson("/api/invoices/{$this->invoice->id}/whatsapp-targets")->assertOk();

        $this->assertStringContainsString('لا توجد محادثة واتساب مفتوحة النافذة لهذا العميل', $response->json('message'));
        $this->assertFalse($response->json('targets.0.window_open'));
        $this->sendInvoice()->assertStatus(422)->assertJsonPath('code', 'WINDOW_EXPIRED');
    }

    public function test_a_conversation_matched_by_the_invoice_phone_counts(): void
    {
        // المرتبطة بالعميل برقم آخر، وغير المرتبطة برقم الفاتورة نفسه
        $this->conv->update(['phone' => '16505553333', 'wa_phone' => '16505553333']);
        $byPhone = WaConversation::factory()->windowOpen()->create(['client_id' => null, 'phone' => '16505551234', 'wa_phone' => '16505551234']);
        $merged = WaConversation::factory()->windowOpen()->create(['client_id' => $this->client->id, 'status' => 'merged']);

        $ids = array_column($this->actingAs($this->staff)->getJson("/api/invoices/{$this->invoice->id}/whatsapp-targets")->json('targets'), 'conversation_id');

        $this->assertEqualsCanonicalizing([$this->conv->id, $byPhone->id], $ids);
        $this->assertNotContains($merged->id, $ids);
    }

    public function test_the_pdf_download_is_behind_the_flag_with_an_admin_preview(): void
    {
        config(['features.env.invoice_whatsapp_send' => false]);
        $this->actingAs($this->staff)->get("/invoices/{$this->invoice->id}/pdf")->assertNotFound();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get("/invoices/{$this->invoice->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        config(['features.env.invoice_whatsapp_send' => true]);
        $this->actingAs($this->staff)->get("/invoices/{$this->invoice->id}/pdf")->assertOk();
    }
}

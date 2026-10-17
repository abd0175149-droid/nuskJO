<?php

namespace Tests\Feature\Invoices;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Invoices\InvoicePdfData;
use App\Services\Invoices\InvoicePdfService;
use App\Services\Rendering\HeadlessChromium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * فاتورة PDF للعميل بلا تكلفة (E32.8): قائمة سماح في InvoicePdfData، وقالب مستقل لا يرى النموذج. قيم مميزة لكل حقل
 * تكلفة وربح ووكيل وملاحظة داخلية يجب ألا تظهر في HTML القالب، وفاتورة العرض من جانب البيع في لقطتها.
 */
class InvoicePdfNoCostLeakTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINELS = ['987654.321', '987,654.321', '123456.789', '123,456.789', 'AGENT_SENTINEL_X', 'AGENT_SENTINEL_Y',
        '55555.5', '55,555.5', '4444.444', '4,444.444', '7.777', '31415.926', '31,415.926', 'NOTE_SENTINEL', 'SERVICES_SENTINEL'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
    }

    protected function tearDown(): void
    {
        HeadlessChromium::fake(null);
        parent::tearDown();
    }

    private function agent(string $name): int
    {
        return DB::table('agents')->insertGetId(['name' => $name, 'code' => 'AG-'.substr(md5($name), 0, 6), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function invoice(array $attrs): Invoice
    {
        $client = Client::where('code', 'C-10001')->first() ?? Client::factory()->create(['name' => 'سعيد الخطيب', 'code' => 'C-10001']);
        $id = DB::table('invoices')->insertGetId($attrs + [
            'invoice_number' => 'INV-2026-0007', 'client_id' => $client->id, 'exchange_rate_snapshot' => 0.189,
            'invoice_date' => '2026-10-15', 'trip_date' => '2026-11-20', 'status' => 'approved', 'total_sell_jod' => 1001.0, 'total_jod' => 1001.0,
            'total_cost_jod' => 987654.321, 'profit_jod' => 123456.789, 'services_cost_sar' => 31415.926, 'notes' => 'NOTE_SENTINEL',
            'original_values' => json_encode(['total_cost_jod' => 987654.321]), 'created_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::findOrFail($id);
    }

    private function costItem(Invoice $invoice, string $agent, string $description = 'فندق مكة'): void
    {
        InvoiceItem::create(['invoice_id' => $invoice->id, 'agent_id' => $this->agent($agent), 'item_type' => 'service', 'description' => $description,
            'statement' => 'SERVICES_SENTINEL', 'quantity' => 2, 'unit_price_sar' => 4444.44, 'unit_price_jod' => 4444.444, 'sell_price_jod' => 0,
            'total_cost_sar' => 55555.5, 'total_cost_jod' => 31415.926, 'total_sell_jod' => 0, 'sort_order' => 1,
            'cost_original' => 55555.5, 'fx_rate' => 7.777, 'cost_currency' => 'SAR', 'created_at' => now()]);
    }

    private function offerInvoice(): Invoice
    {
        $invoice = $this->invoice(['offer_lines' => json_encode([
            'offer_title' => 'عمرة رمضان',
            'lines' => [['key' => 'k1', 'sel' => [1 => 3], 'room' => 'double', 'pax' => 2, 'labels' => [['group' => 'الفندق', 'option' => 'فندق الصفوة']],
                'parts' => [['pp' => 250.5, 'cost' => 31415.926]], 'sell_pp' => 250.5]],
            'costs' => [['agent_id' => 9, 'total_jod' => 31415.926, 'total_original' => 55555.5]],
            'extras' => [['description' => 'تأشيرة', 'qty' => 2, 'sell' => 250]],
            'discount' => 0.5, 'sell' => 1000.5, 'cost' => 987654.321, 'pax' => 2,
        ], JSON_UNESCAPED_UNICODE), 'total_jod' => 1000.5, 'total_sell_jod' => 1000.5]);
        $this->costItem($invoice, 'AGENT_SENTINEL_X');

        return $invoice;
    }

    public function test_an_offer_invoice_shows_the_sale_side_only(): void
    {
        $html = InvoicePdfService::html($this->offerInvoice());

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $html, $sentinel);
        }
        foreach (['سعيد الخطيب', 'INV-2026-0007', 'عمرة رمضان — فندق الصفوة — ثنائية', '250.500', '501.000', 'تأشيرة', '-0.500 JOD', '1,000.500 JOD'] as $shown) {
            $this->assertStringContainsString($shown, $html, $shown);
        }
    }

    public function test_a_regular_invoice_never_lists_agents_or_costs(): void
    {
        $invoice = $this->invoice([]);
        $this->costItem($invoice, 'AGENT_SENTINEL_Y', 'تذكرة عمّان — جدة');

        $html = InvoicePdfService::html($invoice);

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $html, $sentinel);
        }
        $this->assertStringContainsString('تذكرة عمّان — جدة', $html);
        $this->assertStringContainsString('1,001.000 JOD', $html);
    }

    public function test_the_data_object_holds_allow_listed_keys_only(): void
    {
        $data = InvoicePdfData::from($this->offerInvoice())->toArray();

        $this->assertSame([], array_values(array_diff(array_keys($data), InvoicePdfData::KEYS)));
        foreach ($data['items'] as $item) {
            $this->assertSame(InvoicePdfData::ITEM_KEYS, array_keys($item));
        }
        $this->assertSame(['name', 'code'], array_keys($data['client']));
        $this->assertArrayNotHasKey('tax', $data['company']);
    }

    public function test_the_template_loads_nothing_from_the_network(): void
    {
        $html = InvoicePdfService::html($this->offerInvoice());

        $this->assertStringContainsString("<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; img-src data:;", $html);
        $this->assertStringContainsString('<html dir="rtl" lang="ar">', $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:|<script/i', $html);
    }

    public function test_a_pending_invoice_is_marked_as_a_draft(): void
    {
        $this->assertStringContainsString('مسودة — فاتورة غير معتمدة بعد', InvoicePdfService::html($this->invoice(['status' => 'pending'])));
        $this->assertStringNotContainsString('مسودة', InvoicePdfService::html($this->invoice(['invoice_number' => 'INV-2026-0008'])));
    }

    public function test_render_prints_once_caches_and_checks_the_output(): void
    {
        $calls = 0;
        HeadlessChromium::fake(function (string $html, string $out) use (&$calls) {
            $calls++;
            $this->assertStringNotContainsString('AGENT_SENTINEL_X', (string) file_get_contents($html));
            file_put_contents($out, "%PDF-1.4\n%%EOF\n");
        });
        $invoice = $this->offerInvoice();

        $first = InvoicePdfService::render($invoice);
        $second = InvoicePdfService::render($invoice);

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('#^invoices/[0-9a-f]{40}\.pdf$#', $first['path']);
        Storage::disk('wa_media')->assertExists($first['path']);
        $this->assertSame(hash('sha256', "%PDF-1.4\n%%EOF\n"), $first['sha256']);

        // تعديل الفاتورة يغيّر المفتاح فيُصيَّر من جديد
        $invoice->forceFill(['updated_at' => now()->addMinute()])->save();
        InvoicePdfService::render($invoice->fresh());
        $this->assertSame(2, $calls);
    }

    public function test_a_non_pdf_output_is_refused(): void
    {
        HeadlessChromium::fake(fn (string $html, string $out) => file_put_contents($out, '<html>not a pdf</html>'));

        $this->expectExceptionMessage('الناتج ليس ملف PDF.');
        InvoicePdfService::render($this->offerInvoice());
    }

    public function test_the_print_arguments_isolate_the_network_and_drop_browser_headers(): void
    {
        config(['services.chromium.net_isolation' => true]);
        $args = HeadlessChromium::pdfArguments('/usr/bin/chromium', '/tmp/p', '/tmp/i.html', '/tmp/i.pdf');

        foreach (['--headless', '--no-sandbox', '--no-pdf-header-footer', '--print-to-pdf=/tmp/i.pdf', '--user-data-dir=/tmp/p', '--proxy-server=127.0.0.1:9'] as $flag) {
            $this->assertContains($flag, $args);
        }
        $this->assertSame('file:///tmp/i.html', end($args));
    }

    /** كروميوم الفعلي: يُشغَّل حيث يوجد (الحاوية، أو CHROMIUM_TEST_PATH محلياً)، ويُتخطّى غير ذلك */
    #[Group('chromium')]
    public function test_real_chromium_prints_an_arabic_pdf(): void
    {
        $path = getenv('CHROMIUM_TEST_PATH') ?: null;
        if ($path) {
            config(['services.chromium.path' => $path]);
        }
        try {
            HeadlessChromium::binary();
        } catch (\RuntimeException) {
            $this->markTestSkipped('لا كروميوم في هذه البيئة.');
        }

        $pdf = InvoicePdfService::render($this->offerInvoice());

        $bytes = (string) Storage::disk('wa_media')->get($pdf['path']);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }
}

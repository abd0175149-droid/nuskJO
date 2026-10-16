<?php

namespace Tests\Feature\Reports;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Services\Reports\OwnerReports;
use App\Services\WhatsApp\AdminTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * استخراج تقارير المالك (E40.8): المساعد الإداري على واتساب يعطي بعد الاستخراج ما كان يعطيه قبله حرفياً (المرجع
 * LegacyAdminReports)، والنسخة المقيّدة لـ MCP بلا عدّادات واتساب ولا هواتف ولا نص طلب ولا اسم كتبه العميل.
 */
class OwnerReportsParityTest extends TestCase
{
    use RefreshDatabase;

    private WaConversation $conv;

    private WaAdminNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seedData();
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505553333', 'is_admin_chat' => true]);
        $this->number = WaAdminNumber::factory()->create(['phone' => '16505553333']);
    }

    private function seedData(): void
    {
        $creator = User::factory()->create(['name' => 'منشئ']);
        $seller = User::factory()->create(['name' => 'بائع أول']);
        $employee = DB::table('employees')->insertGetId(['employee_number' => 'E-1', 'user_id' => $seller->id, 'hire_date' => '2024-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('accounts')->insertGetId(['code' => '1200-9', 'name' => 'ذمة', 'type' => 'asset', 'balance' => 75.5, 'created_at' => now(), 'updated_at' => now()]);
        $withAccount = Client::factory()->create(['name' => 'عميل الرصيد', 'code' => 'CLT-041', 'phone' => '16505551234', 'account_id' => $account]);
        $plain = Client::factory()->create(['name' => 'عميل بلا حساب', 'code' => 'CLT-042', 'phone' => '15550783881']);
        $today = now()->toDateString();

        $invoice = fn (array $a) => DB::table('invoices')->insertGetId($a + [
            'invoice_number' => 'INV-'.uniqid(), 'client_id' => $withAccount->id, 'exchange_rate_snapshot' => 0.19,
            'invoice_date' => $today, 'created_by' => $creator->id, 'status' => 'approved', 'approved_at' => now(),
            'total_sell_jod' => 100, 'profit_jod' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice(['invoice_number' => 'INV-A1', 'trip_date' => $today, 'sold_by' => $employee, 'client_phone' => '16505551234']);
        $invoice(['invoice_number' => 'INV-A2', 'trip_date' => $today, 'total_sell_jod' => 250.125, 'profit_jod' => 40, 'client_id' => $plain->id]);
        $invoice(['invoice_number' => 'INV-P1', 'status' => 'pending', 'approved_at' => null, 'total_sell_jod' => 60]);
        DB::table('invoice_items')->insert(['invoice_id' => 1, 'item_type' => 'service', 'description' => 'تذكرة', 'unit_price_sar' => 0, 'sell_price_jod' => 100,
            'total_cost_sar' => 0, 'total_sell_jod' => 100, 'quantity' => 2]);

        DB::table('receipts')->insert([
            ['receipt_number' => 'RCP-1', 'client_id' => $withAccount->id, 'amount_jod' => 40, 'payment_method' => 'cash', 'receipt_date' => $today, 'created_by' => $creator->id, 'status' => 'approved', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['receipt_number' => 'RCP-2', 'client_id' => $plain->id, 'amount_jod' => 15.5, 'payment_method' => 'cash', 'receipt_date' => $today, 'created_by' => $creator->id, 'status' => 'pending', 'approved_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('disbursements')->insert(['disbursement_number' => 'DSB-1', 'account_id' => $account, 'amount' => 30, 'currency' => 'JOD', 'payment_method' => 'cash',
            'disbursement_date' => $today, 'description' => 'مصروف مكتب', 'created_by' => $creator->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'last_inbound_at' => now()]);
        QuoteRequest::factory()->forConversation($conv)->create(['status' => 'new', 'details' => 'نص كتبه العميل عن رحلته', 'customer_name' => 'اسم من واتساب', 'assigned_to' => $seller->id]);
        QuoteRequest::factory()->create(['status' => 'priced', 'client_id' => $withAccount->id, 'details' => 'تفصيل آخر']);
        QuoteRequest::factory()->create(['status' => 'won']);
    }

    private function admin(string $tool, array $in = []): array
    {
        return AdminTools::execute($tool, $in, $this->conv->fresh(), $this->number->fresh());
    }

    public function test_the_admin_assistant_output_is_unchanged_for_all_six_reports(): void
    {
        $cases = [
            ['get_daily_summary', [], fn ($in) => LegacyAdminReports::dailySummary($in)],
            ['get_sales_report', [], fn ($in) => LegacyAdminReports::salesReport($in)],
            ['get_sales_report', ['from' => '2026-01-01', 'to' => '2026-12-31'], fn ($in) => LegacyAdminReports::salesReport($in)],
            ['get_travelers', [], fn ($in) => LegacyAdminReports::travelers($in)],
            ['get_pending_approvals', [], fn ($in) => LegacyAdminReports::pendingApprovals($in)],
            ['get_open_quotes', [], fn ($in) => LegacyAdminReports::openQuotes($in)],
            ['find_client', ['query' => 'عميل'], fn ($in) => LegacyAdminReports::findClient($in)],
            ['find_client', ['query' => '0016505551234'], fn ($in) => LegacyAdminReports::findClient($in)],
            ['find_client', ['query' => 'لا أحد'], fn ($in) => LegacyAdminReports::findClient($in)],
            ['find_client', ['query' => 'ع'], fn ($in) => LegacyAdminReports::findClient($in)],
        ];

        foreach ($cases as [$tool, $in, $legacy]) {
            $this->assertSame($legacy($in), $this->admin($tool, $in), $tool.' '.json_encode($in, JSON_UNESCAPED_UNICODE));
        }
        // البيانات تُنتج نتائج فعلاً (لا تكافؤ بين قائمتين فارغتين)
        $this->assertSame(2, $this->admin('get_travelers')['count']);
        $this->assertSame(2, $this->admin('get_open_quotes')['count']);
        $this->assertSame(1, $this->admin('find_client', ['query' => '0016505551234'])['count']);
    }

    public function test_the_restricted_variants_drop_whatsapp_counters_phones_and_request_text(): void
    {
        $this->assertArrayNotHasKey('whatsapp', OwnerReports::dailySummary(withWhatsapp: false));
        $this->assertArrayHasKey('whatsapp', OwnerReports::dailySummary(withWhatsapp: true));

        $travelers = OwnerReports::travelers(now()->toDateString(), withPhones: false);
        $this->assertSame(['client', 'invoice', 'pax', 'remaining_jod'], array_keys($travelers['travelers'][0]));

        $quotes = OwnerReports::openQuotes(withDetails: false);
        $this->assertSame(['request_number', 'type', 'status', 'offer_title', 'assigned_to', 'since'], array_keys($quotes['requests'][0]));
        $json = json_encode($quotes, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('نص كتبه العميل', $json);
        $this->assertStringNotContainsString('اسم من واتساب', $json);
        $this->assertStringNotContainsString('عميل الرصيد', $json);

        $clients = OwnerReports::findClient('عميل', withPhones: false);
        $this->assertSame(2, $clients['count']);
        $this->assertArrayNotHasKey('phone', $clients['clients'][0]);
        $this->assertStringNotContainsString('16505551234', json_encode($clients));
        $this->assertSame(0, OwnerReports::findClient('16505551234', withPhones: false)['count'], 'لا بحث بالرقم بلا هواتف');
    }
}

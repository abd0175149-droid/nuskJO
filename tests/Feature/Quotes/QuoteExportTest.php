<?php

namespace Tests\Feature\Quotes;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تصدير طلبات التسعير CSV (E24): العلم والصلاحية quotes.export في الخادم، ومدى سنة على الأكثر، وأعمدة بيضاء
 * بترويسات عربية بلا هاتف ولا اسم عميل ولا تفاصيل، وتعقيم الصيغ، وتدقيق بعدد الصفوف قبل البث.
 */
class QuoteExportTest extends TestCase
{
    use RefreshDatabase;

    private User $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.quote_export' => true]);
        $this->exporter = User::factory()->withPermissions(['quotes.view', 'quotes.export'], 'accountant')->create(['name' => 'المحاسب']);
    }

    private function quote(array $attrs = []): QuoteRequest
    {
        $created = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);
        $q = QuoteRequest::create($attrs + [
            'request_number' => QuoteRequest::nextNumber(), 'phone' => '962790000123', 'customer_name' => 'زبون سري',
            'type' => 'flight', 'details' => 'تفاصيل خاصة بالعميل', 'status' => 'new', 'route_from' => 'AMM', 'route_to' => 'JED',
            'quoted_note' => 'ملاحظة داخلية',
        ]);
        if ($created) {
            $q->forceFill(['created_at' => $created])->save();
        }

        return $q;
    }

    private function export(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->exporter)->get('/quote-requests/export?'.http_build_query($query + ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]));
    }

    public function test_without_the_permission_it_is_forbidden_and_without_the_flag_missing(): void
    {
        $viewer = User::factory()->withPermissions(['quotes.view'], 'sales')->create();
        $this->export([], $viewer)->assertForbidden();

        config(['features.env.quote_export' => false]);
        $this->export()->assertNotFound();
    }

    public function test_a_range_over_a_year_or_reversed_is_refused(): void
    {
        $this->actingAs($this->exporter)->get('/quote-requests/export?from=2025-01-01&to=2026-01-05')->assertSessionHasErrors('to');
        $this->actingAs($this->exporter)->getJson('/quote-requests/export?from=2026-02-01&to=2026-01-01')->assertStatus(422);
        $this->actingAs($this->exporter)->getJson('/quote-requests/export')->assertStatus(422);
        $this->assertSame(0, AuditLog::where('action', AuditAction::QuoteExport->value)->count());
    }

    public function test_the_file_has_a_bom_arabic_headers_and_white_listed_columns_only(): void
    {
        $offer = Offer::create(['title' => '=HYPERLINK("http://x.test","اضغط")', 'category' => 'umrah', 'is_active' => true]);
        $quoter = User::factory()->create(['name' => 'المسعّر']);
        $this->quote(['offer_id' => $offer->id, 'status' => 'priced', 'quoted_price_jod' => 450.5, 'quoted_by' => $quoter->id, 'quoted_at' => now(), 'assigned_to' => $quoter->id]);
        $this->quote(['type' => 'visa', 'created_at' => now()->subDays(60)]);   // خارج المدى

        $res = $this->export();
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('quote-requests_', $res->headers->get('Content-Disposition'));
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(2, $lines, 'ترويسة وصف واحد في المدى');
        $this->assertSame(['رقم الطلب', 'التاريخ', 'النوع', 'الحالة', 'العرض', 'الموكَّل', 'المُسعِّر', 'السعر المعروض (د.أ)', 'وقت التسعير', 'رقم الفاتورة'], str_getcsv($lines[0]));
        $row = str_getcsv($lines[1]);
        $this->assertSame('تذاكر', $row[2]);
        $this->assertSame('مسعّر', $row[3]);
        $this->assertSame("'=HYPERLINK(\"http://x.test\",\"اضغط\")", $row[4], 'الصيغة تخرج معقّمة');
        $this->assertSame(['المسعّر', 'المسعّر', '450.500'], [$row[5], $row[6], $row[7]]);
        foreach (['962790000123', 'زبون سري', 'تفاصيل خاصة', 'ملاحظة داخلية', 'AMM', 'JED'] as $leak) {
            $this->assertStringNotContainsString($leak, $csv);
        }
    }

    public function test_the_export_is_audited_with_the_row_count_before_streaming(): void
    {
        $this->quote();
        $this->quote(['status' => 'won']);

        $this->export(['status' => 'won'])->assertOk();

        $audit = AuditLog::where('action', AuditAction::QuoteExport->value)->sole();
        $this->assertSame(1, $audit->changes['rows']);
        $this->assertSame('won', $audit->changes['status']);
        $this->assertSame($this->exporter->id, (int) $audit->user_id);
    }

    public function test_the_row_cap_truncates_with_a_note(): void
    {
        config(['audit.export_max_rows' => 2]);
        foreach (range(1, 3) as $i) {
            $this->quote();
        }

        $csv = $this->export()->streamedContent();

        $this->assertStringContainsString('اقتُطع التصدير عند 2', $csv);
        $this->assertSame(2, AuditLog::where('action', AuditAction::QuoteExport->value)->sole()->changes['rows']);
    }

    public function test_the_index_passes_can_export_only_with_permission_and_flag(): void
    {
        $this->actingAs($this->exporter)->get('/quote-requests')->assertInertia(fn ($p) => $p->where('canExport', true));
        config(['features.env.quote_export' => false]);
        $this->actingAs($this->exporter)->get('/quote-requests')->assertInertia(fn ($p) => $p->where('canExport', false));
    }

    public function test_the_permission_is_granted_to_admin_by_the_migration_only(): void
    {
        $id = DB::table('permissions')->where('slug', 'quotes.export')->value('id');
        $this->assertNotNull($id);
        $admin = DB::table('roles')->where('slug', 'admin')->value('id');
        if ($admin) {
            $this->assertTrue(DB::table('role_permissions')->where(['role_id' => $admin, 'permission_id' => $id])->exists());
        }
        $this->assertContains('quote-requests.export', config('security.stepup.routes'));
    }
}

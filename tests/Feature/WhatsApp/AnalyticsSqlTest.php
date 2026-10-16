<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\PeriodAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * تحليلات الفترات بتجميع SQL (E24، العلم analytics_v2): الحركة تطابق العدّ اليدوي بلا التجريبي وغير المسلَّم،
 * وساعات الذروة بتوقيت عمّان، والتحويلات بلا عدّ مزدوج، والكلفة تشمل admin وvoice، وتحوّل الطلب إلى فاتورة،
 * وأول رد بشري من أزواج WaHandoff.
 */
class AnalyticsSqlTest extends TestCase
{
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.analytics_v2' => true]);
        $this->travelTo(Carbon::parse('2026-10-16 09:00:00', 'UTC'));
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function msg(string $direction, string $source, Carbon $at, array $attrs = []): WaMessage
    {
        return WaMessage::factory()->create($attrs + ['conversation_id' => $this->conv->id, 'direction' => $direction, 'source' => $source,
            'status' => $direction === 'in' ? 'received' : 'sent', 'created_at' => $at]);
    }

    public function test_traffic_matches_a_manual_count_without_test_or_undelivered(): void
    {
        $this->msg('in', 'customer', now()->subDays(1));
        $this->msg('in', 'customer', now()->subDays(2));
        $this->msg('out', 'bot', now()->subDays(1));
        $this->msg('out', 'staff', now()->subDays(1));
        $this->msg('out', 'system', now()->subDays(3));
        $this->msg('out', 'bot', now()->subDays(1), ['status' => 'failed']);
        $this->msg('out', 'test', now()->subDays(1));
        $this->msg('in', 'customer', now()->subDays(9));   // خارج 7 أيام

        $t = PeriodAnalytics::traffic('7d');

        $this->assertSame(['in' => 2, 'out' => 3, 'bot' => 1, 'staff' => 1, 'system' => 1, 'rule' => 0], $t['totals']);
        $this->assertCount(7, $t['series']);
        $yesterday = collect($t['series'])->firstWhere('key', now('Asia/Amman')->subDay()->toDateString());
        $this->assertSame([1, 2], [$yesterday['in'], $yesterday['out']]);
        $this->assertCount(24, PeriodAnalytics::traffic('24h')['series'], 'فترة 24 ساعة بالساعة');
    }

    public function test_the_peak_map_puts_sunday_2130_utc_on_monday_midnight_amman(): void
    {
        $this->msg('in', 'customer', Carbon::parse('2026-10-11 21:30:00', 'UTC'));   // الأحد 21:30 UTC = الإثنين 00:30 عمّان
        $this->msg('out', 'bot', Carbon::parse('2026-10-11 21:31:00', 'UTC'));       // الصادر لا يدخل الخريطة

        $peak = PeriodAnalytics::peakHours('7d');

        $this->assertSame(1, $peak['grid'][1][0]);
        $this->assertSame(0, $peak['grid'][0][21]);
        $this->assertSame(1, $peak['max']);
    }

    public function test_handoffs_are_not_counted_twice(): void
    {
        $this->conv->update(['needs_attention' => true]);
        QuoteRequest::create(['request_number' => 'QR-1', 'conversation_id' => $this->conv->id, 'phone' => '1', 'type' => 'flight', 'status' => 'new']);
        QuoteRequest::create(['request_number' => 'QR-2', 'conversation_id' => $this->conv->id, 'phone' => '1', 'type' => 'visa', 'status' => 'new']);

        $handoff = fn () => collect(PeriodAnalytics::quality('7d')['metrics'])->firstWhere('key', 'handoff');
        $this->assertSame(1, $handoff()['value'], 'محادثة واحدة فيها تدخّل وطلبان');
        $this->assertTrue($handoff()['estimated']);

        // مع تدقيق WaHandoff: المحادثات المميزة في الفترة
        DB::table('audit_logs')->insert([
            ['action' => AuditAction::WaHandoff->value, 'entity_type' => 'wa_conversation', 'entity_id' => $this->conv->id, 'created_at' => now()->subHours(3)],
            ['action' => AuditAction::WaHandoff->value, 'entity_type' => 'wa_conversation', 'entity_id' => $this->conv->id, 'created_at' => now()->subHours(2)],
        ]);
        Cache::flush();
        $this->assertSame(1, $handoff()['value']);
        $this->assertFalse($handoff()['estimated']);
    }

    public function test_usage_covers_admin_and_voice_and_prices_each_model(): void
    {
        foreach (['live' => 1_000_000, 'admin' => 2_000_000, 'voice' => 1_000_000, 'playground' => 5_000_000] as $source => $tokens) {
            WaBotUsage::create(['conversation_id' => $this->conv->id, 'source' => $source, 'model' => 'gemini-2.5-flash',
                'prompt_tokens' => $tokens, 'output_tokens' => 0, 'created_at' => now()->subDay()]);
        }
        WaBotUsage::create(['source' => 'live', 'model' => 'gemini-2.5-flash', 'prompt_tokens' => 1_000_000, 'created_at' => now()->subDays(20)]);

        $u = PeriodAnalytics::usage('7d');

        $this->assertEqualsWithDelta(1.2, $u['tiles']['cost_30'], 0.000001, '4M إدخال × 0.30$ (live وadmin وvoice)');
        $this->assertEqualsWithDelta(1.5, $u['tiles']['cost_total'], 0.000001);
        $this->assertEqualsWithDelta(1.5, $u['tiles']['playground_cost'], 0.000001);
        $this->assertSame(3, $u['tiles']['replies']);
        $this->assertSame(['cost' => 0.6, 'replies' => 1], $u['by_source']['admin']);
        $this->assertSame(['cost' => 0.3, 'replies' => 1], $u['by_source']['voice']);
        $this->assertSame($this->conv->id, $u['top_conversations'][0]['id']);
        $this->assertCount(7, $u['series']);
    }

    public function test_quote_conversion_counts_invoiced_requests_and_their_approved_value(): void
    {
        $user = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $invoice = fn (string $status, float $total) => DB::table('invoices')->insertGetId(['invoice_number' => 'INV-'.$status.$total, 'client_id' => $client->id,
            'exchange_rate_snapshot' => 0.19, 'invoice_date' => now()->toDateString(), 'status' => $status, 'created_by' => $user->id,
            'total_sell_jod' => $total, 'created_at' => now(), 'updated_at' => now()]);
        QuoteRequest::create(['request_number' => 'QR-A', 'phone' => '1', 'type' => 'flight', 'status' => 'won', 'invoice_id' => $invoice('approved', 500.5)]);
        QuoteRequest::create(['request_number' => 'QR-B', 'phone' => '1', 'type' => 'flight', 'status' => 'priced', 'invoice_id' => $invoice('draft', 900)]);
        QuoteRequest::create(['request_number' => 'QR-C', 'phone' => '1', 'type' => 'visa', 'status' => 'new']);

        $q = PeriodAnalytics::quoteConversion('30d');

        $this->assertSame(3, $q['requests']);
        $this->assertSame(2, $q['invoiced']);
        $this->assertSame(66.7, $q['rate']);
        $this->assertSame(1, $q['won']);
        $this->assertSame(500.5, $q['invoiced_value_jod'], 'المعتمدة وحدها');
        $this->assertSame(['type' => 'flight', 'label' => 'تذاكر', 'requests' => 2, 'invoiced' => 2], collect($q['by_type'])->firstWhere('type', 'flight'));
    }

    public function test_first_human_response_pairs_each_handoff_with_the_next_staff_reply(): void
    {
        $other = WaConversation::factory()->windowOpen()->create();
        foreach ([[$this->conv, 120], [$other, 600]] as [$c, $wait]) {
            $at = now()->subHours(5);
            DB::table('audit_logs')->insert(['action' => AuditAction::WaHandoff->value, 'entity_type' => 'wa_conversation', 'entity_id' => $c->id, 'created_at' => $at]);
            WaMessage::factory()->create(['conversation_id' => $c->id, 'direction' => 'out', 'source' => 'staff', 'status' => 'sent', 'created_at' => $at->copy()->subMinutes(10)]);
            WaMessage::factory()->create(['conversation_id' => $c->id, 'direction' => 'out', 'source' => 'staff', 'status' => 'sent', 'created_at' => $at->copy()->addSeconds($wait)]);
        }
        $third = WaConversation::factory()->windowOpen()->create();
        DB::table('audit_logs')->insert(['action' => AuditAction::WaHandoff->value, 'entity_type' => 'wa_conversation', 'entity_id' => $third->id, 'created_at' => now()->subHour()]);

        $r = PeriodAnalytics::firstHumanResponse('24h');

        $this->assertSame(3, $r['handoffs']);
        $this->assertSame(2, $r['answered']);
        $this->assertSame(1, $r['unanswered']);
        $this->assertSame(360, $r['median_s']);
        $this->assertSame(600, $r['p90_s']);
        $this->assertFalse($r['estimated']);
    }

    public function test_reply_latency_and_unanswered_follow_the_old_definitions(): void
    {
        $t = now()->subHours(3);
        $this->msg('in', 'customer', $t);
        $this->msg('out', 'system', $t->copy()->addSeconds(5));     // النظام لا يُعدّ رداً
        $this->msg('out', 'bot', $t->copy()->addSeconds(30));
        $this->msg('in', 'customer', $t->copy()->addMinutes(5));
        $this->msg('in', 'customer', $t->copy()->addMinutes(6));    // الأولى بلا جواب
        $this->msg('out', 'staff', $t->copy()->addMinutes(8));
        $this->msg('in', 'customer', $t->copy()->addMinutes(20));   // الأخيرة: لا تُعدّ

        $metrics = collect(PeriodAnalytics::quality('24h')['metrics'])->keyBy('key');

        $this->assertSame(1, $metrics['unanswered']['value']);
        $this->assertSame(75, $metrics['median']['value'], 'وسيط 30 و120 ثانية');
        $this->assertEquals(50, $metrics['automation']['value']);
    }

    public function test_the_page_takes_a_period_and_validates_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/whatsapp/analytics?period=24h')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('WhatsApp/Analytics', false)->where('v2', true)->where('period', '24h')
                ->has('traffic.series.series', 24)->has('traffic.peak.grid', 7)->has('metaCost.service_free_tier'));
        $this->actingAs($admin)->getJson('/api/whatsapp/analytics?period=1y')->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/whatsapp/analytics')->assertOk()->assertJsonPath('period', '7d');

        config(['features.env.analytics_v2' => false]);
        $this->actingAs($admin)->getJson('/api/whatsapp/analytics')->assertOk()->assertJsonPath('v2', false)->assertJsonMissingPath('traffic');
    }

    public function test_top_conversations_stay_within_scope_and_mask_numbers(): void
    {
        $this->conv->update(['display_name' => null, 'wa_profile_name' => null, 'phone' => '962791234567']);
        $this->msg('in', 'customer', now()->subHour());
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.settings'], 'supervisor')->create();

        $this->assertSame([], PeriodAnalytics::topConversations('7d', $staff), 'بلا اختصاص لا يرى المحادثة');
        $admin = User::factory()->admin()->create();
        $this->assertSame('9627•••4567', PeriodAnalytics::topConversations('7d', $admin)[0]['name']);
    }

    public function test_meta_analytics_reconcile_is_read_daily_and_shown_for_comparison_only(): void
    {
        config(['features.env.meta_analytics_reconcile' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        WaBotSetting::current()->fill(['wa_token' => 'test-token', 'wa_phone_number_id' => '106540352242922'])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['analytics' => ['granularity' => 'DAY',
            'data_points' => [['start' => 1, 'end' => 2, 'sent' => 40, 'delivered' => 38]]], 'id' => '102290129340398'])]);

        $this->artisan('whatsapp:meta-analytics')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'fields=analytics.start(') && $r->method() === 'GET');
        $meta = PeriodAnalytics::metaCost('7d')['meta'];
        $this->assertSame(['ok' => true, 'meta_sent' => 40, 'meta_delivered' => 38], array_intersect_key($meta, array_flip(['ok', 'meta_sent', 'meta_delivered'])));

        config(['features.env.meta_analytics_reconcile' => false]);
        $this->assertNull(PeriodAnalytics::metaCost('7d')['meta']);
    }
}

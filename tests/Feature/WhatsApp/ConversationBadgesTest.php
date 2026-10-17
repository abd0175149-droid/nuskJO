<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\ConversationBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * شارات النظام في القائمة (E29.5): محسوبة في الخادم بلا تخزين. «عليه رصيد» لمن يملك accounting.view وحده وبلا مبلغ،
 * والسفر والفاتورة لمن يملك invoices.view، ولا شارة مالية لمحادثة بلا رقم موثوق، وعدد الاستعلامات ثابت للصفحة.
 */
class ConversationBadgesTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $creator;

    private int $accounts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.wa_labels' => true]);
        $this->creator = User::factory()->create();
    }

    private function client(float $balance = 0): Client
    {
        $account = DB::table('accounts')->insertGetId(['code' => '19'.str_pad((string) ++$this->accounts, 5, '0', STR_PAD_LEFT), 'name' => 'ذمة', 'type' => 'asset',
            'balance' => $balance, 'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);

        return Client::factory()->create(['account_id' => $account]);
    }

    private function invoice(Client $client, string $status, ?string $tripDate, ?string $number = null): void
    {
        DB::table('invoices')->insert(['invoice_number' => $number ?? 'INV-'.\Illuminate\Support\Str::ulid(), 'client_id' => $client->id,
            'exchange_rate_snapshot' => 0.189, 'invoice_date' => now()->toDateString(), 'trip_date' => $tripDate, 'status' => $status,
            'total_sell_jod' => 300, 'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function badges(User $user, WaConversation $conv): array
    {
        $row = collect($this->actingAs($user)->getJson('/api/whatsapp/conversations')->assertOk()->json('conversations'))->firstWhere('id', $conv->id);

        return array_column($row['badges'], 'label', 'key');
    }

    public function test_the_balance_badge_needs_accounting_view_and_carries_no_amount(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $accountant = $this->staffWith('accountant-inbox', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'accounting.view']);
        $owing = $this->conversationTagged([], ['client_id' => $this->client(120.5)->id]);
        $credit = $this->conversationTagged([], ['client_id' => $this->client(-40)->id]);

        $this->assertArrayNotHasKey('balance', $this->badges($agent, $owing));
        $this->assertSame('عليه رصيد', $this->badges($accountant, $owing)['balance']);
        $this->assertArrayNotHasKey('balance', $this->badges($accountant, $credit), 'له رصيد: لا شارة');
        $json = $this->actingAs($accountant)->getJson('/api/whatsapp/conversations')->getContent();
        $this->assertStringNotContainsString('120.5', $json);
    }

    public function test_trip_and_pending_invoice_badges_need_invoices_view(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $sales = $this->staffWith('sales-invoices', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view']);
        $soon = $this->client();
        $this->invoice($soon, 'approved', now()->addDays(3)->toDateString());
        $this->invoice($soon, 'pending', now()->addDays(40)->toDateString());
        $edge = $this->client();
        // آخر يوم في النافذة، مخزّناً كما يكتبه cast التاريخ
        $this->invoice($edge, 'approved', now()->addDays(ConversationBadges::TRIP_DAYS)->format('Y-m-d 00:00:00'));
        $far = $this->client();
        $this->invoice($far, 'approved', now()->addDays(30)->toDateString());
        $this->invoice($far, 'rejected', now()->addDays(2)->toDateString());
        $convSoon = $this->conversationTagged([], ['client_id' => $soon->id]);
        $convEdge = $this->conversationTagged([], ['client_id' => $edge->id]);
        $convFar = $this->conversationTagged([], ['client_id' => $far->id]);

        $this->assertSame([], $this->badges($agent, $convSoon));
        $this->assertSame(['trip' => 'سفر بعد 3 أيام', 'invoice_pending' => 'فاتورة بانتظار الاعتماد'], $this->badges($sales, $convSoon));
        $this->assertSame(['trip' => 'سفر بعد 14 يوماً'], $this->badges($sales, $convEdge));
        $this->assertSame([], $this->badges($sales, $convFar));
        $this->assertSame('سفر اليوم', ConversationBadges::tripLabel(0));
        $this->assertSame('سفر غداً', ConversationBadges::tripLabel(1));
    }

    public function test_a_conversation_without_a_trusted_number_gets_no_financial_badge(): void
    {
        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view', 'accounting.view']);
        $client = $this->client(99);
        $this->invoice($client, 'pending', now()->addDay()->toDateString());
        $phoneless = $this->conversationTagged([], ['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918',
            'client_id' => $client->id, 'client_link' => 'manual']);
        QuoteRequest::create(['request_number' => 'QR-20261016-0001', 'conversation_id' => $phoneless->id, 'phone' => '', 'type' => 'flight', 'pax_adults' => 1, 'status' => 'priced']);

        $this->assertSame(['quote' => 'طلب تسعير مفتوح'], $this->badges($boss, $phoneless), 'طلب التسعير ليس مالياً؛ السفر والفاتورة والرصيد لا');
    }

    public function test_open_quotes_only_and_a_constant_number_of_queries_per_page(): void
    {
        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view', 'accounting.view']);
        $make = function (int $n) {
            $convs = collect();
            for ($i = 0; $i < $n; $i++) {
                $client = $this->client($i % 2 ? 10 : 0);
                $this->invoice($client, $i % 3 ? 'approved' : 'pending', now()->addDays($i % 20)->toDateString());
                $conv = WaConversation::factory()->create(['client_id' => $client->id]);
                QuoteRequest::create(['request_number' => 'QR-'.$n.'-'.$i, 'conversation_id' => $conv->id, 'phone' => '', 'type' => 'flight', 'pax_adults' => 1,
                    'status' => ['new', 'priced', 'won'][$i % 3]]);
                $convs->push($conv);
            }

            return $convs;
        };
        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting) {
            $queries += $counting ? 1 : 0;
        });
        $count = function ($convs) use ($boss, &$queries, &$counting) {
            Cache::flush();
            $boss->can('invoices.view');
            [$queries, $counting] = [0, true];
            $result = ConversationBadges::forPage($convs, $boss);
            $counting = false;

            return [$queries, $result];
        };

        [$small] = $count($make(10));
        [$large, $result] = $count($make(80));
        $this->assertSame($small, $large, 'عدد الاستعلامات لا يكبر مع الصفحة');
        $this->assertLessThanOrEqual(3, $large);
        $withQuote = collect($result)->filter(fn ($b) => in_array('quote', array_column($b, 'key'), true))->count();
        $this->assertSame(54, $withQuote, 'new وpriced وحدهما مفتوحان (ثلثا الثمانين تقريباً)');
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaLabel;
use App\Models\WaMessage;
use App\Models\WaThreadPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * بطاقة الحقائق (E29.7): كل تفصيل بصلاحيته، والرصيد لمن يملك accounting.view وحده، والمحادثة بلا رقم موثوق بلا أي
 * بيان مالي مع تلميح «غير مربوطة بعميل»، والتثبيت والتصنيفات معها.
 */
class ConversationFactsTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.thread_pins' => true, 'features.env.wa_labels' => true]);
        $account = DB::table('accounts')->insertGetId(['code' => '19001', 'name' => 'ذمة', 'type' => 'asset', 'balance' => 12.5,
            'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Client::factory()->create(['name' => 'سامي', 'code' => 'CLT-042', 'account_id' => $account]);
        $creator = User::factory()->create();
        foreach ([['INV-20261001-0001', 'approved', now()->subDays(30), now()->addDays(20)], ['INV-20261010-0002', 'pending', now()->subDays(5), now()->addDays(5)],
            ['INV-20260901-0003', 'approved', now()->subDays(60), now()->subDays(40)]] as [$number, $status, $date, $trip]) {
            DB::table('invoices')->insert(['invoice_number' => $number, 'client_id' => $this->client->id, 'exchange_rate_snapshot' => 0.189,
                'invoice_date' => $date->toDateString(), 'trip_date' => $trip->toDateString(), 'status' => $status, 'total_sell_jod' => 500,
                'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function facts(User $user, WaConversation $conv): array
    {
        return $this->actingAs($user)->getJson("/api/whatsapp/conversations/{$conv->id}/facts")->assertOk()->json();
    }

    public function test_details_follow_the_permissions(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view', 'quotes.view', 'clients.view', 'accounting.view']);
        $conv = $this->conversationTagged([], ['client_id' => $this->client->id]);
        $quote = QuoteRequest::create(['request_number' => 'QR-20261016-0001', 'conversation_id' => $conv->id, 'phone' => '', 'type' => 'umrah', 'pax_adults' => 2, 'status' => 'priced']);
        $msg = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => str_repeat('ب', 200)]);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'note', 'note' => 'يفضّل الطابق الأرضي', 'updated_by' => $agent->id]);
        WaThreadPin::create(['conversation_id' => $conv->id, 'kind' => 'message', 'message_id' => $msg->id]);
        $vip = WaLabel::create(['name' => 'VIP', 'color' => 'gold']);
        DB::table('wa_conversation_labels')->insert(['conversation_id' => $conv->id, 'label_id' => $vip->id, 'created_at' => now()]);

        $plain = $this->facts($agent, $conv);
        $this->assertSame(['name' => 'سامي', 'code' => 'CLT-042'], $plain['client']);
        $this->assertSame(['date' => now()->addDays(5)->toDateString()], $plain['next_trip'], 'التاريخ وحده بلا رقم الفاتورة');
        $this->assertNull($plain['last_invoice']);
        $this->assertNull($plain['balance']);
        $this->assertSame(['request_number' => 'QR-20261016-0001', 'status' => 'priced', 'status_label' => 'مسعّر'], $plain['open_quote']);
        $this->assertSame('يفضّل الطابق الأرضي', $plain['pinned']['note']['text']);
        $this->assertSame($agent->name, $plain['pinned']['note']['by']);
        $this->assertSame(140, mb_strlen($plain['pinned']['messages'][0]['excerpt']));
        $this->assertSame('VIP', $plain['labels'][0]['name']);
        $this->assertNull($plain['hint']);

        $full = $this->facts($boss, $conv);
        $this->assertSame('/clients?search=CLT-042', $full['client']['url']);
        $this->assertSame('INV-20261010-0002', $full['next_trip']['invoice_number']);
        $this->assertSame('/invoices?highlight='.$full['next_trip']['invoice_id'], $full['next_trip']['url']);
        $this->assertSame(['number' => 'INV-20261010-0002', 'status' => 'pending', 'status_label' => 'بانتظار الاعتماد'], array_diff_key($full['last_invoice'], ['url' => 0]));
        $this->assertSame(['amount' => '12.500', 'currency' => 'JOD', 'direction' => 'عليه'], $full['balance']);
        $this->assertSame($quote->id, $full['open_quote']['id']);
    }

    public function test_a_conversation_without_a_trusted_number_shows_no_financial_facts(): void
    {
        $boss = $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view', 'quotes.view', 'clients.view', 'accounting.view']);
        $phoneless = $this->conversationTagged([], ['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918',
            'client_id' => $this->client->id, 'client_link' => 'manual']);
        $unlinked = $this->conversationTagged();

        foreach ([$phoneless, $unlinked] as $conv) {
            $facts = $this->facts($boss, $conv);
            foreach (['client', 'next_trip', 'last_invoice', 'balance'] as $key) {
                $this->assertNull($facts[$key], $key);
            }
            $this->assertSame('المحادثة غير مربوطة بعميل', $facts['hint']);
        }
    }

    public function test_the_facts_route_is_fenced(): void
    {
        $umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $foreign = $this->conversationTagged(['visa'], ['client_id' => $this->client->id]);

        $this->actingAs($umrah)->getJson("/api/whatsapp/conversations/{$foreign->id}/facts")->assertNotFound();
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\Offer;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * اللوحة الجانبية للمحادثة (C128): الرحلة القادمة بعرضها وفنادقها ومسافريها لكل من يرى المحادثة،
 * ورقم الفاتورة ومبالغها بصلاحية invoices.view، والرصيد بصلاحية accounting.view، ولا تكلفة في أي حقل.
 * الفواتير تُجمع من العميل المربوط ومن الرقم نفسه، والمحادثة بلا رقم ظاهر بلا أي بيان من النظام.
 */
class ConversationPanelTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private const PHONE = '962791234567';

    private Client $client;

    private Offer $offer;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.conversation_panel' => true]);
        $account = DB::table('accounts')->insertGetId(['code' => '19001', 'name' => 'ذمة', 'type' => 'asset', 'balance' => -40,
            'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);
        $this->client = Client::factory()->create(['name' => 'سامي', 'code' => 'CLT-042', 'phone' => '0791234567', 'account_id' => $account]);
        $this->creator = User::factory()->create();

        $this->offer = Offer::factory()->ready()->create(['title' => 'عمرة الربيع', 'nights' => 7]);
        $group = $this->offer->groups()->first();
        $option = $group->options()->first();
        $option->stays()->first()->update(['name' => 'فندق الصفوة', 'rating' => 5, 'rating_plus' => true, 'distance_haram' => '300 م', 'meals' => 'إفطار']);

        $this->invoice('INV-OFFER', ['offer_id' => $this->offer->id, 'offer_seats' => 3, 'trip_date' => now()->addDays(30)->toDateString(),
            'offer_lines' => json_encode(['offer_title' => 'عمرة الربيع', 'lines' => [
                ['key' => 'a', 'sel' => [$group->id => $option->id], 'room' => 'double', 'pax' => 2, 'labels' => [['group' => 'الإقامة', 'option' => 'الصفوة']],
                    'parts' => [['pp' => 250, 'cost' => 31415.926]], 'sell_pp' => 250],
                ['key' => 'b', 'sel' => [$group->id => $option->id], 'room' => 'triple', 'pax' => 1, 'labels' => [], 'parts' => [], 'sell_pp' => 220],
            ], 'costs' => [['agent_id' => 9, 'total_jod' => 31415.926]], 'cost' => 31415.926], JSON_UNESCAPED_UNICODE)]);
        $custom = $this->invoice('INV-CUSTOM', ['trip_date' => now()->subDays(40)->toDateString(), 'invoice_date' => now()->subDays(60)->toDateString()]);
        DB::table('invoice_items')->insert(['invoice_id' => $custom, 'item_type' => 'service', 'description' => 'تذكرة طيران', 'unit_price_sar' => 0,
            'sell_price_jod' => 100, 'total_cost_sar' => 31415.926, 'total_sell_jod' => 100, 'quantity' => 1, 'sort_order' => 1]);
        // فاتورة على الرقم نفسه باسم آخر، وفاتورة مرفوضة لا تظهر، وفاتورة لرقم مختلف لا تظهر
        $brother = Client::factory()->create(['name' => 'أخو سامي', 'phone' => null]);
        $other = Client::factory()->create(['phone' => null]);
        $this->invoice('INV-PHONE', ['client_id' => $brother->id, 'client_phone' => '+962 79 123 4567', 'status' => 'pending',
            'trip_date' => now()->addDays(3)->toDateString()]);
        $this->invoice('INV-REJECTED', ['status' => 'rejected']);
        $this->invoice('INV-OTHER', ['client_id' => $other->id, 'client_phone' => '0781234567']);
    }

    private function invoice(string $number, array $attributes = []): int
    {
        return DB::table('invoices')->insertGetId($attributes + ['invoice_number' => $number, 'client_id' => $this->client->id,
            'exchange_rate_snapshot' => 0.189, 'invoice_date' => now()->subDays(5)->toDateString(), 'status' => 'approved',
            'total_sell_jod' => 500, 'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function panel(User $user, WaConversation $conv): array
    {
        return $this->actingAs($user)->getJson("/api/whatsapp/conversations/{$conv->id}/panel")->assertOk()->json();
    }

    private function boss(): User
    {
        return $this->staffWith('boss', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'invoices.view', 'quotes.view', 'clients.view',
            'accounting.view', 'offers.view']);
    }

    public function test_trips_show_hotels_and_travellers_without_invoice_figures_for_plain_staff(): void
    {
        $agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $conv = $this->conversationTagged([], ['phone' => self::PHONE, 'client_id' => $this->client->id]);
        WaCustomerNote::create(['conversation_id' => $conv->id, 'note' => 'يفضّل الطابق الأرضي', 'source' => 'bot', 'created_at' => now()]);

        $p = $this->panel($agent, $conv);

        $this->assertSame([['name' => 'سامي', 'code' => 'CLT-042', 'source' => 'linked']], $p['clients']);
        $this->assertCount(2, $p['trips'], 'الرحلتان القادمتان: فاتورة الرقم ثم فاتورة العرض');
        [$soon, $offerTrip] = $p['trips'];
        $this->assertSame(3, $soon['days_until']);
        $this->assertSame('custom', $soon['kind']);
        $this->assertSame('offer', $offerTrip['kind']);
        $this->assertSame('عمرة الربيع', $offerTrip['offer']['title']);
        $this->assertSame('مكة المكرمة', $offerTrip['offer']['destination']);
        $this->assertArrayNotHasKey('url', $offerTrip['offer'], 'رابط العرض لمن يملك offers.view');
        $this->assertSame(3, $offerTrip['pax']);
        $this->assertSame([2, 1], array_column($offerTrip['travellers'], 'pax'));
        $this->assertCount(1, $offerTrip['hotels'], 'الفندق نفسه لا يتكرر بين سطري الفاتورة');
        $this->assertSame(['فندق الصفوة', '5+', '300 م', 'إفطار'], [$offerTrip['hotels'][0]['name'], $offerTrip['hotels'][0]['rating'],
            $offerTrip['hotels'][0]['distance'], $offerTrip['hotels'][0]['meals']]);
        $this->assertSame(7, $offerTrip['nights']);
        $this->assertArrayNotHasKey('invoice_number', $offerTrip);
        $this->assertSame(['تذكرة طيران'], $p['past_trips'][0]['services']);

        $this->assertNull($p['invoices']);
        $this->assertNull($p['summary']);
        $this->assertNull($p['balance']);
        $this->assertSame('يفضّل الطابق الأرضي', $p['notes'][0]['text']);
        $this->assertStringNotContainsString('31415', json_encode($p), 'لا تكلفة في أي حقل');
    }

    public function test_invoices_and_balance_follow_their_permissions(): void
    {
        $conv = $this->conversationTagged([], ['phone' => self::PHONE]);

        $p = $this->panel($this->boss(), $conv);

        $this->assertSame([['name' => 'سامي', 'code' => 'CLT-042', 'source' => 'phone', 'url' => '/clients?search=CLT-042']], $p['clients']);
        $this->assertEqualsCanonicalizing(['INV-OFFER', 'INV-CUSTOM', 'INV-PHONE'], array_column($p['invoices'], 'number'),
            'فواتير العميل والرقم نفسه، بلا المرفوضة ولا رقم آخر');
        $this->assertSame(['count' => 3, 'approved_count' => 2, 'approved_total' => '1000.000', 'pending_count' => 1], $p['summary']);
        $this->assertSame(['amount' => '40.000', 'currency' => 'JOD', 'direction' => 'له', 'client' => 'سامي'], $p['balance']);
        $this->assertSame('INV-PHONE', $p['trips'][0]['invoice_number']);
        $this->assertSame('/offers/'.$this->offer->id, $p['trips'][1]['offer']['url']);
        $this->assertStringNotContainsString('31415', json_encode($p));
    }

    public function test_a_conversation_without_a_visible_number_gets_no_erp_data(): void
    {
        $phoneless = $this->conversationTagged([], ['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918',
            'client_id' => $this->client->id, 'client_link' => 'manual']);

        $p = $this->panel($this->boss(), $phoneless);

        $this->assertFalse($p['identity']['trusted']);
        $this->assertNotNull($p['identity']['hint']);
        $this->assertSame([], $p['clients']);
        $this->assertSame([], $p['trips']);
        $this->assertNull($p['invoices']);
        $this->assertNull($p['balance']);
    }

    public function test_the_panel_is_fenced_and_flagged(): void
    {
        $umrah = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $foreign = $this->conversationTagged(['visa'], ['phone' => '962790000001']);
        $this->actingAs($umrah)->getJson("/api/whatsapp/conversations/{$foreign->id}/panel")->assertNotFound();

        config(['features.env.conversation_panel' => false]);
        $own = $this->conversationTagged(['umrah'], ['phone' => self::PHONE]);
        $this->actingAs($umrah)->getJson("/api/whatsapp/conversations/{$own->id}/panel")->assertNotFound();
    }
}

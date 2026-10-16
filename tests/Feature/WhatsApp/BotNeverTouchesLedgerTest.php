<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaKnowledgeEntry;
use App\Models\WaMessage;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * البوت والمساعد الإداري لا يكتبان في الدفاتر (INV-11).
 *
 * تُشغَّل كل أداة في BotTools::labels() وكل أداة في AdminTools::names() (والتعديلات حتى التأكيد)
 * على عميل مربوط له حساب وفاتورة معتمدة وقيود قائمة، ثم يُقارن ما في الدفاتر قبل وبعد.
 * وكل أداة يُتحقق أنها عملت فعلاً، فلا ينجح الاختبار لأن الأدوات فشلت مبكراً.
 */
class BotNeverTouchesLedgerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private Client $client;

    private Offer $offer;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        // الحظر (E31) يرد بقائمة added_users للهدف نفسه، وما سواه رسالة مرسلة
        $n = 0;
        $this->fakeGraph(['graph.facebook.com/*' => function (Request $r) use (&$n) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/block_users')) {
                $user = (string) data_get($r->data(), 'block_users.0.user');

                return Http::response(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => [['input' => $user, 'wa_id' => ltrim($user, '+')]]]]);
            }

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT.'.(++$n)]]]);
        }]);
        // ميتا تجلب صورة البطاقة من رابط HTTPS عام
        config(['filesystems.disks.public.url' => 'https://nusk.test/storage']);

        $user = User::factory()->create();
        $accountId = DB::table('accounts')->insertGetId([
            'code' => '1201', 'name' => 'ذمّة عميل الاختبار', 'type' => 'asset', 'balance' => 125.5,
            'currency' => 'JOD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->client = Client::factory()->create(['name' => 'عميل الدفاتر', 'account_id' => $accountId]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-TEST-1', 'client_id' => $this->client->id, 'exchange_rate_snapshot' => 0.189,
            'invoice_date' => now()->subDays(3)->toDateString(), 'trip_date' => now()->addDays(10)->toDateString(),
            'status' => 'approved', 'total_sell_jod' => 300, 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // قيود قائمة: أي حذف أو تعديل أو إضافة يظهر في المقارنة
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-TEST-1', 'entry_date' => now(), 'description' => 'فاتورة INV-TEST-1',
            'reference_type' => 'invoice', 'reference_id' => $invoiceId, 'total_debit' => 300, 'total_credit' => 300,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $entryId, 'account_id' => $accountId, 'debit' => 300, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $entryId, 'account_id' => DB::table('accounts')->where('code', '5500')->value('id'), 'debit' => 0, 'credit' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ledger_entries')->insert([
            'entry_date' => now(), 'entity_type' => 'client', 'entity_id' => $this->client->id,
            'transaction_type' => 'invoice', 'transaction_id' => $invoiceId, 'debit' => 300, 'credit' => 0,
            'balance_after' => 300, 'currency' => 'JOD', 'created_at' => now(),
        ]);

        // بطاقة مرفوعة يدوياً: send_offer_card يرسلها دون توليد بالمتصفح
        $this->offer = Offer::factory()->create(['custom_card_path' => 'offer-cards/test-card.png']);

        $this->conv = WaConversation::factory()->windowOpen()->linkedTo($this->client)->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'مرحبا']);
    }

    /** لقطة الدفاتر: الصفوف كاملة لا أعدادها فقط، ومعها أرصدة الحسابات وحالات الفواتير */
    private function ledgerSnapshot(): array
    {
        return [
            'journal_entries' => DB::table('journal_entries')->orderBy('id')->get()->toArray(),
            'journal_entry_lines' => DB::table('journal_entry_lines')->orderBy('id')->get()->toArray(),
            'ledger_entries' => DB::table('ledger_entries')->orderBy('id')->get()->toArray(),
            'accounts.balance' => DB::table('accounts')->orderBy('id')->pluck('balance', 'id')->all(),
            'invoices.status' => DB::table('invoices')->orderBy('id')->pluck('status', 'id')->all(),
            'receipts' => DB::table('receipts')->count(),
        ];
    }

    private function assertLedgerCounts(array $before): void
    {
        foreach (['journal_entries', 'journal_entry_lines', 'ledger_entries'] as $table) {
            $this->assertSame(count($before[$table]), DB::table($table)->count(), "تغيّر عدد صفوف {$table}");
        }
        $this->assertEquals($before, $this->ledgerSnapshot());
    }

    #[Group('INV-11')]
    public function test_customer_bot_tools_never_write_to_the_ledger(): void
    {
        $optionRef = $this->offer->sellOptions()->first()->ref;
        // E33: الدبوس والبطاقة من الجدولين المعتمدين، خلف العلم wa_share_cards
        config(['features.env.wa_share_cards' => true]);
        \App\Models\WaPlace::create(['name' => 'المكتب', 'latitude' => 31.9539, 'longitude' => 35.9106, 'kind' => 'office', 'is_default' => true, 'bot_allowed' => true]);
        $card = \App\Models\WaContactCard::create(['label' => 'المكتب', 'formatted_name' => 'مكتب نسك', 'phone_e164' => '+16505553333',
            'consent_note' => 'موافقة مكتوبة', 'consented_at' => now(), 'bot_allowed' => true]);
        $args = [
            'get_offers' => [],
            'get_offer_details' => ['offer_id' => $this->offer->id],
            'send_offer_card' => ['offer_id' => $this->offer->id],
            'set_topic' => ['topics' => ['umrah']],
            'get_my_balance' => [],
            'get_my_invoices' => [],
            'get_my_trips' => [],
            'request_quote' => ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة',
                'depart_date' => now()->addDays(20)->toDateString(), 'adults' => 2, 'details' => 'تذكرتان ذهاب وإياب'],
            'confirm_booking' => ['offer_id' => $this->offer->id, 'option_ref' => $optionRef, 'room_type' => 'ثنائية',
                'adults' => 2, 'details' => 'أريد الحجز'],
            'save_note' => ['note' => 'يفضّل غرفة ثنائية'],
            'send_office_location' => [],
            'send_contact_card' => ['card_key' => 'card_'.$card->id],
            // E36: تجهيز بلا إرسال (لا تشغيل رد هنا)، ولا تمس الدفاتر
            'show_offers_menu' => [],
            'present_choices' => ['kind' => 'booking_actions', 'offer_id' => $this->offer->id],
            'send_quote_form' => [],
            'handoff_to_human' => ['reason' => 'طلب موظفاً'],
        ];
        $this->assertEqualsCanonicalizing(BotTools::names(), array_keys($args), 'أداة جديدة في BotTools بلا حالة هنا');

        $before = $this->ledgerSnapshot();

        $out = [];
        foreach ($args as $tool => $in) {
            $out[$tool] = BotTools::execute($tool, $in, $this->conv->fresh());
            $this->assertArrayNotHasKey('error', $out[$tool], "{$tool} فشلت فلم تُختبر فعلاً");
        }

        // الأدوات عملت فعلاً على العميل المربوط
        $this->assertSame(125.5, $out['get_my_balance']['balance_jod']);
        $this->assertSame('INV-TEST-1', $out['get_my_invoices']['invoices'][0]['invoice_number']);
        $this->assertSame(1, $out['get_my_trips']['count']);
        $this->assertTrue($out['send_offer_card']['sent']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && $r['type'] === 'image');
        $this->assertSame(2, QuoteRequest::where('conversation_id', $this->conv->id)->count());
        $this->assertFalse($this->conv->fresh()->bot_enabled);

        $this->assertLedgerCounts($before);
    }

    #[Group('INV-11')]
    public function test_admin_tools_never_write_to_the_ledger(): void
    {
        $adminConv = WaConversation::factory()->windowOpen()->create();
        $admin = WaAdminNumber::factory()->create(['phone' => $adminConv->phone]);
        $this->say($adminConv, 'ابدأ');

        $before = $this->ledgerSnapshot();
        $used = [];

        $read = function (string $tool, array $in = []) use ($adminConv, $admin, &$used) {
            return $this->runAdmin($tool, $in, $adminConv, $admin, $used);
        };
        $write = function (string $tool, array $in) use ($adminConv, $admin, &$used) {
            $preview = $this->runAdmin($tool, $in, $adminConv, $admin, $used);
            $this->assertTrue($preview['needs_confirmation'] ?? false, "{$tool}:{$in['action']} بلا معاينة");
            $this->say($adminConv, 'تأكيد');
            $done = $this->runAdmin($tool, $in + ['confirm' => true], $adminConv, $admin, $used);
            $this->assertTrue($done['done'] ?? false, "{$tool}:{$in['action']} لم يُنفَّذ بعد التأكيد");
        };

        $read('get_daily_summary');
        $read('get_sales_report', ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()]);
        $read('get_travelers', ['date' => now()->addDays(10)->toDateString()]);
        $read('get_pending_approvals');
        $read('get_open_quotes');
        $read('get_attention_queue');
        // E23: حالة القناة قراءة لا تمس الدفاتر
        $read('get_channel_status');
        $found = $read('find_client', ['query' => 'عميل الدفاتر']);
        $this->assertSame(125.5, $found['clients'][0]['balance_jod']);

        $read('manage_knowledge', ['action' => 'list']);
        $write('manage_knowledge', ['action' => 'add', 'title' => 'مواعيد الدوام', 'content' => 'من التاسعة صباحاً إلى الخامسة مساءً.']);
        $entry = WaKnowledgeEntry::sole();
        $read('manage_knowledge', ['action' => 'view', 'id' => $entry->id]);
        $read('manage_knowledge', ['action' => 'search', 'query' => 'الدوام']);
        $write('manage_knowledge', ['action' => 'update', 'id' => $entry->id, 'content' => 'من الثامنة صباحاً إلى الرابعة مساءً.']);
        $write('manage_knowledge', ['action' => 'delete', 'id' => $entry->id]);
        $this->assertSame(0, WaKnowledgeEntry::count());

        $read('manage_bot_tools', ['action' => 'list']);
        $write('manage_bot_tools', ['action' => 'disable', 'tool' => 'save_note']);
        $this->assertFalse(WaBotSetting::current()->toolEnabled('save_note'));
        $write('manage_bot_tools', ['action' => 'enable', 'tool' => 'save_note']);

        $read('set_customer_bot', ['action' => 'status']);
        $write('set_customer_bot', ['action' => 'off']);
        $this->assertFalse(WaBotSetting::current()->enabled);
        $write('set_customer_bot', ['action' => 'on']);

        // E31: حظر رقم مزعج (خلف علمه، وبمستخدم مرتبط يملك whatsapp.block) لا يمس الدفاتر
        config(['features.env.wa_block_admin_tool' => true]);
        $admin->forceFill(['user_id' => User::factory()->admin()->create()->id])->save();
        $write('block_contact', ['action' => 'block', 'phone' => '+'.$this->conv->phone, 'reason_code' => 'spam', 'reason' => 'رسائل مزعجة متكررة طوال اليوم']);
        $this->assertNotNull($this->conv->fresh()->blocked_at);

        $this->assertEqualsCanonicalizing(AdminTools::names(), array_values(array_unique($used)), 'أداة إدارية بلا حالة هنا');

        $this->assertLedgerCounts($before);
    }

    private function runAdmin(string $tool, array $in, WaConversation $conv, WaAdminNumber $admin, array &$used): array
    {
        $used[] = $tool;
        $out = AdminTools::execute($tool, $in, $conv->fresh(), $admin->fresh());
        $this->assertArrayNotHasKey('error', $out, "{$tool} فشلت فلم تُختبر فعلاً: ".json_encode($out, JSON_UNESCAPED_UNICODE));

        return $out;
    }

    private function say(WaConversation $conv, string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body]);
    }
}

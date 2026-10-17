<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\QuoteRequestGuard;
use App\Services\WhatsApp\Tools\ToolInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * منع تكرار طلبات التسعير (E17.8): استدعاء الأداة مرتين بالمدخلات نفسها، أو إعادة العميل خلال 30 دقيقة،
 * لا ينشئ طلباً ثانياً ولا إشعاراً ثانياً. والطلبات المختلفة فعلاً لا تُدمج.
 */
class QuoteRequestDedupeTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const FLIGHT = ['type' => 'flight', 'route_from' => 'عمّان', 'route_to' => 'جدة', 'depart_date' => '2026-12-20', 'adults' => 2];

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.quote_dedupe' => true]);
        $this->configureChannel();
        User::factory()->admin()->create();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function inbound(string $body = 'بدي تسعيرة'): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
    }

    private function quote(array $in): array
    {
        return ToolInvoker::run('request_quote', $in, $this->conv->fresh(), ['request_quote'])['output'];
    }

    public function test_the_model_calling_the_tool_twice_creates_one_request_and_one_notification(): void
    {
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'request_quote', 'args' => self::FLIGHT], ['call' => 'request_quote', 'args' => self::FLIGHT], 'وصل طلبك.');
        $this->inbound();

        BotEngine::reply($this->conv->fresh());

        $this->assertSame(1, QuoteRequest::count());
        $this->assertSame(1, Notification::where('title', '💰 طلب تسعير جديد')->count());
        $this->assertContains('dup:request_quote', WaBotUsage::latest('id')->first()->tools_used);
    }

    public function test_two_different_routes_in_one_message_are_two_requests(): void
    {
        $this->inbound('عمّان جدة وعمّان إسطنبول');
        $a = $this->quote(self::FLIGHT);
        $b = $this->quote(['route_to' => 'إسطنبول'] + self::FLIGHT);

        $this->assertNotSame($a['request_number'], $b['request_number']);
        $this->assertSame(2, QuoteRequest::count());
    }

    public function test_a_repeat_within_30_minutes_returns_the_same_number_and_after_that_a_new_one(): void
    {
        $this->inbound();
        $first = $this->quote(self::FLIGHT);

        $this->travel(10)->minutes();
        $this->inbound('أعيد الطلب');
        $again = $this->quote(self::FLIGHT);
        $this->assertSame($first['request_number'], $again['request_number']);
        $this->assertTrue($again['duplicate']);

        $this->travel(22)->minutes();
        $this->inbound('مرة ثانية');
        $later = $this->quote(self::FLIGHT);
        $this->assertNotSame($first['request_number'], $later['request_number']);
        $this->assertSame(2, QuoteRequest::count());
    }

    public function test_a_priced_request_or_a_different_date_is_not_a_duplicate(): void
    {
        $this->inbound();
        $first = $this->quote(self::FLIGHT);
        QuoteRequest::where('request_number', $first['request_number'])->update(['status' => 'priced']);

        $this->inbound('نفس الطلب');
        $this->assertNotSame($first['request_number'], $this->quote(self::FLIGHT)['request_number']);

        $this->inbound('تاريخ آخر');
        $this->quote(['depart_date' => '2026-12-27'] + self::FLIGHT);
        $this->assertSame(3, QuoteRequest::count());
    }

    public function test_old_requests_without_a_key_do_not_conflict(): void
    {
        DB::table('quote_requests')->insert(['request_number' => 'QR-OLD-1', 'conversation_id' => $this->conv->id, 'phone' => '', 'type' => 'flight', 'status' => 'priced', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quote_requests')->insert(['request_number' => 'QR-OLD-2', 'conversation_id' => $this->conv->id, 'phone' => '', 'type' => 'flight', 'status' => 'priced', 'created_at' => now(), 'updated_at' => now()]);

        $this->inbound();
        $this->quote(self::FLIGHT);
        $this->assertSame(3, QuoteRequest::count());
    }

    public function test_a_race_on_the_key_returns_the_existing_request(): void
    {
        $this->inbound();
        $first = QuoteRequestGuard::createOrReuse($this->conv, 'quote', ['conversation_id' => $this->conv->id, 'phone' => '', 'type' => 'flight', 'route_to' => 'جدة', 'status' => 'new']);
        // الطلب الأول خرج من نافذة الحالة new، والمفتاح وحده يكشف أنه الرسالة نفسها
        $first['qr']->update(['status' => 'priced']);

        $second = QuoteRequestGuard::createOrReuse($this->conv, 'quote', ['conversation_id' => $this->conv->id, 'phone' => '', 'type' => 'flight', 'route_to' => 'جدة', 'status' => 'new']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['qr']->id, $second['qr']->id);
    }

    public function test_a_number_collision_is_retried(): void
    {
        $this->inbound();
        $taken = QuoteRequest::nextNumber();
        // رقم اليوم التالي محجوز بطلب قديم بلا مفتاح: max+1 يصطدم مرة ثم يتقدم
        DB::table('quote_requests')->insert(['request_number' => $taken, 'conversation_id' => null, 'phone' => '', 'type' => 'flight', 'status' => 'priced', 'created_at' => now(), 'updated_at' => now()]);
        QuoteRequest::creating(function (QuoteRequest $qr) use ($taken) {
            static $once = false;
            if (!$once) {
                $once = true;
                $qr->request_number = $taken;
            }
        });

        $out = $this->quote(self::FLIGHT);
        $this->assertNotSame($taken, $out['request_number']);
    }

    public function test_with_the_flag_off_every_call_creates_a_request(): void
    {
        config(['features.env.quote_dedupe' => false]);
        $this->inbound();
        $this->quote(self::FLIGHT);
        $this->quote(self::FLIGHT);

        $this->assertSame(2, QuoteRequest::count());
        $this->assertNull(QuoteRequest::first()->dedupe_key);
    }
}

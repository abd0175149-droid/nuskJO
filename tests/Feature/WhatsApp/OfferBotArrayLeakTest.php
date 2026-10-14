<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use Database\Factories\OfferFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * لا حقل داخلي من العرض يصل البوت أو النموذج (INV-08).
 *
 * العروض تُنشأ بـ withCanaryInternals(): قيم داخلية لا تتكرر في أي مكان آخر، فظهور أيّ منها
 * في الحمولة أو في جسم طلب Gemini يعني تسريباً. وكل حالة تتحقق أولاً أن الحمولة فيها العرض فعلاً
 * (العنوان والسعر)، فلا ينجح الاختبار لأن الحمولة فارغة.
 */
class OfferBotArrayLeakTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const FORBIDDEN_KEY = '/cost|agent|room_rates|seats|internal|margin|profit/i';

    private const TITLE = 'عمرة اختبار التسريب';

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offer = Offer::factory()->ready()->withCanaryInternals()->create(['title' => self::TITLE]);
    }

    /** @return list<string> */
    private function canaries(): array
    {
        return [
            (string) OfferFactory::CANARY_ROOM_RATE,
            (string) OfferFactory::CANARY_UNIT_COST,
            (string) OfferFactory::CANARY_AGENT_ID,
            (string) OfferFactory::CANARY_TOTAL_SEATS,
            'CANARY',
        ];
    }

    private function assertNoForbiddenKeys(array $data, string $path = '$'): void
    {
        foreach ($data as $key => $value) {
            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_KEY, (string) $key, "مفتاح داخلي في {$path}.{$key}");
            if (is_array($value)) {
                $this->assertNoForbiddenKeys($value, "{$path}.{$key}");
            }
        }
    }

    private function assertNoCanaries(string $json, string $where): void
    {
        foreach ($this->canaries() as $canary) {
            $this->assertStringNotContainsString($canary, $json, "القيمة الداخلية {$canary} وصلت {$where}");
        }
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function conversation(): WaConversation
    {
        // رقم ثابت: الرقم العشوائي قد يحوي 4242 فيُفشل فحص القيم بلا تسريب
        return WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    #[Group('INV-08')]
    public function test_canary_internals_are_really_stored_on_the_offer(): void
    {
        $stay = $this->offer->groups->first()->options->first()->stays->first();

        $this->assertSame(['double' => OfferFactory::CANARY_ROOM_RATE], $stay->room_rates);
        $this->assertSame(OfferFactory::CANARY_AGENT_ID, (int) $stay->agent_id);
        $this->assertSame(OfferFactory::CANARY_TOTAL_SEATS, $this->offer->total_seats);
        $this->assertSame(OfferFactory::CANARY_UNIT_COST, $this->offer->costs()->first()->unit_cost);
        $this->assertSame(OfferFactory::CANARY_COST_LABEL, $this->offer->costs()->first()->label);
    }

    #[Group('INV-08')]
    public function test_to_bot_array_has_no_internal_keys_or_values(): void
    {
        $payload = $this->offer->fresh()->toBotArray();
        $json = $this->encode($payload);

        $this->assertSame(self::TITLE, $payload['title']);
        $this->assertSame([['ثنائية' => 250.0, 'ثلاثية' => 220.0]], array_column($payload['options'], 'prices_per_person_jod'));
        $this->assertSame('متوفر', $payload['availability']);

        $this->assertNoForbiddenKeys($payload);
        $this->assertNoCanaries($json, 'toBotArray');
    }

    #[Group('INV-08')]
    public function test_get_offers_and_get_offer_details_results_have_no_internals(): void
    {
        $conv = $this->conversation();

        $list = BotTools::execute('get_offers', [], $conv);
        $details = BotTools::execute('get_offer_details', ['offer_id' => $this->offer->id], $conv);

        $this->assertSame(1, $list['count']);
        $this->assertSame(self::TITLE, $list['offers'][0]['title']);
        $this->assertSame(self::TITLE, $details['offer']['title']);

        foreach (['get_offers' => $list, 'get_offer_details' => $details] as $tool => $result) {
            $this->assertNoForbiddenKeys($result, $tool);
            $this->assertNoCanaries($this->encode($result), $tool);
        }
    }

    #[Group('INV-08')]
    public function test_tool_result_sent_back_to_gemini_has_no_internals(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'get_offers', 'args' => []], 'عندنا عرض عمرة يبدأ من 220 ديناراً للفرد.');

        $conv = $this->conversation();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'شو عروض العمرة عندكم؟']);

        $result = BotEngine::reply($conv);

        $this->assertTrue($result['sent']);

        $geminiCalls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com'))->values();
        $this->assertCount(2, $geminiCalls);

        // الطلب الثاني يحمل نتيجة get_offers إلى النموذج
        [$second] = $geminiCalls[1];
        $body = $this->encode($second->data());
        $this->assertStringContainsString('functionResponse', $body);
        $this->assertStringContainsString(self::TITLE, $body);

        $this->assertNoCanaries($body, 'جسم طلب Gemini');
        $this->assertNoCanaries($second->body(), 'جسم طلب Gemini الخام');
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\Llm\GeminiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * عقد مزوّدي النموذج كما يرسله BotEngine فعلاً: الرابط والترويسات وشكل الجسم.
 * INV-16: أجزاء ردّ Gemini تُعاد إليه كما وصلت، ووسائط الأداة الفارغة تُرسل {} لا [].
 */
class LlmProviderContractTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function conversationWithMessage(string $body = 'مرحبا'): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => $body]);

        return $conv;
    }

    private function isGemini(Request $r): bool
    {
        return str_contains($r->url(), 'generativelanguage.googleapis.com');
    }

    /** @return list<Request> */
    private function geminiRequests(): array
    {
        return Http::recorded(fn (Request $r) => $this->isGemini($r))->map(fn ($pair) => $pair[0])->values()->all();
    }

    // ==================== Gemini ====================

    public function test_gemini_request_uses_generate_content_with_system_instruction_and_function_declarations(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini('أهلاً بك');

        BotEngine::reply($this->conversationWithMessage());

        Http::assertSent(function (Request $r) {
            if (!$this->isGemini($r)) {
                return false;
            }

            $declarations = collect($r['tools'][0]['functionDeclarations'])->keyBy('name');

            return $r->method() === 'POST'
                && str_starts_with($r->url(), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent')
                && count($r['systemInstruction']['parts']) === 2
                && str_contains($r['systemInstruction']['parts'][0]['text'], 'مساعد نُسك')
                && Arr::last($r['contents'])['role'] === 'user'
                && $declarations->has('get_offers')
                && $declarations['get_offer_details']['parameters']['required'] === ['offer_id']
                // Gemini يرفض parameters بخصائص فارغة، فتُحذف للأداة بلا وسائط
                && !array_key_exists('parameters', $declarations['get_my_balance']);
        });
    }

    #[Group('INV-16')]
    public function test_empty_tool_args_are_sent_back_to_gemini_as_an_object(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $this->fakeGemini(['call' => 'get_my_balance', 'args' => []], 'رقمك غير مربوط بحساب.');

        $this->assertTrue(BotEngine::reply($this->conversationWithMessage('كم رصيدي؟'))['sent']);

        $requests = $this->geminiRequests();
        $this->assertCount(2, $requests);

        $raw = $requests[1]->body();
        $this->assertStringContainsString('"functionCall":{"name":"get_my_balance","args":{}}', $raw);
        $this->assertStringNotContainsString('"args":[]', $raw);
        $this->assertStringContainsString('"functionResponse":{"name":"get_my_balance"', $raw);
    }

    #[Group('INV-16')]
    public function test_model_parts_are_returned_to_gemini_verbatim_with_thought_signature(): void
    {
        $this->configureChannel();
        $this->fakeGraph();

        $call = $this->geminiResponse(['call' => 'get_offers', 'args' => ['category' => 'umrah']]);
        $call['candidates'][0]['content']['parts'][0]['thoughtSignature'] = 'sig-opaque-123';
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($call)
            ->push($this->geminiResponse('لا توجد عروض عمرة الآن.'))]);

        BotEngine::reply($this->conversationWithMessage('عروض العمرة؟'));

        $contents = $this->geminiRequests()[1]->data()['contents'];
        $modelTurn = $contents[count($contents) - 2];

        $this->assertSame('model', $modelTurn['role']);
        $this->assertSame([[
            'functionCall' => ['name' => 'get_offers', 'args' => ['category' => 'umrah']],
            'thoughtSignature' => 'sig-opaque-123',
        ]], $modelTurn['parts']);
    }

    #[Group('INV-16')]
    public function test_append_assistant_turns_only_empty_args_into_an_object(): void
    {
        $history = [];
        (new GeminiProvider)->appendAssistant($history, [
            ['functionCall' => ['name' => 'get_my_trips', 'args' => []], 'thoughtSignature' => 's1'],
            ['functionCall' => ['name' => 'get_offers', 'args' => ['category' => 'visa']]],
            ['text' => 'لحظة من فضلك'],
        ]);

        $this->assertSame(
            '{"role":"model","parts":[{"functionCall":{"name":"get_my_trips","args":{}},"thoughtSignature":"s1"},'
            .'{"functionCall":{"name":"get_offers","args":{"category":"visa"}}},{"text":"لحظة من فضلك"}]}',
            json_encode($history[0], JSON_UNESCAPED_UNICODE),
        );
    }

    // ==================== Anthropic ====================

    public function test_anthropic_request_sends_api_key_version_and_caches_only_the_first_system_block(): void
    {
        $this->configureChannel(['provider' => 'anthropic', 'model' => 'claude-test']);
        $this->fakeGraph();
        $this->fakeAnthropic('أهلاً بك');

        $this->assertTrue(BotEngine::reply($this->conversationWithMessage())['sent']);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }

            return $r->method() === 'POST'
                && $r->hasHeader('x-api-key', 'test-llm-key')
                && $r->hasHeader('anthropic-version', '2023-06-01')
                && $r['model'] === 'claude-test'
                && count($r['system']) === 2
                && $r['system'][0]['cache_control'] === ['type' => 'ephemeral']
                && !array_key_exists('cache_control', $r['system'][1])
                && in_array('get_offers', array_column($r['tools'], 'name'), true)
                && Arr::last($r['messages'])['role'] === 'user';
        });
        Http::assertNotSent(fn (Request $r) => $this->isGemini($r));
    }
}

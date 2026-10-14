<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\WhatsApp\Llm\AnthropicProvider;
use App\Services\WhatsApp\Llm\GeminiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * نداءات النماذج: مفتاح Gemini في الترويسة لا في الرابط، ولا تتبع أي تحويل،
 * واسم النموذج مقطع مسار واحد، وأخطاء فحص المفتاح لا تعيد الرابط ولا المفتاح.
 */
class OutboundLlmCallsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyTEST-not-a-real-key';

    private function geminiOk(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]], 'usageMetadata' => []];
    }

    public function test_gemini_sends_the_key_in_a_header_only(): void
    {
        Http::fake(fn (Request $r) => Http::response(str_ends_with($r->url(), '/models') ? ['models' => []] : $this->geminiOk()));
        $gemini = new GeminiProvider;

        $gemini->generate(self::KEY, 'gemini-2.5-flash', ['s'], [['role' => 'user', 'parts' => [['text' => 'hi']]]], []);
        $gemini->transcribe(self::KEY, 'gemini-2.5-flash', 'bytes', 'audio/ogg');
        $gemini->models(self::KEY);

        Http::assertSentCount(3);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::KEY) || str_contains($r->url(), 'key='));
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', self::KEY));
    }

    public function test_model_name_cannot_change_the_path(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        (new GeminiProvider)->generate(self::KEY, '../../other', ['s'], [], []);
    }

    public function test_redirects_are_not_followed_with_the_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('', 302, ['Location' => 'https://collector.example/steal']),
            'api.anthropic.com/*' => Http::response('', 307, ['Location' => 'https://collector.example/steal']),
            'collector.example/*' => Http::response(['models' => [], 'data' => []]),
        ]);

        foreach ([new GeminiProvider, new AnthropicProvider] as $provider) {
            try {
                $provider->models(self::KEY);
                $this->fail('a redirect must not count as success');
            } catch (RuntimeException) {
            }
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'collector.example'));
    }

    public function test_plain_http_calls_do_not_follow_redirects_either(): void
    {
        Http::fake([
            'partner.example/*' => Http::response('', 301, ['Location' => 'https://collector.example/x']),
            'collector.example/*' => Http::response('ok'),
        ]);

        $this->assertSame(301, Http::get('https://partner.example/api')->status());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'collector.example'));
    }

    public function test_key_check_hides_connection_details(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://generativelanguage.googleapis.com/v1beta/models?key='.self::KEY));
        $response = $this->actingAs($owner)->postJson('/api/whatsapp/test-key', ['provider' => 'google', 'api_key' => self::KEY])
            ->assertStatus(422)
            ->assertJsonPath('error', 'تعذّر الاتصال بالمزوّد (مهلة أو شبكة) — حاول لاحقاً.');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_key_check_needs_the_settings_permission(): void
    {
        Http::fake();
        $agent = User::factory()->withPermissions(['whatsapp.view'], 'agent')->create();

        $this->actingAs($agent)->postJson('/api/whatsapp/test-key', ['provider' => 'google', 'api_key' => self::KEY])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_key_check_redacts_provider_errors(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key '.self::KEY.' not valid']], 400)]);
        $response = $this->actingAs($owner)->postJson('/api/whatsapp/test-key', ['provider' => 'google', 'api_key' => self::KEY])->assertStatus(422);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringContainsString('not valid', $response->json('error'));
    }

    public function test_model_names_are_limited_to_safe_characters(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->put('/whatsapp/settings', ['model' => 'gemini/../x?y'])
            ->assertSessionHasErrors(['model' => 'اسم النموذج يقبل الحروف والأرقام و . _ - فقط']);
        $this->actingAs($owner)->put('/whatsapp/settings', ['model' => 'gemini-2.5-flash'])->assertSessionHasNoErrors();
    }
}

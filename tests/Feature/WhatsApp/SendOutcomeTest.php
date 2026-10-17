<?php

namespace Tests\Feature\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * نتيجة الإرسال المجهولة وحد الإرسال (E28): مع connection_resilience، المهلة أو الانقطاع بعد بدء الإرسال 202
 * OUTCOME_UNKNOWN «قد تكون أُرسلت» بنص واحد بلا رابط ولا نص استثناء، والرفض الصريح 422 كما هو. وحد wa-send لكل
 * موظف: الطلب بعد الحد في الدقيقة 429 LOCAL_RATE_LIMIT (والسعر من صفحته برسالة في الصفحة).
 */
class SendOutcomeTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.connection_resilience' => true]);
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all', 'quotes.view', 'quotes.price'])->create();
    }

    private function send(WaConversation $conversation, string $body = 'موعدك غداً')
    {
        return $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$conversation->id}/send", ['body' => $body],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function timeout(): void
    {
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received for https://graph.facebook.com/v25.0/106540352242922/messages')]);
    }

    private function assertMaybeSent($response): void
    {
        $response->assertStatus(202)->assertJsonPath('ok', false)->assertJsonPath('code', 'OUTCOME_UNKNOWN')->assertJsonPath('unknown', true);
        $error = $response->json('error');
        $this->assertStringStartsWith('قد تكون الرسالة أُرسلت — راجع المحادثة قبل الإعادة', $error);
        foreach (['http', 'graph.facebook.com', 'cURL', 'timed out', 'Exception'] as $raw) {
            $this->assertStringNotContainsString($raw, $error);
        }
    }

    public function test_a_timeout_on_the_old_path_is_maybe_sent_not_failed(): void
    {
        $this->timeout();
        $conversation = WaConversation::factory()->windowOpen()->create();

        $this->assertMaybeSent($this->send($conversation));
        $this->assertSame(WaMessage::STATUS_UNKNOWN, WaMessage::where('conversation_id', $conversation->id)->sole()->status);
    }

    public function test_a_timeout_with_the_send_ledger_is_maybe_sent(): void
    {
        config(['features.env.send_ledger' => true]);
        $this->timeout();
        $conversation = WaConversation::factory()->windowOpen()->create();

        $response = $this->send($conversation);

        $this->assertMaybeSent($response);
        $this->assertSame('unknown', $response->json('status'));
        $this->assertNotNull($response->json('row_id'));
    }

    public function test_a_connection_that_never_opened_is_an_explicit_failure(): void
    {
        config(['features.env.meta_error_v2' => true]);
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to graph.facebook.com port 443')]);

        $this->send(WaConversation::factory()->windowOpen()->create())->assertStatus(422)->assertJsonPath('code', 'HTTP_CONNECT_FAILED');
    }

    public function test_a_meta_rejection_stays_422(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/error_131026.json')), true), 400)]);

        $response = $this->send(WaConversation::factory()->windowOpen()->create());

        $response->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertNotSame('OUTCOME_UNKNOWN', $response->json('code'));
    }

    public function test_without_the_flag_the_old_answers_stay(): void
    {
        config(['features.env.connection_resilience' => false]);
        $this->timeout();

        $this->send(WaConversation::factory()->windowOpen()->create())->assertStatus(422)->assertJsonPath('code', 'HTTP_ERROR');
    }

    public function test_the_thirty_first_send_in_a_minute_is_slowed_down(): void
    {
        $this->fakeGraph();
        $conversation = WaConversation::factory()->windowOpen()->create();

        for ($i = 1; $i <= 30; $i++) {
            $this->send($conversation, "رسالة {$i}")->assertOk();
        }
        $this->send($conversation, 'رسالة 31')->assertStatus(429)
            ->assertExactJson(['ok' => false, 'code' => 'LOCAL_RATE_LIMIT', 'error' => 'تمهّل قليلاً — أرسلت رسائل كثيرة في دقيقة']);
        $this->assertSame(30, WaMessage::where('conversation_id', $conversation->id)->count());

        // الحد لكل موظف: زميل آخر يرسل
        $colleague = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'], 'desk-2')->create();
        $this->actingAs($colleague)->postJson("/api/whatsapp/conversations/{$conversation->id}/send", ['body' => 'من زميل'])->assertOk();

        $this->travel(61)->seconds();
        $this->send($conversation, 'بعد دقيقة')->assertOk();
    }

    public function test_the_quote_price_page_shows_the_limit_and_maybe_sent_in_the_page(): void
    {
        $conversation = WaConversation::factory()->windowOpen()->create();
        $quote = QuoteRequest::factory()->forConversation($conversation)->create(['status' => 'new']);
        $this->timeout();

        $this->actingAs($this->staff)->from('/quote-requests')->post("/quote-requests/{$quote->id}/price", ['quoted_price_jod' => 250, 'send_to_client' => true])
            ->assertRedirect('/quote-requests')
            ->assertSessionHas('error', 'حُفظ السعر، وقد يكون أُرسل — تحقق من المحادثة قبل إعادة الإرسال.');

        config(['whatsapp.send.per_minute' => 1]);
        $this->actingAs($this->staff)->from('/quote-requests')->withHeader('X-Inertia', 'true')
            ->post("/quote-requests/{$quote->id}/price", ['quoted_price_jod' => 260, 'send_to_client' => true, 'confirm_resend' => true])
            ->assertRedirect('/quote-requests')
            ->assertSessionHas('error', 'تمهّل قليلاً — أرسلت رسائل كثيرة في دقيقة');
        $this->assertSame(250.0, (float) $quote->fresh()->quoted_price_jod);
    }
}

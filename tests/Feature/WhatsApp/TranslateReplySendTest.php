<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MessageTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * ترجمة رد الموظف (E29.9): المعاينة لا تخزّن ولا ترسل، وتعيد مقارنة الأرقام ورمز تحقق. الإرسال يُرفض بلا رمز صالح لنفس
 * المستخدم والنص والهدف، أو مع اختلاف الأرقام أو أرقام بالحروف، ولا يصل أي طلب إلى Graph في الحالتين. المتطابق يُرسل
 * عبر WhatsAppChannel ويُخزَّن نص الموظف الأصلي في الحمولة.
 */
class TranslateReplySendTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const SOURCE = 'السعر ١٥٠ دينار للشخص، والسفر في ٢٠/١١';

    private const ENGLISH = 'The price is 150 JOD per person, travel on 20/11';

    private User $agent;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.staff_translation' => true]);
        $this->agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->conv = $this->conversationTagged([], ['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $s = WaBotSetting::current();
        WaBotSetting::whereKey($s->id)->update(['llm_terms_confirmed_at' => now(), 'llm_terms_fp' => MessageTranslator::termsFingerprint($s)]);
    }

    private function send(array $payload, ?string $key = 'auto', ?User $as = null)
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key === 'auto' ? (string) Str::uuid() : $key];

        return $this->actingAs($as ?? $this->agent)->postJson("/api/whatsapp/conversations/{$this->conv->id}/send-translated", $payload, $headers);
    }

    private function translation(string $body, ?string $token = null, string $source = self::SOURCE): array
    {
        return ['body' => $body, 'translation' => ['source_text' => $source, 'target' => 'en',
            'check_token' => $token ?? MessageTranslator::checkToken($this->conv->id, $this->agent->id, $source, 'en')]];
    }

    public function test_the_preview_returns_the_translation_numbers_and_a_token_and_stores_nothing(): void
    {
        $this->fakeGemini('{"text":"'.self::ENGLISH.'"}');

        $res = $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$this->conv->id}/translate-reply", ['text' => self::SOURCE, 'target' => 'en'])
            ->assertOk()->assertJsonPath('translation', self::ENGLISH)->assertJsonPath('target', 'en')
            ->assertJsonPath('numbers.ok', true)->assertJsonPath('numbers.source', ['11', '150', '20']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res->json('check_token'));
        $this->assertTrue(MessageTranslator::verifyToken($res->json('check_token'), $this->conv->id, $this->agent->id, self::SOURCE, 'en'));
        $this->assertSame(0, WaMessage::count());
        $this->assertSame('translate', WaBotUsage::sole()->source);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['systemInstruction']['parts'][0]['text'], 'الإنجليزية (رمزها en)')
            && !array_key_exists('tools', $r->data()));
    }

    public function test_the_preview_flags_changed_or_spelled_numbers(): void
    {
        $this->fakeGemini('{"text":"The price is one hundred fifty JOD per person, travel on 20/11"}');

        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$this->conv->id}/translate-reply", ['text' => self::SOURCE, 'target' => 'en'])
            ->assertOk()->assertJsonPath('numbers.ok', false)->assertJsonPath('numbers.spelled', true)
            ->assertJsonPath('numbers.missing', ['150'])->assertJsonPath('numbers.spelled_words', ['one', 'hundred', 'fifty']);
    }

    public function test_sending_needs_a_valid_token_matching_numbers_and_a_key_before_any_graph_call(): void
    {
        $this->fakeGraph();

        $this->send(['body' => self::ENGLISH])->assertStatus(422)->assertJsonValidationErrors(['translation']);
        $this->send($this->translation(self::ENGLISH, str_repeat('a', 64)))->assertStatus(422)->assertJsonPath('code', 'TRANSLATION_UNVERIFIED');
        $other = $this->staffWith('other-agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->send($this->translation(self::ENGLISH), 'auto', $other)->assertStatus(422)->assertJsonPath('code', 'TRANSLATION_UNVERIFIED');
        // رمز معاينة نص آخر: تغيّر الأصل بعد المعاينة
        $this->send($this->translation(self::ENGLISH, MessageTranslator::checkToken($this->conv->id, $this->agent->id, 'نص آخر', 'en')))
            ->assertStatus(422)->assertJsonPath('code', 'TRANSLATION_UNVERIFIED');
        $this->send($this->translation('Price 105 JOD, travel on 20/11'))->assertStatus(422)
            ->assertJsonPath('code', 'NUMBERS_MISMATCH')->assertJsonPath('missing', ['150'])->assertJsonPath('extra', ['105']);
        $this->send($this->translation('The price is one hundred fifty (150) JOD, travel on 20/11'))->assertStatus(422)
            ->assertJsonPath('code', 'NUMBERS_MISMATCH')->assertJsonPath('spelled_words', ['one', 'hundred', 'fifty']);
        $this->send($this->translation(self::ENGLISH), null)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->travel(31)->minutes();
        $this->send($this->translation(self::ENGLISH, MessageTranslator::checkToken($this->conv->id, $this->agent->id, self::SOURCE, 'en', intdiv(now()->subMinutes(31)->getTimestamp(), 60))))
            ->assertStatus(422)->assertJsonPath('code', 'TRANSLATION_UNVERIFIED');

        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_matching_translation_goes_through_the_channel_and_keeps_the_staff_text(): void
    {
        $this->fakeGraph();
        $edited = 'Dear guest, the price is 150 JOD per person and you travel on 20/11.';

        $this->send($this->translation($edited))->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r->data()['text']['body'] === $edited);
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame([$edited, 'staff', $this->agent->id], [$row->body, $row->source, $row->staff_id]);
        $this->assertSame(self::SOURCE, $row->payload['_source_text']);
        $this->assertSame(['from' => 'ar', 'to' => 'en', 'model' => 'gemini-test'], $row->payload['_translated']);

        $thread = collect($this->actingAs($this->agent)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->json('messages'))->firstWhere('id', $row->id);
        $this->assertSame(['to' => 'en', 'to_label' => 'الإنجليزية', 'source_text' => self::SOURCE], $thread['translated']);
    }

    public function test_with_the_ledger_one_key_sends_once(): void
    {
        config(['features.env.send_ledger' => true]);
        $this->fakeGraph();
        $key = (string) Str::uuid();

        $this->send($this->translation(self::ENGLISH), $key)->assertOk();
        $this->send($this->translation(self::ENGLISH), $key)->assertOk()->assertJsonPath('replayed', true);

        Http::assertSentCount(1);
        $this->assertSame(self::SOURCE, WaMessage::where('direction', 'out')->sole()->payload['_source_text']);
    }

    public function test_a_closed_window_refuses_both_the_preview_and_the_send(): void
    {
        $this->fakeGraph();
        $this->conv->forceFill(['last_inbound_at' => now()->subDays(2)])->save();

        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$this->conv->id}/translate-reply", ['text' => self::SOURCE, 'target' => 'en'])
            ->assertStatus(422)->assertJsonPath('code', 'WINDOW_CLOSED');
        $this->send($this->translation(self::ENGLISH))->assertStatus(422)->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }
}

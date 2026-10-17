<?php

namespace Tests\Feature\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\SendKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * منع التكرار من جهة الخادم (E19.5): Idempotency-Key لرسالة الموظف، ومفتاح السعر، ومفتاح رد البوت.
 * نقرتان أو إعادة بالمفتاح نفسه لا تُخرجان رسالتين للعميل. موظف خارج النطاق يُرد 404 قبل أي مفتاح (ConversationScopeTest، المسار send).
 */
class SendIdempotencyTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const UUID = '3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.send_ledger' => true]);
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all', 'quotes.view', 'quotes.price'])->create();
    }

    private function postSend(WaConversation $conv, string $body, ?string $key = self::UUID)
    {
        return $this->actingAs($this->staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => $body],
            $key !== null ? ['Idempotency-Key' => $key] : []);
    }

    public function test_the_same_key_twice_sends_once_and_replays(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->postSend($conv, 'موعدك غداً')->assertOk()->assertJsonPath('status', 'sent');
        $this->postSend($conv, 'موعدك غداً')->assertOk()->assertJsonPath('replayed', true);

        Http::assertSentCount(1);
        $this->assertSame(1, WaMessage::count());
        $this->assertSame('staff:'.self::UUID, WaMessage::sole()->send_key);
    }

    public function test_a_queued_key_answers_409_and_another_text_422(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'source' => 'staff', 'status' => 'queued',
            'send_key' => 'staff:'.self::UUID, 'body' => 'موعدك غداً', 'payload' => ['_req_hash' => hash('sha256', $conv->id.'|text|موعدك غداً')]]);

        $this->postSend($conv, 'موعدك غداً')->assertStatus(409)->assertJsonPath('code', 'SEND_IN_PROGRESS');
        $this->postSend($conv, 'نص آخر')->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
        Http::assertNothingSent();
    }

    public function test_an_unknown_key_answers_409_with_unknown(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->postSend($conv, 'موعدك غداً')->assertStatus(202)->assertJsonPath('unknown', true)->assertJsonPath('status', 'unknown');
        $this->postSend($conv, 'موعدك غداً')->assertStatus(409)->assertJsonPath('code', 'OUTCOME_UNKNOWN')->assertJsonPath('unknown', true);

        Http::assertSentCount(1);
    }

    public function test_a_missing_header_still_sends_and_is_counted(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->postSend($conv, 'أهلاً', null)->assertOk();

        $this->assertStringStartsWith('staff:', (string) WaMessage::sole()->send_key);
        $this->assertSame(1, (int) Cache::get('wa:idem:missing:'.now()->format('Ymd')));
    }

    public function test_the_same_price_twice_within_ten_minutes_sends_once(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();
        $qr = QuoteRequest::factory()->create(['conversation_id' => $conv->id, 'status' => 'new']);
        $price = fn (array $extra = []) => $this->actingAs($this->staff)->post("/quote-requests/{$qr->id}/price",
            ['quoted_price_jod' => 350, 'quoted_note' => 'شامل الفندق', 'send_to_client' => true] + $extra);

        $price()->assertSessionHas('success');
        $price(['confirm_resend' => true])->assertSessionHas('success');
        $this->assertSame(2, WaMessage::count(), '«أعد الإرسال» الصريح يرسل');

        Http::assertSentCount(2);
    }

    public function test_a_double_submit_of_the_same_price_replays(): void
    {
        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();
        $qr = QuoteRequest::factory()->create(['conversation_id' => $conv->id, 'status' => 'new']);
        $key = SendKey::quote($qr, 350, 'شامل الفندق');

        $this->actingAs($this->staff)->post("/quote-requests/{$qr->id}/price", ['quoted_price_jod' => 350, 'quoted_note' => 'شامل الفندق', 'send_to_client' => true]);
        // الطلب الثاني قبل أن تصير الحالة sent في الواجهة (نقرتان): المفتاح نفسه يعيد replayed
        QuoteRequest::whereKey($qr->id)->update(['status' => 'priced']);
        $this->actingAs($this->staff)->post("/quote-requests/{$qr->id}/price", ['quoted_price_jod' => 350, 'quoted_note' => 'شامل الفندق', 'send_to_client' => true])
            ->assertSessionHas('success', 'أُرسل هذا السعر نفسه للعميل قبل قليل — لم يُرسل مرة ثانية.');

        Http::assertSentCount(1);
        $this->assertSame($key, WaMessage::sole()->send_key);
    }

    public function test_the_thread_offers_an_explicit_resend_only_for_released_or_reaped_rows(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $released = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'failed', 'attempts' => 2, 'payload' => ['_released_key' => 'reply:1:1:text']]);
        $reaped = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'unknown', 'attempts' => 1, 'payload' => ['_reaped' => now()->toIso8601String()]]);
        $fresh = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'status' => 'unknown', 'attempts' => 1]);

        $rows = collect($this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->json('messages'))->keyBy('id');

        $this->assertSame([true, 2], [$rows[$released->id]['can_resend'], $rows[$released->id]['attempts']]);
        $this->assertTrue($rows[$reaped->id]['can_resend']);
        $this->assertFalse($rows[$fresh->id]['can_resend'], 'غير مؤكد حديث: ينتظر ويبهوك ميتا أو المنظّف');
        $this->assertArrayNotHasKey('payload', $rows[$released->id]);

        config(['features.env.send_ledger' => false]);
        $plain = collect($this->actingAs($this->staff)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->json('messages'))->keyBy('id');
        $this->assertArrayNotHasKey('can_resend', $plain[$released->id]);
    }
}

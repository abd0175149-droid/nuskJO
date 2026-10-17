<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * بوابة المحتوى الصادر (E15.9): تسجيل أولاً ثم حجب لرسائل البوت والنظام مع تحويل لموظف بلا رسالة ثانية، وتحذير للموظف
 * يؤكده (409 ثم ack مسجَّل)، والرسائل التي يبنيها الكود بلا تأكيد، والإغلاق عند الفشل للآلي والفتح للموظف.
 */
class OutboundPolicyTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const CARD = '4111 1111 1111 1111';

    /** IBAN صالح (mod-97) لحساب مثال، وآخر للشركة في القائمة المسموحة */
    private const IBAN = 'GB82WEST12345698765432';

    private const COMPANY_IBAN = 'GB33BUKB20201555555555';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureChannel();
        $this->fakeGraph();
    }

    private function enforce(): void
    {
        config(['features.env.outbound_policy_enforce' => true]);
    }

    private function customer(): WaConversation
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'مرحبا']);

        return $conv;
    }

    private function graphTexts(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'))
            ->map(fn ($p) => $p[0]['text']['body'] ?? null)->filter()->values()->all();
    }

    public function test_log_mode_sends_and_records_the_decision(): void
    {
        $conv = $this->customer();

        $res = WhatsAppChannel::sendText($conv, 'ادفع بالبطاقة '.self::CARD, 'bot');

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $this->graphTexts());
        $out = $conv->messages()->where('direction', 'out')->sole();
        $this->assertSame('sent', $out->status);
        $this->assertSame(['mode' => 'log', 'context' => 'reply', 'reasons' => ['card']], $out->payload['_policy']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && !isset($r['_policy']));
    }

    public function test_enforced_block_keeps_no_content_and_hands_off_without_a_second_message(): void
    {
        $this->enforce();
        $conv = $this->customer();
        $this->fakeGemini('رقم بطاقتك المسجّلة '.self::CARD);

        $result = BotEngine::reply($conv);

        $this->assertFalse($result['sent']);
        $this->assertSame([], $this->graphTexts(), 'لا شيء يصل ميتا');
        $out = $conv->messages()->where('direction', 'out')->sole();
        $this->assertSame('blocked', $out->status);
        $this->assertSame('POLICY_CARD', $out->error_code);
        $this->assertSame('[حُجبت رسالة آلية: رقم بطاقة]', $out->body);
        $this->assertStringNotContainsString('4111', json_encode($out->payload));
        $this->assertSame(64, strlen($out->payload['_policy']['sha256']));

        $conv->refresh();
        $this->assertTrue($conv->needs_attention);
        $this->assertSame('policy_block', $conv->attention_reason);
    }

    public function test_the_company_iban_passes_and_another_iban_is_blocked(): void
    {
        $this->enforce();
        config(['whatsapp.outbound_policy.allow_ibans' => [self::COMPANY_IBAN]]);
        $conv = $this->customer();

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'حسابنا: '.self::COMPANY_IBAN, 'bot')['ok']);
        $blocked = WhatsAppChannel::sendText($conv, 'حوّل إلى '.chunk_split(self::IBAN, 4, ' '), 'bot');

        $this->assertSame('POLICY_BLOCKED', $blocked['code']);
        $this->assertSame(['sent', 'blocked'], $conv->messages()->where('direction', 'out')->orderBy('id')->pluck('status')->all());
    }

    public function test_raw_json_internal_words_and_tool_names_are_blocked_from_the_bot(): void
    {
        $this->enforce();
        $conv = $this->customer();

        foreach (['{"cost_jod": 120}' => 'POLICY_RAW_JSON', 'التكلفة علينا 300 دينار' => 'POLICY_INTERNAL_FIELD',
            'سأستدعي get_my_balance الآن' => 'POLICY_TOOL_LEAK', 'SQLSTATE[HY000]: error' => 'POLICY_TRACE'] as $text => $code) {
            $this->assertSame('POLICY_BLOCKED', WhatsAppChannel::sendText($conv, $text, 'bot')['code'], $text);
            $this->assertSame($code, $conv->messages()->latest('id')->first()->error_code, $text);
        }
        $this->assertTrue(WhatsAppChannel::sendText($conv, 'العرض يبدأ من 450 د.أ للفرد.', 'bot')['ok'], 'رد عادي يمر');
    }

    public function test_button_and_list_titles_are_checked_too(): void
    {
        $this->enforce();
        $conv = $this->customer();

        $res = WhatsAppChannel::sendButtons($conv, 'اختر', [['id' => 'a', 'title' => 'margin 20%']], 'bot');

        $this->assertSame('POLICY_BLOCKED', $res['code']);
        $this->assertContains('margin 20%', OutboundPolicy::texts(['interactive' => ['action' => ['buttons' => [['reply' => ['title' => 'margin 20%']]]]]], ''));
    }

    public function test_admin_chats_are_internal_and_not_checked(): void
    {
        $this->enforce();
        $conv = $this->customer();
        $conv->forceFill(['is_admin_chat' => true])->save();

        $this->assertTrue(WhatsAppChannel::sendText($conv, 'الربح profit_jod 120، الأداة save_note مفعّلة', 'bot')['ok']);
    }

    public function test_a_failure_inside_the_check_blocks_the_bot_and_lets_staff_through(): void
    {
        $this->enforce();
        config(['whatsapp.outbound_policy.allow_ibans' => [new \stdClass]]);
        $conv = $this->customer();

        $this->assertSame('POLICY_BLOCKED', WhatsAppChannel::sendText($conv, 'الحساب '.self::IBAN, 'bot')['code']);
        $this->assertSame('POLICY_ERROR', $conv->messages()->latest('id')->first()->error_code);

        $admin = User::factory()->admin()->create();
        $this->assertTrue(WhatsAppChannel::sendText($conv->fresh(), 'الحساب '.self::IBAN, 'staff', $admin->id)['ok']);
    }

    public function test_staff_get_a_warning_and_send_after_acknowledging_it(): void
    {
        $this->enforce();
        config(['audit.extended' => true]);
        $conv = $this->customer();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'رقم جوازك N1234567'])
            ->assertStatus(409)->assertJson(['code' => 'POLICY_WARN', 'reasons' => ['passport']]);
        $this->assertSame([], $this->graphTexts());

        $this->actingAs($admin)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'رقم جوازك N1234567', 'ack_warnings' => true])
            ->assertOk();
        $this->assertSame(['رقم جوازك N1234567'], $this->graphTexts());
        $this->assertSame(['passport'], AuditLog::where('action', AuditAction::WaPolicyOverride->value)->sole()->changes['reasons']);
    }

    public function test_messages_built_by_code_record_warnings_without_asking(): void
    {
        $conv = $this->customer();
        $quote = QuoteRequest::factory()->forConversation($conv)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/quote-requests/{$quote->id}/price", ['quoted_price_jod' => 300, 'quoted_note' => 'أرسل صورة الجواز AB123456', 'send_to_client' => true])
            ->assertSessionHasNoErrors();

        $out = $conv->messages()->where('direction', 'out')->sole();
        $this->assertSame('sent', $out->status);
        $this->assertSame(['mode' => 'log', 'context' => 'code', 'warnings' => ['passport']], $out->payload['_policy']);
    }

    public function test_the_report_counts_decisions_and_masks_samples(): void
    {
        $conv = $this->customer();
        WhatsAppChannel::sendText($conv, 'بطاقة '.self::CARD, 'bot');

        $this->artisan('whatsapp:policy-report', ['--days' => 1])
            ->expectsOutputToContain('رقم بطاقة (card)')
            ->doesntExpectOutputToContain('4111')
            ->assertSuccessful();
    }
}

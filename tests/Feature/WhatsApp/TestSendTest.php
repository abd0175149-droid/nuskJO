<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\TestSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * «إرسال تجريبي» (E23): رقم إدارة معلَّم وحده، بنافذة مفتوحة، ونص ثابت من الكود، بالمصدر test عبر WhatsAppChannel،
 * وحد 10 في الساعة للنظام. والرسالة التجريبية خارج سياق المساعد وبوابة «آخر رسالة».
 */
class TestSendTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $owner;

    private WaAdminNumber $number;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.wa_test_send' => true, 'app.url' => 'https://nuskjo.example.test']);
        RateLimiter::clear(TestSend::RATE_KEY);
        $this->configureChannel();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->number = WaAdminNumber::factory()->create(['phone' => $this->conv->phone, 'test_recipient' => true]);
    }

    private function send(array $data = [], ?string $key = null)
    {
        return $this->actingAs($this->owner)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson('/api/whatsapp/test-send', $data + ['admin_number_id' => $this->number->id, 'kind' => 'text']);
    }

    public function test_a_text_test_goes_through_the_channel_with_the_test_source_and_is_audited(): void
    {
        $this->fakeGraph();

        $res = $this->send()->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertSame('wamid.OUT.1', $res->json('wamid'));
        $row = WaMessage::findOrFail($res->json('message_id'));
        $this->assertSame(['test', 'out', 'sent'], [$row->source, $row->direction, $row->status]);
        $this->assertStringStartsWith('🧪 رسالة تجريبية من نُسك', $row->body);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $this->conv->phone && $r['type'] === 'text');
        $audit = AuditLog::where('action', AuditAction::WaTestSend->value)->sole();
        $this->assertSame(['kind' => 'text', 'admin_number_id' => $this->number->id], array_intersect_key((array) $audit->changes, ['kind' => 1, 'admin_number_id' => 1]));

        $this->actingAs($this->owner)->getJson("/api/whatsapp/test-send/{$row->id}/status")->assertOk()->assertJson(['status' => 'sent']);
    }

    public function test_every_kind_uses_fixed_content(): void
    {
        $this->fakeGraph();

        foreach (['image', 'buttons', 'list'] as $kind) {
            $this->send(['kind' => $kind])->assertOk()->assertJson(['ok' => true]);
        }

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->values();
        $this->assertSame('https://nuskjo.example.test/images/wa-test-card.png', $sent[0]['image']['link']);
        $this->assertSame(['test:1', 'test:2'], array_column(array_column($sent[1]['interactive']['action']['buttons'], 'reply'), 'id'));
        $this->assertSame(['test:a', 'test:b'], array_column($sent[2]['interactive']['action']['sections'][0]['rows'], 'id'));
        $this->assertSame(3, WaMessage::where('source', 'test')->count());
    }

    public function test_free_text_is_never_accepted(): void
    {
        $this->fakeGraph();

        $this->send(['body' => 'نص حر من المستخدم'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send(['kind' => 'template'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_an_unmarked_number_is_refused(): void
    {
        $this->fakeGraph();
        $this->number->update(['test_recipient' => false]);

        $this->send()->assertUnprocessable()->assertJson(['code' => 'NOT_TEST_RECIPIENT']);
        Http::assertNothingSent();
    }

    public function test_a_closed_window_is_refused_without_any_request(): void
    {
        $this->fakeGraph();
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);

        $res = $this->send()->assertUnprocessable();

        $this->assertSame('اطلب من صاحب الرقم مراسلة رقم الشركة أولاً — لا نرسل خارج نافذة 24 ساعة ولا قوالب.', $res->json('error'));
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_the_eleventh_send_within_an_hour_is_refused(): void
    {
        $this->fakeGraph();
        for ($i = 0; $i < 10; $i++) {
            $this->travel(25)->seconds();
            $this->send()->assertOk();
        }

        $this->travel(25)->seconds();
        $this->send()->assertStatus(429)->assertJson(['code' => 'RATE_LIMITED']);
        $this->assertSame(10, WaMessage::where('source', 'test')->count());
    }

    public function test_the_same_idempotency_key_never_sends_twice_with_the_ledger(): void
    {
        config(['features.env.send_ledger' => true]);
        $this->fakeGraph();
        $key = (string) Str::uuid();

        $this->send([], $key)->assertOk();
        $this->send([], $key)->assertOk();

        $this->assertSame(1, WaMessage::where('source', 'test')->count());
        Http::assertSentCount(1);
    }

    public function test_suspension_still_blocks_a_test(): void
    {
        $this->fakeGraph();
        WaBotSetting::current()->update(['wa_suspended' => true]);

        $this->send()->assertOk()->assertJson(['ok' => false, 'code' => 'SENDING_SUSPENDED']);
        Http::assertNothingSent();
    }

    public function test_permission_flag_and_status_scope(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();
        $this->actingAs($staff)->postJson('/api/whatsapp/test-send', ['admin_number_id' => $this->number->id, 'kind' => 'text'])->assertForbidden();

        $customer = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id]);
        $this->actingAs($this->owner)->getJson("/api/whatsapp/test-send/{$customer->id}/status")->assertNotFound();

        config(['features.env.wa_test_send' => false]);
        $this->send()->assertNotFound();
    }

    public function test_the_admin_assistant_ignores_the_test_message_as_last_outbound(): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'مرحبا', 'created_at' => now()->subMinutes(3)]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'source' => 'test', 'body' => '🧪 رسالة تجريبية', 'created_at' => now()->subMinute()]);
        $this->fakeGraph();
        $this->fakeGemini('أهلاً، كيف أساعدك؟');

        $result = BotEngine::reply($this->conv->fresh());

        $this->assertTrue($result['sent'], (string) ($result['reason'] ?? ''));
        $prompt = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $body = json_encode(json_decode($prompt->body(), true), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مرحبا', $body);
        $this->assertStringNotContainsString('رسالة تجريبية', $body);
    }

    public function test_the_settings_page_lists_marked_recipients_by_name_and_last_four_digits(): void
    {
        $this->actingAs($this->owner)->get('/whatsapp/settings')->assertInertia(fn ($p) => $p
            ->where('diagnostics.test_send', true)
            ->where('diagnostics.recipients.0.id', $this->number->id)
            ->where('diagnostics.recipients.0.last4', substr($this->conv->phone, -4))
            ->where('diagnostics.recipients.0.window_open', true)
            ->missing('diagnostics.recipients.0.phone'));
    }

    public function test_the_admin_numbers_page_saves_the_toggle(): void
    {
        $this->actingAs($this->owner)->put("/whatsapp/admin-numbers/{$this->number->id}", [
            'phone' => '+'.$this->number->phone, 'name' => $this->number->name, 'permissions' => [], 'is_active' => true, 'test_recipient' => false,
        ])->assertRedirect();

        $this->assertFalse($this->number->fresh()->test_recipient);
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaQuickReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الردود الجاهزة (E17.7): لا سعر ثابت، ونص الدفع ينتظر الاعتماد، ولا طلب لجواز عبر واتساب، والمتغيرات تُملأ
 * في الخادم من المحادثة ومن صلاحيات الموظف، ولا تصل {{…}} إلى عميل أبداً.
 */
class QuickReplyTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.quick_replies' => true, 'audit.extended' => true]);
        $this->manager = $this->staffWith('qr-manager', ['whatsapp.quick_replies']);
        $this->agent = $this->staffWith('agent', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
    }

    private function save(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post('/whatsapp/quick-replies', $data + ['title' => 'رد', 'category' => 'general']);
    }

    private function reply(string $body, array $attrs = []): WaQuickReply
    {
        return WaQuickReply::create($attrs + ['title' => 'رد', 'body' => $body, 'category' => 'general', 'is_active' => true]);
    }

    private function render(WaConversation $c, WaQuickReply $r, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->getJson("/api/whatsapp/conversations/{$c->id}/quick-replies/render?reply={$r->id}");
    }

    public function test_management_needs_its_permission(): void
    {
        $this->save(['body' => 'أهلاً {{name}}'], $this->agent)->assertForbidden();
        $this->actingAs($this->agent)->get('/whatsapp/quick-replies')->assertForbidden();

        $this->save(['body' => 'أهلاً {{name}}'])->assertSessionHasNoErrors();
        $r = WaQuickReply::firstOrFail();
        $this->assertTrue($r->is_active);
        $this->actingAs($this->manager)->put("/whatsapp/quick-replies/{$r->id}", ['title' => 'ترحيب', 'body' => 'مرحباً {{name}}', 'category' => 'general'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->delete("/whatsapp/quick-replies/{$r->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($r);
        $this->assertSame(3, AuditLog::where('entity_type', 'wa_quick_reply')->count());
    }

    public function test_a_fixed_price_is_refused_in_latin_or_arabic_digits(): void
    {
        $this->save(['body' => 'السعر 450 دينار للشخص'])->assertSessionHasErrors('body');
        $this->save(['body' => 'السعر ٤٥٠ د.أ'])->assertSessionHasErrors('body');
        $this->save(['body' => 'JOD 99 only'])->assertSessionHasErrors('body');
        $this->save(['body' => 'السعر {{quoted_price}} للشخص'])->assertSessionHasNoErrors();
    }

    public function test_unknown_variables_and_requests_for_passports_are_refused(): void
    {
        $this->save(['body' => 'رصيدك {{balance}}'])->assertSessionHasErrors('body');
        $this->save(['body' => 'يرجى إرسال صورة جواز السفر هنا'])->assertSessionHasErrors('body');
        $this->save(['body' => 'أحضر صورة الجواز إلى المكتب'])->assertSessionHasNoErrors();
    }

    public function test_payment_text_waits_for_approval_and_returns_to_it_after_edits(): void
    {
        $this->save(['body' => 'الدفع عبر CliQ باسم الشركة'])->assertSessionHasNoErrors();
        $r = WaQuickReply::firstOrFail();
        $this->assertTrue($r->needs_approval);
        $this->assertFalse($r->is_active);
        $this->actingAs($this->agent)->getJson('/api/whatsapp/quick-replies')->assertOk()->assertJsonCount(0, 'replies');

        // الاعتماد لمن يملك whatsapp.settings وحده
        $this->actingAs($this->manager)->post("/whatsapp/quick-replies/{$r->id}/approve")->assertForbidden();
        $admin = $this->staffWith('owner', ['whatsapp.quick_replies', 'whatsapp.settings']);
        $this->actingAs($admin)->post("/whatsapp/quick-replies/{$r->id}/approve")->assertSessionHasNoErrors();
        $this->assertTrue($r->fresh()->is_active);
        $this->actingAs($this->agent)->getJson('/api/whatsapp/quick-replies')->assertJsonCount(1, 'replies');
        $this->assertDatabaseHas('audit_logs', ['action' => 'wa.quick_reply_approve', 'severity' => 'warn']);

        $this->actingAs($this->manager)->put("/whatsapp/quick-replies/{$r->id}", ['title' => 'رد', 'body' => 'الدفع عبر CliQ أو حوالة', 'category' => 'general', 'is_active' => true]);
        $this->assertTrue($r->fresh()->needs_approval);
        $this->assertFalse($r->fresh()->is_active);
        $this->assertNull($r->fresh()->approved_by);
    }

    public function test_render_fills_variables_from_the_linked_conversation(): void
    {
        Setting::set('company_phone', '+962 6 000 0000');
        $client = Client::factory()->create(['name' => 'أحمد سالم', 'code' => 'C-77']);
        $c = WaConversation::factory()->windowOpen()->create(['client_id' => $client->id]);
        $r = $this->reply('أهلاً {{name}} ({{client_code}})، معك {{staff_name}} — {{company_phone}}');

        $this->render($c, $r)->assertOk()
            ->assertJsonPath('missing', [])
            ->assertJsonPath('text', 'أهلاً أحمد سالم (C-77)، معك '.$this->agent->name.' — +962 6 000 0000')
            ->assertJsonPath('window_open', true);
    }

    public function test_an_unlinked_conversation_leaves_name_missing(): void
    {
        $c = WaConversation::factory()->windowOpen()->create(['wa_profile_name' => 'لقب غير موثق']);
        $r = $this->reply('أهلاً {{name}}');

        $this->render($c, $r)->assertOk()->assertJsonPath('missing', ['name'])->assertJsonPath('text', 'أهلاً {{name}}');
    }

    public function test_the_price_variable_needs_the_pricing_permission(): void
    {
        $c = WaConversation::factory()->windowOpen()->create();
        QuoteRequest::factory()->create(['conversation_id' => $c->id, 'quoted_price_jod' => 450, 'status' => 'priced']);
        $r = $this->reply('السعر {{quoted_price}}');

        $this->render($c, $r)->assertJsonPath('missing', ['quoted_price']);

        $pricer = $this->staffWith('pricer', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'quotes.price']);
        $this->render($c, $r, $pricer)->assertJsonPath('text', 'السعر 450.000 د.أ');
    }

    public function test_render_respects_scope_and_the_flag(): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $hidden = $this->conversationTagged(['visa']);
        $r = $this->reply('أهلاً');
        $this->render($hidden, $r, $specialist)->assertNotFound();

        $inactive = $this->reply('قديم', ['is_active' => false]);
        $this->render(WaConversation::factory()->windowOpen()->create(), $inactive)->assertNotFound();

        config(['features.env.quick_replies' => false]);
        $this->render(WaConversation::factory()->windowOpen()->create(), $r)->assertNotFound();
        $this->actingAs($this->agent)->getJson('/api/whatsapp/quick-replies')->assertNotFound();
    }

    public function test_send_refuses_an_unfilled_variable_before_any_call_to_meta(): void
    {
        Http::fake();
        $c = WaConversation::factory()->windowOpen()->create();

        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$c->id}/send", ['body' => 'أهلاً {{ name }}'])
            ->assertStatus(422)->assertJsonPath('code', 'UNFILLED_VARIABLE');
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());

        // «{name}» بقوس واحد ليس متغيراً
        $this->fakeGraph();
        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$c->id}/send", ['body' => 'أهلاً {name}'])->assertOk();
        $this->assertSame('staff', WaMessage::latest('id')->first()->source);
    }

    public function test_a_closed_window_still_refuses_like_any_staff_reply(): void
    {
        $this->fakeGraph();
        $c = WaConversation::factory()->windowClosed()->create();
        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$c->id}/send", ['body' => 'أهلاً'])
            ->assertStatus(422)->assertJsonPath('code', 'WINDOW_EXPIRED');
    }
}

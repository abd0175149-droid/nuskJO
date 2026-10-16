<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * رسالة التصحيح المقتبسة (E33، العلم wa_reply_quote، C009): لا تعديل في Cloud API. التصحيح نص مقتبس من الخاطئة،
 * والأصل لا يتغيّر (دليل عند نزاع على سعر)، وصف تدقيق بلا نص، والبوت يعتمد التصحيح ولا يكرر الرقم الخاطئ.
 */
class CorrectionTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $staff;

    private WaConversation $conv;

    private WaMessage $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_reply_quote' => true]);
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'كم سعر الغرفة الثلاثية؟']);
        $this->original = WaMessage::factory()->fromStaff($this->staff)->create([
            'conversation_id' => $this->conv->id, 'wamid' => 'wamid.OUT.ORIG', 'body' => 'السعر 150 دينار للفرد',
        ]);
    }

    private function correct(WaMessage $message, string $body = 'تصحيح: السعر 165 دينار للفرد', ?WaConversation $conv = null)
    {
        $conv ??= $this->conv;

        return $this->actingAs($this->staff)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/whatsapp/conversations/{$conv->id}/messages/{$message->id}/correct", ['body' => $body]);
    }

    public function test_the_correction_quotes_the_original_and_the_original_body_never_changes(): void
    {
        config(['audit.extended' => false]);
        $this->fakeGraph();

        $this->correct($this->original)->assertOk()->assertJson(['ok' => true]);

        $request = collect(Http::recorded())->map(fn ($p) => $p[0])->sole();
        $this->assertSame(['message_id' => 'wamid.OUT.ORIG'], $request['context']);
        $this->assertSame('تصحيح: السعر 165 دينار للفرد', $request['text']['body']);

        $original = $this->original->fresh();
        $correction = WaMessage::where('direction', 'out')->orderByDesc('id')->first();
        $this->assertSame('السعر 150 دينار للفرد', $original->body);
        $this->assertSame($correction->id, data_get($original->payload, '_corrected_by.0.message_id'));
        $this->assertSame($this->staff->id, data_get($original->payload, '_corrected_by.0.staff_id'));
        $this->assertSame(['wamid.OUT.ORIG', $this->original->id], [$correction->reply_to_wamid, data_get($correction->payload, '_meta.corrects_message_id')]);

        // صف تدقيق دائم (ولو كان التدقيق الموسّع مطفأً) بالمعرّفات وحدها
        $audit = AuditLog::where('action', 'wa.msg_correct')->sole();
        $this->assertSame([$this->original->id, $correction->id, $this->conv->id],
            [$audit->entity_id, $audit->changes['new_message_id'], $audit->changes['conversation_id']]);
        $this->assertStringNotContainsString('165', json_encode($audit->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('150', json_encode($audit->changes, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_bot_context_replaces_the_wrong_message_and_tags_the_correction(): void
    {
        $this->fakeGraph();
        $this->correct($this->original)->assertOk();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'تمام، كم للأطفال؟']);

        $messages = (new \ReflectionMethod(BotEngine::class, 'buildMessages'))->invoke(null, $this->conv->fresh(), WaBotSetting::current(), null);
        $text = implode("\n", array_column($messages, 'text'));

        $this->assertStringNotContainsString('150', $text, 'لا يرى النموذج الرقم الخاطئ');
        $this->assertStringContainsString('[رسالة سابقة أُرسل لها تصحيح — اعتمد نص التصحيح]', $text);
        $this->assertStringContainsString('[تصحيح لرسالة سابقة] تصحيح: السعر 165 دينار للفرد', $text);
    }

    public function test_a_failed_original_or_an_inbound_message_cannot_be_corrected(): void
    {
        $this->fakeGraph();
        $failed = WaMessage::factory()->fromStaff($this->staff)->create(['conversation_id' => $this->conv->id, 'wamid' => null, 'status' => 'failed']);
        $inbound = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id]);

        $this->correct($failed)->assertStatus(422)->assertJson(['error' => 'فشل إرسال الرسالة الأصلية فلا يمكن اقتباسها.']);
        $this->correct($inbound)->assertStatus(422)->assertJson(['code' => 'NOT_CORRECTABLE']);
        Http::assertNothingSent();
    }

    public function test_a_message_of_another_conversation_in_the_url_is_not_found(): void
    {
        $this->fakeGraph();
        $elsewhere = WaMessage::factory()->fromStaff($this->staff)->create(['wamid' => 'wamid.ELSEWHERE']);

        $this->correct($elsewhere)->assertNotFound();
        Http::assertNothingSent();
        $this->assertNull(data_get($elsewhere->fresh()->payload, '_corrected_by'));
    }

    public function test_a_closed_window_refuses_without_http_and_the_flag_gates_the_route(): void
    {
        $this->fakeGraph();
        $this->conv->update(['last_inbound_at' => now()->subDays(2)]);

        $this->correct($this->original)->assertStatus(422)->assertJson(['error' => 'النافذة مغلقة — تواصل مع العميل هاتفياً.']);

        $this->conv->update(['last_inbound_at' => now()->subHour()]);
        config(['features.env.wa_reply_quote' => false]);
        $this->correct($this->original)->assertStatus(422)->assertJson(['code' => 'FEATURE_OFF']);
        Http::assertNothingSent();
        $this->assertNull(data_get($this->original->fresh()->payload, '_corrected_by'));
    }

    public function test_without_reply_permission_it_is_forbidden(): void
    {
        $this->fakeGraph();
        $this->staff = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);

        $this->correct($this->original)->assertForbidden();
        Http::assertNothingSent();
    }
}

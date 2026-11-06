<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReplyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * رسالة لا يعرضها واتساب ويب (E33، منذ 2026-11-06 من البوابة): نص عربي بحسب النوع، تُحفظ، لكن البوت لا يرد على نص
 * لا يراه: «تدخّل» بسبب «رسالة غير مدعومة» وإشعار واحد في نافذة التهدئة. والحذف والتعديل حدثان على الأصل لا رسائل،
 * ونص الأصل لا يتغيّر.
 */
class UnsupportedInboundTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private WaConversation $conv;

    private WaMessage $earlier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        User::factory()->admin()->create();
        $this->travelTo(Carbon::createFromTimestamp(1760425510, config('app.timezone')));
        $this->conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234',
            'last_inbound_at' => now()->subHours(3), 'unread_count' => 0, 'needs_attention' => false]);
        $this->earlier = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'أريد غرفة ثلاثية', 'created_at' => now()->subHours(3)]);
    }

    public static function kinds(): array
    {
        return [
            'poll' => [['type' => 'poll_creation', 'poll' => ['name' => 'أي موعد يناسبكم؟']], '📊 أرسل العميل استطلاعاً: أي موعد يناسبكم؟'],
            'masked' => [['type' => 'masked'], '🔒 رسالة لا يعرضها واتساب على الأجهزة المرتبطة — افتحها على هاتف الرقم'],
            'unknown' => [['type' => 'list_response_v9'], '⚠️ رسالة لا يعرضها واتساب ويب (list_response_v9)'],
        ];
    }

    private function postUnsupported(array $over): void
    {
        $this->postOpenWa('message.received', $this->incoming('16505551234', '', $over + ['timestamp' => now()->getTimestamp()]))->assertOk();
    }

    #[DataProvider('kinds')]
    public function test_an_arabic_text_reaches_staff_and_never_the_bot(array $over, string $body): void
    {
        Queue::fake();

        $this->postUnsupported($over);

        $message = WaMessage::where('msg_type', 'unsupported')->sole();
        $this->assertSame($body, $message->body);
        $conv = $this->conv->fresh();
        $this->assertSame([true, 'unsupported', 1], [$conv->needs_attention, $conv->attention_reason, $conv->unread_count]);
        $this->assertTrue($conv->last_inbound_at->gt(now()->subMinutes(5)), 'رسالة حقيقية من العميل');
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        $this->assertSame('أريد غرفة ثلاثية', $this->earlier->fresh()->body);
    }

    public function test_one_notice_within_the_cooldown_for_consecutive_polls(): void
    {
        Queue::fake();

        $this->postUnsupported(['type' => 'poll_creation']);
        $this->postUnsupported(['type' => 'masked']);

        $this->assertSame(2, WaMessage::where('msg_type', 'unsupported')->count());
        $this->assertSame(1, Notification::where('title', 'like', '%تحتاج تدخّلاً%')->count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_an_edit_or_revoke_marks_the_original_and_adds_no_message(): void
    {
        Queue::fake();
        $this->earlier->forceFill(['wamid' => 'false_16505551234@c.us_EARLY1'])->save();

        $this->postOpenWa('message.edited', ['messageId' => 'false_16505551234@c.us_EARLY1', 'body' => 'أريد غرفة رباعية'])->assertOk();
        $this->postOpenWa('message.revoked', ['revokedId' => 'false_16505551234@c.us_EARLY1'])->assertOk();

        $earlier = $this->earlier->fresh();
        $this->assertSame('أريد غرفة ثلاثية', $earlier->body, 'الأصل دليل لا يُمحى');
        $this->assertSame('أريد غرفة رباعية', $earlier->payload['_edited_body']);
        $this->assertNotNull($earlier->payload['_revoked_at']);
        $this->assertSame(1, WaMessage::count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_the_attention_reason_has_a_label_and_the_system_rule_is_updated(): void
    {
        $this->assertSame('🧩 رسالة غير مدعومة', ReplyState::REASONS['unsupported']);
        $this->assertStringContainsString('رسالة لا يعرضها واتساب ويب', BotEngine::SYSTEM_RULES);
        $this->assertStringContainsString('لا تعلّق عليها، فقد وصلت لموظف', BotEngine::SYSTEM_RULES);
    }
}

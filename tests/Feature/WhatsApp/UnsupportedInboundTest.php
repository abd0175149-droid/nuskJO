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
 * رسالة لا تدعمها منصة ميتا (E33، بلا علم لأنه تصحيح خلل): نص عربي بحسب unsupported.type، تُحفظ وتفتح النافذة، لكن
 * البوت لا يرد على نص لا يراه: «تدخّل» بسبب «رسالة غير مدعومة» وإشعار واحد في نافذة التهدئة. لا رسالة أصلية تتغيّر.
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
            'edit' => ['unsupported_edit.json', '✏️ عدّل العميل رسالة سابقة، ولا يظهر النص الجديد في منصة ميتا — راجع المحادثة واطلب منه إعادة كتابتها'],
            'poll' => ['unsupported_poll.json', '📊 أرسل العميل استطلاعاً لا تعرضه منصة ميتا'],
            'unknown' => ['unsupported.json', '⚠️ رسالة لا تدعمها منصة ميتا'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_an_arabic_text_reaches_staff_and_never_the_bot(string $fixture, string $body): void
    {
        Queue::fake();

        $this->postMetaWebhook($fixture)->assertOk();

        $message = WaMessage::where('msg_type', 'unsupported')->sole();
        $this->assertSame($body, $message->body);
        $conv = $this->conv->fresh();
        $this->assertSame([true, 'unsupported', 1], [$conv->needs_attention, $conv->attention_reason, $conv->unread_count]);
        $this->assertTrue($conv->last_inbound_at->gt(now()->subMinutes(5)), 'رسالة حقيقية تفتح النافذة');
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        $this->assertSame('أريد غرفة ثلاثية', $this->earlier->fresh()->body);
    }

    public function test_one_notice_within_the_cooldown_for_consecutive_polls(): void
    {
        Queue::fake();

        $this->postMetaWebhook('unsupported_poll.json')->assertOk();
        $this->postMetaWebhook('unsupported_edit.json')->assertOk();

        $this->assertSame(2, WaMessage::where('msg_type', 'unsupported')->count());
        $this->assertSame(1, Notification::where('title', 'like', '%تحتاج تدخّلاً%')->count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
    }

    public function test_an_explicit_edit_or_revoke_type_is_treated_as_unsupported(): void
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('unsupported_edit.json')), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0', [
            'from' => '16505551234', 'id' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgASGBQ5QzY5QjBDOUY5Rjg0ODM0QjlKRgA=', 'timestamp' => '1760425505',
            'type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid.X'],
        ]);

        $this->postRawWebhook(json_encode($payload))->assertOk();

        $message = WaMessage::latest('id')->first();
        $this->assertSame(['unsupported', '🗑️ حذف العميل رسالة سابقة من جهازه، ويبقى نصها الأصلي هنا'], [$message->msg_type, $message->body]);
        $this->assertSame('أريد غرفة ثلاثية', $this->earlier->fresh()->body);
        $this->assertSame('unsupported', $this->conv->fresh()->attention_reason);
    }

    public function test_the_attention_reason_has_a_label_and_the_system_rule_is_updated(): void
    {
        $this->assertSame('🧩 رسالة غير مدعومة', ReplyState::REASONS['unsupported']);
        $this->assertStringContainsString('رسالة لا تدعمها منصة ميتا', BotEngine::SYSTEM_RULES);
        $this->assertStringContainsString('لا تعلّق عليها، فقد وصلت لموظف', BotEngine::SYSTEM_RULES);
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\EchoHandler;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * أصداء التعايش بالمعالجة الكاملة (E39، العلم coexistence_echoes): رسالة الموظف من الهاتف تُحفظ صادرة بالمصدر
 * staff_phone ومرسلها الحامل، وتوقف البوت فوراً ولا تفتح النافذة، والتعديل والحذف لا يمسان الأصل.
 */
class EchoWebhookTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private User $holder;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel(['pause_minutes' => 30]);
        config(['features.env.coexistence_echoes' => true]);
        $this->holder = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'phone_holder')->create(['name' => 'حامل الهاتف']);
        DB::table('wa_bot_settings')->update(['coexistence_holder_user_id' => $this->holder->id]);
        $this->assertTrue(EchoHandler::fullOn());
    }

    private function conversation(array $attributes = []): WaConversation
    {
        $conv = WhatsAppInbox::conversationFor('16505551234');
        $conv->forceFill($attributes)->save();

        return $conv->fresh();
    }

    public function test_a_text_echo_is_stored_as_the_holders_message_pauses_the_bot_and_never_opens_the_window(): void
    {
        $inbound = now()->subHours(3)->startOfSecond();
        $conv = $this->conversation(['last_inbound_at' => $inbound, 'needs_attention' => true, 'attention_reason' => 'handoff', 'unread_count' => 2]);

        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();   // إعادة ميتا

        $m = WaMessage::sole();
        $this->assertSame(['out', 'staff_phone', 'text', 'sent', $this->holder->id, 'wamid.FIXTURE.echo.1'],
            [$m->direction, $m->source, $m->msg_type, $m->status, (int) $m->staff_id, $m->wamid]);
        $this->assertSame('Reply typed in the WhatsApp Business app on the phone.', $m->body);
        $conv->refresh();
        $this->assertSame($inbound->getTimestamp(), $conv->last_inbound_at->getTimestamp(), 'الصدى لا يفتح النافذة');
        $this->assertNotNull($conv->last_outbound_at);
        $this->assertTrue($conv->bot_paused_until->isFuture());
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($conv->bot_paused_until), 1);
        $this->assertFalse($conv->needs_attention);
        $this->assertNull($conv->attention_reason);
        $this->assertSame(0, (int) $conv->unread_count);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        Http::assertNothingSent();
    }

    public function test_the_echo_pause_setting_overrides_the_staff_pause(): void
    {
        DB::table('wa_bot_settings')->update(['echo_pause_minutes' => 240]);
        $conv = $this->conversation();

        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $this->assertEqualsWithDelta(240, now()->diffInMinutes($conv->fresh()->bot_paused_until), 1);
    }

    public function test_a_bot_reply_being_generated_is_dropped_once_the_phone_answers(): void
    {
        $conv = $this->conversation();
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text', 'body' => 'سؤال', 'wamid' => 'wamid.TEST.q']);
        $run = new ReplyRun('token', (int) WaMessage::max('id'));
        $this->assertNull($run->mayDeliver($conv));

        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $this->assertSame('stale_takeover', $run->mayDeliver($conv));
    }

    public function test_an_echo_to_a_username_customer_matches_by_bsuid_in_either_field(): void
    {
        $conv = WaConversation::create(['wa_user_id' => 'US.13491208655302741918', 'bot_enabled' => true, 'status' => 'open']);

        $this->postMetaWebhook('smb_message_echoes_bsuid.json')->assertOk();
        $this->assertSame($conv->id, (int) WaMessage::sole()->conversation_id);

        // to يحمل المعرّف نفسه بدل to_user_id
        $payload = json_decode((string) file_get_contents($this->fixturePath('smb_message_echoes_bsuid.json')), true);
        $echo = &$payload['entry'][0]['changes'][0]['value']['message_echoes'][0];
        $echo['to'] = $echo['to_user_id'];
        unset($echo['to_user_id']);
        $echo['id'] = 'wamid.FIXTURE.echo.bsuid2';
        $this->postRawWebhook((string) json_encode($payload))->assertOk();

        $this->assertSame([$conv->id], WaMessage::pluck('conversation_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame(1, WaConversation::count());
    }

    public function test_an_image_echo_is_archived_like_an_inbound_file(): void
    {
        config(['features.env.media_archive' => true]);
        $this->conversation();

        $this->postMetaWebhook('smb_message_echoes_image.json')->assertOk();

        $m = WaMessage::sole();
        $this->assertSame(['image', '1104480873777231', 'pending'], [$m->msg_type, $m->media_id, $m->media_status]);
        $this->assertStringContainsString('تأكيد الحجز', $m->body);
        Queue::assertPushed(ArchiveWhatsAppMedia::class, fn ($job) => true);
    }

    public function test_an_edit_keeps_the_original_and_adds_the_new_text_as_its_own_row(): void
    {
        $this->conversation();
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $this->postMetaWebhook('smb_message_echoes_edit.json')->assertOk();
        $this->postMetaWebhook('smb_message_echoes_edit.json')->assertOk();

        $original = WaMessage::where('wamid', 'wamid.FIXTURE.echo.1')->sole();
        $this->assertSame('Reply typed in the WhatsApp Business app on the phone.', $original->body);
        $this->assertNotNull($original->payload['_edited_at'] ?? null);
        $edit = WaMessage::where('msg_type', 'edit')->sole();
        $this->assertSame(['Corrected reply typed again on the phone.', 'wamid.FIXTURE.echo.1', 'staff_phone'], [$edit->body, $edit->reply_to_wamid, $edit->source]);
        $this->assertSame(2, WaMessage::count());
    }

    public function test_a_revoke_flags_the_original_and_never_deletes_it(): void
    {
        $this->conversation();
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $this->postMetaWebhook('smb_message_echoes_revoke.json')->assertOk();

        $original = WaMessage::sole();
        $this->assertSame('Reply typed in the WhatsApp Business app on the phone.', $original->body);
        $this->assertNotNull($original->payload['_revoked_at'] ?? null);
    }

    public function test_a_revoke_of_a_message_we_never_stored_leaves_a_marker(): void
    {
        $this->conversation();

        $this->postMetaWebhook('smb_message_echoes_revoke.json')->assertOk();

        $m = WaMessage::sole();
        $this->assertSame(['revoke', '🗑️ حُذفت من الهاتف رسالة لم تُحفظ هنا'], [$m->msg_type, $m->body]);
    }

    public function test_the_staff_may_start_a_conversation_from_the_phone(): void
    {
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $conv = WaConversation::sole();
        $this->assertSame('16505551234', $conv->phone);
        $this->assertNull($conv->last_inbound_at);
        $this->assertFalse($conv->isWindowOpen());
    }

    public function test_an_echo_for_another_number_is_ignored(): void
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('smb_message_echoes.json')), true);
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '199999999999999';

        $this->postRawWebhook((string) json_encode($payload))->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
    }

    public function test_an_unqualified_holder_leaves_the_sender_empty(): void
    {
        $this->holder->forceFill(['is_active' => false])->save();
        $this->conversation();

        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $this->assertNull(WaMessage::sole()->staff_id);
    }

    public function test_the_model_sees_what_the_staff_wrote_from_the_phone(): void
    {
        $conv = $this->conversation();
        WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text', 'body' => 'هل العرض متاح؟', 'wamid' => 'wamid.TEST.q2']);
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        $build = new \ReflectionMethod(BotEngine::class, 'buildMessages');
        $messages = $build->invoke(null, $conv->fresh(), WaBotSetting::current());

        // ينتهي دائماً بدور user («(المتابعة)»)
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('Reply typed in the WhatsApp Business app', $messages[1]['text']);
    }
}

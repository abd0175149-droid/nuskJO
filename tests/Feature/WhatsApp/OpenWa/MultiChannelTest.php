<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\TopicRouter;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** رقمان وعميل واحد: محادثتان، والرد من رقم المحادثة بمفتاحه، والرؤية حسب wa_channel_users */
class MultiChannelTest extends TestCase
{
    use BuildsWhatsAppStaff, ConfiguresWhatsApp, RefreshDatabase;

    public function test_one_customer_on_two_numbers_gets_two_conversations_and_replies_leave_from_each(): void
    {
        Queue::fake();
        $this->configureChannel();
        $a = $this->testChannel();
        $b = $this->makeChannel(['name' => 'الدعم', 'openwa_session_id' => 'sess-b', 'api_key' => 'key-b']);
        $this->fakeGateway();

        $this->postOpenWa('message.received', $this->incoming('962791112233', 'من رقم أ'), $a)->assertOk();
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'من رقم ب'), $b)->assertOk();

        $convs = WaConversation::orderBy('id')->get();
        $this->assertCount(2, $convs);
        $this->assertSame([$a->id, $b->id], $convs->pluck('channel_id')->map(fn ($v) => (int) $v)->all());

        WhatsAppChannel::sendText($convs[1], 'رد من ب', 'system');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sessions/sess-b/messages/send-text')
            && $r->header('X-API-Key')[0] === 'key-b' && $r['chatId'] === '962791112233@c.us' && $r['text'] === 'رد من ب');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sessions/sess-1/messages/send-text'));
    }

    public function test_staff_assigned_to_a_number_see_only_its_conversations(): void
    {
        Queue::fake();
        $this->configureChannel();
        $a = $this->testChannel();
        $b = $this->makeChannel(['name' => 'الدعم', 'openwa_session_id' => 'sess-b']);
        $this->fakeGateway();
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'أ'), $a);
        $this->postOpenWa('message.received', $this->incoming('962794445566', 'ب'), $b);

        $agent = $this->staffWith('agent-a', self::INBOX_PERMISSIONS);
        DB::table('wa_conversations')->update(['assigned_to' => $agent->id]);
        DB::table('wa_channel_users')->insert(['channel_id' => $a->id, 'user_id' => $agent->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        // رقم ب له موظفه، فلا يُفتح لغيره
        $other = $this->staffWith('agent-b', self::INBOX_PERMISSIONS);
        DB::table('wa_channel_users')->insert(['channel_id' => $b->id, 'user_id' => $other->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $visible = WaConversation::query()->visibleTo($agent)->pluck('channel_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$a->id], $visible);
        $this->assertSame([$a->id], TopicRouter::allowedNumberIds($agent));
    }

    public function test_conversation_on_an_offline_number_is_not_sent(): void
    {
        Queue::fake();
        $this->configureChannel();
        $this->fakeGateway();
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'hi'))->assertOk();
        $this->testChannel()->forceFill(['status' => 'disconnected'])->save();

        $res = WhatsAppChannel::sendText(WaConversation::sole(), 'رد', 'system');
        $this->assertFalse($res['ok']);
        $this->assertSame('CHANNEL_NOT_READY', $res['code']);
        $this->assertSame([], $this->gatewaySends());
    }
}

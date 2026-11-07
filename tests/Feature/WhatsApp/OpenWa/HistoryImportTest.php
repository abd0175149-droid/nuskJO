<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Jobs\BackfillChannel;
use App\Jobs\ImportChannelHistory;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\EchoHandler;
use App\Services\WhatsApp\OpenWa\ChannelState;
use App\Services\WhatsApp\OpenWa\HistoryImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * استيراد سجل الهاتف عند أول ربط (OpenWa\HistoryImport): صفحات قائمة المحادثات عبر حلقات، والاستئناف من المؤشر،
 * ومنع التكرار، ووقت الرسالة نفسه، والصادر من الهاتف بلا إيقاف للبوت، ولا بوت ولا تنزيل ولا غير مقروء للسجل،
 * والعلامة إلى آخر وارد فيرد البوت على الحي وحده.
 */
class HistoryImportTest extends TestCase
{
    use BuildsWhatsAppStaff, ConfiguresWhatsApp, RefreshDatabase;

    private const A = '962791110001@c.us';

    private const B = '962791110002@c.us';

    private const C = '962791110003@c.us';

    private const GROUP = '120363000000000001@g.us';

    private const LID = '150573718479015@lid';

    /** @var array<string, list<array>> */
    private array $history = [];

    /** قائمة المحادثات كما تعيدها البوابة */
    private array $chats = [];

    /** رقم جهة @lid كما تعيده /contacts/{id}/phone */
    private array $lidPhones = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['whatsapp.openwa.import' => [
            'auto' => true, 'days' => 180, 'messages_per_chat' => 500, 'chats_page' => 2, 'sleep_ms' => 0,
            'job_budget_seconds' => 0, 'close_new' => true,
        ]]);
        $day = 86400;
        $t = now()->getTimestamp();
        $this->history = [
            self::A => [
                $this->msg(self::A, 'a1', 'السلام عليكم، كم سعر العمرة؟', $t - 5 * $day),
                $this->msg(self::A, 'a2', 'أهلاً، العمرة تبدأ من 350', $t - 5 * $day + 600, fromMe: true),
                $this->msg(self::A, 'a3', '', $t - 4 * $day, extra: ['type' => 'image', 'media' => ['mimetype' => 'image/jpeg']]),
            ],
            // أقدم من العمق (180 يوماً): لا يُستورد
            self::B => [$this->msg(self::B, 'b1', 'قديم جداً', $t - 400 * $day)],
            self::C => [$this->msg(self::C, 'c1', 'شكراً لكم', $t - 2 * $day)],
        ];
        $this->chats = [
            ['id' => self::A, 'name' => 'أبو أحمد', 'isGroup' => false, 'timestamp' => $t - 4 * 86400],
            ['id' => self::B, 'name' => 'قديم', 'isGroup' => false, 'timestamp' => $t - 400 * 86400],
            ['id' => self::GROUP, 'name' => 'مجموعة', 'isGroup' => true, 'timestamp' => $t],
            ['id' => self::C, 'name' => 'عميل', 'isGroup' => false, 'timestamp' => $t - 2 * 86400],
        ];
        $this->fakeGateway([
            'nuskjo-openwa:2785/api/sessions/*/chats*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                return Http::response(array_slice($this->chats, (int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 1000)));
            },
            'nuskjo-openwa:2785/api/sessions/*/contacts/*/phone' => function ($request) {
                preg_match('#/contacts/([^/]+)/phone#', $request->url(), $m);
                $id = rawurldecode($m[1]);

                return Http::response(['contactId' => $id, 'phone' => $this->lidPhones[$id] ?? null]);
            },
            'nuskjo-openwa:2785/api/sessions/*/messages/*/history*' => function ($request) {
                preg_match('#/messages/([^/]+)/history#', $request->url(), $m);

                return Http::response($this->history[rawurldecode($m[1])] ?? []);
            },
        ]);
    }

    private function msg(string $jid, string $id, string $body, int $ts, bool $fromMe = false, array $extra = []): array
    {
        $digits = explode('@', $jid)[0];

        return array_replace([
            'id' => ($fromMe ? 'true_' : 'false_').$jid.'_'.strtoupper($id),
            'from' => $fromMe ? self::TEST_CHANNEL_PHONE.'@c.us' : $jid, 'to' => $fromMe ? $jid : self::TEST_CHANNEL_PHONE.'@c.us',
            'chatId' => $jid, 'body' => $body, 'type' => 'text', 'timestamp' => $ts, 'fromMe' => $fromMe, 'isGroup' => false,
            'kind' => 'user', 'senderPhone' => $fromMe ? null : $digits, 'contact' => ['pushName' => 'أبو أحمد'],
        ], $extra);
    }

    /** يشغّل حلقات السلسلة الموجودة في الطابور الوهمي حتى تنتهي. @return int عدد الحلقات */
    private function drain(int $seen = 0, int $max = 50): int
    {
        $ran = 0;
        while ($ran < $max) {
            $jobs = Queue::pushed(ImportChannelHistory::class)->slice($seen)->values();
            if ($jobs->isEmpty()) {
                break;
            }
            $seen += $jobs->count();
            foreach ($jobs as $job) {
                $job->handle();
                $ran++;
            }
        }

        return $ran;
    }

    private function historyCalls(string $jid): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/messages/'.rawurlencode($jid).'/history'))->count();
    }

    private function convFor(string $jid): ?WaConversation
    {
        return WaConversation::where('jid', $jid)->first();
    }

    public function test_all_chats_are_paged_across_jobs_and_stored_as_silent_history(): void
    {
        $ch = $this->testChannel();
        $this->assertTrue(HistoryImport::start($ch, 'manual')['ok']);

        // ثلاث صفحات للقائمة + محادثتان، حلقة لكل خطوة (ميزانية صفر)
        $this->assertGreaterThanOrEqual(5, $this->drain());

        $state = HistoryImport::present($ch->refresh());
        $this->assertSame('done', $state['status']);
        // المجموعة تدخل القائمة (GroupChats) ولا سجل لها هنا، والمحادثة الأقدم من العمق لا تدخل
        $this->assertSame(3, $state['chats_total']);
        $this->assertSame(3, $state['chats_done']);
        $this->assertSame(0, $this->historyCalls(self::B));
        $this->assertSame(4, $state['messages'], 'رسالة أقدم من العمق لا تُستورد');
        $this->assertNotNull($ch->history_imported_at);
        $this->assertNull($ch->history_queue);

        $a = $this->convFor(self::A);
        $this->assertSame((int) $ch->id, (int) $a->channel_id);
        $rows = WaMessage::where('conversation_id', $a->id)->orderBy('id')->get();
        $this->assertSame(['in', 'out', 'in'], $rows->pluck('direction')->all());
        // وقت الرسالة عند واتساب، لا وقت الاستيراد
        $this->assertSame($this->history[self::A][0]['timestamp'], $rows[0]->created_at->getTimestamp());
        $this->assertSame($this->history[self::A][0]['timestamp'], $rows[0]->meta_at->getTimestamp());
        $this->assertSame(['staff_phone', 'sent'], [$rows[1]->source, $rows[1]->status]);
        $this->assertTrue((bool) data_get($rows[0]->payload, '_import'));

        // صامت: لا بوت، ولا غير مقروء، ولا «تدخّل»، ولا إيقاف للبوت بصادر الهاتف، والمحادثة مغلقة
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        $this->assertSame([0, false, null, 'closed'], [(int) $a->unread_count, (bool) $a->needs_attention, $a->bot_paused_until, $a->status]);
        $this->assertSame($rows[2]->id, (int) $a->bot_answered_in_id, 'العلامة إلى آخر وارد مستورد');
        $this->assertSame($this->history[self::A][2]['timestamp'], $a->last_message_at->getTimestamp());
        $this->assertSame(0, WaConversation::where('jid', self::GROUP)->count());
        $this->assertNull($this->convFor(self::B));
    }

    public function test_imported_media_is_kept_on_the_phone_and_not_downloaded(): void
    {
        HistoryImport::start($this->testChannel(), 'manual');
        $this->drain();

        $img = WaMessage::where('msg_type', 'image')->sole();
        $this->assertSame('remote', $img->media_status);
        $this->assertSame($img->wamid, $img->media_id);
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
        $this->assertFalse(collect(Http::recorded())->contains(fn ($p) => str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), '/media')));
    }

    public function test_running_it_again_stores_nothing_twice(): void
    {
        $ch = $this->testChannel();
        HistoryImport::start($ch, 'manual');
        $seen = $this->drain();
        $this->assertSame(4, WaMessage::count());

        $this->assertTrue(HistoryImport::start($ch->refresh(), 'manual')['ok']);
        $this->drain(Queue::pushed(ImportChannelHistory::class)->count() - 1);

        $this->assertSame(4, WaMessage::count());
        $this->assertSame('done', HistoryImport::present($ch->refresh())['status']);
        $this->assertSame(0, HistoryImport::present($ch)['messages'], 'التشغيل الثاني لم يجد جديداً');
    }

    public function test_stop_halts_the_chain_and_resume_continues_from_the_cursor(): void
    {
        config(['audit.extended' => true]);
        $ch = $this->testChannel();
        HistoryImport::start($ch, 'manual');
        // القائمة (3 صفحات) ثم المحادثة الأولى
        $this->drain(0, 4);
        $this->assertSame(1, HistoryImport::present($ch->refresh())['chats_done']);

        $this->actingAs($this->staffWith('channels_admin', ['whatsapp.channels']))
            ->postJson("/api/whatsapp/channels/{$ch->id}/import_stop")->assertOk()->assertJsonPath('channel.history.status', 'stopped');
        // الحلقة المنتظرة من التشغيل الموقوف لا تعمل شيئاً
        $this->drain(4);
        $this->assertSame(1, HistoryImport::present($ch->refresh())['chats_done']);

        $this->postJson("/api/whatsapp/channels/{$ch->id}/import_start")->assertOk();
        $this->drain(Queue::pushed(ImportChannelHistory::class)->count() - 1);

        $state = HistoryImport::present($ch->refresh());
        $this->assertSame(['done', 3, 4], [$state['status'], $state['chats_done'], $state['messages']]);
        $this->assertSame(1, $this->historyCalls(self::A), 'الاستئناف لا يعيد ما استُورد');
        $actions = AuditLog::where('action', 'wa.channel_change')->where('entity_id', $ch->id)->get()->pluck('changes.action')->all();
        $this->assertSame(['import_stop', 'import_start'], $actions);
    }

    public function test_import_routes_need_the_channels_permission(): void
    {
        $ch = $this->testChannel();
        $this->actingAs($this->staffWith('agent', self::INBOX_PERMISSIONS))
            ->postJson("/api/whatsapp/channels/{$ch->id}/import_start")->assertForbidden();
        Queue::assertNotPushed(ImportChannelHistory::class);
        $this->assertNull($ch->refresh()->history_import_state);
    }

    public function test_a_live_message_after_the_import_reaches_the_bot(): void
    {
        HistoryImport::start($this->testChannel(), 'manual');
        $this->drain();
        Queue::assertNotPushed(ProcessWhatsAppReply::class);

        $this->postOpenWa('message.received', $this->incoming('962791110001', 'هل ما زال العرض متاحاً؟'))->assertOk();

        Queue::assertPushed(ProcessWhatsAppReply::class);
        $a = $this->convFor(self::A);
        $this->assertSame(['open', 1], [$a->status, (int) $a->unread_count], 'الوارد الحي يعيد فتح المحادثة المستوردة');
        $this->assertSame(4, WaMessage::where('conversation_id', $a->id)->count());
    }

    public function test_a_chat_that_already_has_messages_only_takes_newer_history(): void
    {
        // وصل العميل A حياً قبل أن يصل دوره في الاستيراد: سجله الأقدم لا يُدرج بعد رسالته الحية
        $this->postOpenWa('message.received', $this->incoming('962791110001', 'رسالة حية'))->assertOk();
        HistoryImport::start($this->testChannel(), 'manual');
        $this->drain();

        $a = $this->convFor(self::A);
        $this->assertSame(['رسالة حية'], WaMessage::where('conversation_id', $a->id)->pluck('body')->all());
        $this->assertSame(1, HistoryImport::present($this->testChannel())['chats_partial']);
        $this->assertSame('open', $a->status, 'محادثة موجودة لا يغلقها الاستيراد');
    }

    public function test_first_link_starts_the_import_and_a_reconnect_starts_the_backfill(): void
    {
        $ch = $this->testChannel();
        $ch->forceFill(['status' => 'authenticating'])->save();
        ChannelState::applyStatus($ch, 'ready', ['phone' => self::TEST_CHANNEL_PHONE]);
        Queue::assertPushed(ImportChannelHistory::class, 1);
        Queue::assertNotPushed(BackfillChannel::class);

        $done = $this->makeChannel(['status' => 'disconnected', 'last_inbound_at' => now()->subHour(), 'history_imported_at' => now()->subDay(),
            'history_import_state' => ['status' => 'done']]);
        ChannelState::applyStatus($done, 'ready', ['phone' => $done->phone]);
        Queue::assertPushed(BackfillChannel::class, 1);
        Queue::assertPushed(ImportChannelHistory::class, 1);
    }

    public function test_the_gap_backfill_never_acts_as_a_first_import(): void
    {
        $ch = $this->testChannel();
        (new BackfillChannel($ch->id))->handle(app(\App\Services\WhatsApp\Transport\ChannelTransport::class));

        Queue::assertPushed(ImportChannelHistory::class, 1);
        $this->assertFalse(collect(Http::recorded())->contains(fn ($p) => str_contains($p[0]->url(), '/history')), 'لا سجل من مسار الاسترجاع');
        $this->assertSame('running', HistoryImport::present($ch->refresh())['status']);
    }

    public function test_a_dead_chain_is_resumed_by_the_channel_watch(): void
    {
        $ch = $this->testChannel();
        HistoryImport::start($ch, 'manual');
        $state = $ch->refresh()->history_import_state;
        $ch->forceFill(['history_import_state' => ['heartbeat_at' => now()->subMinutes(10)->toIso8601String()] + $state])->save();

        $this->assertSame(1, HistoryImport::recoverStale());
        Queue::assertPushed(ImportChannelHistory::class, 2);
        $this->drain(1);
        $this->assertSame('done', HistoryImport::present($ch->refresh())['status']);
    }

    public function test_an_old_phone_echo_from_the_backfill_does_not_pause_the_bot_now(): void
    {
        $ch = $this->testChannel();
        $this->postOpenWa('message.received', $this->incoming('962791110009', 'مرحبا'))->assertOk();
        $conv = WaConversation::where('jid', '962791110009@c.us')->sole();

        $old = $this->msg('962791110009@c.us', 'e1', 'رد من الهاتف قبل ساعات', now()->subHours(3)->getTimestamp(), fromMe: true);
        EchoHandler::handleOpenWa($ch, $old, null, retried: true, historical: true);
        $conv->refresh();
        $this->assertTrue($conv->bot_paused_until === null || $conv->bot_paused_until->isPast());
        $this->assertSame(2, WaMessage::where('conversation_id', $conv->id)->count());

        // صدى حي من الهاتف ما زال يوقف البوت
        $live = $this->msg('962791110009@c.us', 'e2', 'رد الآن', now()->getTimestamp(), fromMe: true);
        EchoHandler::handleOpenWa($ch, $live, null, retried: true);
        $this->assertTrue($conv->refresh()->bot_paused_until->isFuture());
    }

    public function test_a_lid_chat_gets_its_phone_from_the_gateway_and_links_to_the_client(): void
    {
        $client = \App\Models\Client::factory()->create(['phone' => '0791110077']);
        $this->chats = [['id' => self::LID, 'name' => '+962 7 9111 0077', 'isGroup' => false, 'timestamp' => now()->getTimestamp()]];
        $this->lidPhones = [self::LID => '962791110077'];
        $this->history = [self::LID => [
            $this->msg(self::LID, 'l1', 'مرحبا، بدي عرض عمرة', now()->subDay()->getTimestamp(), extra: ['senderPhone' => null]),
        ]];

        HistoryImport::start($this->testChannel(), 'manual');
        $this->drain();

        $conv = WaConversation::where('lid', self::LID)->sole();
        $this->assertSame('962791110077', $conv->phone, 'جهة @lid تأخذ رقمها قبل أن تراسل');
        $this->assertSame($client->id, (int) $conv->client_id);
    }

    public function test_a_reimport_rebuilds_an_untouched_chat_with_its_older_messages_in_order(): void
    {
        // المزامنة الأولى لم تكتمل: البوابة أعادت آخر رسالة وحدها
        $full = $this->history[self::A];
        $this->history[self::A] = [$full[2]];
        $ch = $this->testChannel();
        HistoryImport::start($ch, 'manual');
        $this->drain();
        $a = $this->convFor(self::A);
        $this->assertSame(1, WaMessage::where('conversation_id', $a->id)->count());

        $this->history[self::A] = $full;
        HistoryImport::start($ch->refresh(), 'manual');
        $this->drain(Queue::pushed(ImportChannelHistory::class)->count() - 1);

        $rows = WaMessage::where('conversation_id', $a->id)->orderBy('id')->get();
        $this->assertSame(['A1', 'A2', 'A3'], $rows->map(fn ($r) => substr($r->wamid, -2))->all(), 'الخيط كاملاً بترتيبه');
        $this->assertSame(2, HistoryImport::present($ch->refresh())['messages'], 'العدّاد للجديد وحده');
        $this->assertSame(0, HistoryImport::present($ch)['chats_partial']);
        $this->assertSame($rows[2]->id, (int) $a->refresh()->bot_answered_in_id);
        $this->assertSame('closed', $a->status);
    }

    public function test_a_reimport_never_rebuilds_a_chat_with_live_activity(): void
    {
        $full = $this->history[self::A];
        $this->history[self::A] = [$full[2]];
        $ch = $this->testChannel();
        HistoryImport::start($ch, 'manual');
        $this->drain();
        $this->postOpenWa('message.received', $this->incoming('962791110001', 'رسالة حية'))->assertOk();

        $this->history[self::A] = $full;
        HistoryImport::start($ch->refresh(), 'manual');
        $this->drain(Queue::pushed(ImportChannelHistory::class)->count() - 1);

        $a = $this->convFor(self::A);
        $this->assertSame(2, WaMessage::where('conversation_id', $a->id)->count(), 'لا حذف لمحادثة فيها حي');
        $this->assertSame(1, HistoryImport::present($ch->refresh())['chats_partial']);
    }

    public function test_the_first_link_waits_for_the_phone_sync_and_is_not_taken_for_a_dead_chain(): void
    {
        config(['whatsapp.openwa.import.settle_seconds' => 600]);
        $ch = $this->testChannel();
        $ch->forceFill(['status' => 'authenticating'])->save();
        ChannelState::applyStatus($ch, 'ready', ['phone' => self::TEST_CHANNEL_PHONE]);

        Queue::assertPushed(ImportChannelHistory::class, fn ($job) => $job->delay !== null && now()->diffInSeconds($job->delay) >= 590);
        $this->assertNotNull(HistoryImport::present($ch->refresh())['starts_at']);

        // الرقم متصل طوال الانتظار (فحص حديث)، فالفرق موعد البدء وحده
        $this->travel(8)->minutes();
        $ch->forceFill(['last_probe_ok_at' => now()])->save();
        $this->assertSame(0, HistoryImport::recoverStale(), 'المؤجل قبل موعده ليس ميتاً');
        $this->travel(10)->minutes();
        $ch->forceFill(['last_probe_ok_at' => now()])->save();
        $this->assertSame(1, HistoryImport::recoverStale(), 'بعد موعده بلا نبض: يُستأنف');
    }

    public function test_groups_are_imported_with_their_name_and_authors(): void
    {
        $g = '120363099999999999@g.us';
        $t = now()->getTimestamp();
        $this->chats = [['id' => $g, 'name' => 'معتمرو تشرين', 'isGroup' => true, 'timestamp' => $t]];
        $this->history = [$g => [
            $this->msg($g, 'g1', 'السلام عليكم', $t - 3600, extra: ['author' => '962791110055@c.us', 'senderPhone' => '962791110055', 'contact' => ['pushName' => 'أبو ماهر']]),
            $this->msg($g, 'g2', 'وعليكم السلام', $t - 3000, fromMe: true),
        ]];

        HistoryImport::start($this->testChannel(), 'manual');
        $this->drain();

        $conv = WaConversation::where('jid', $g)->sole();
        $this->assertSame([true, 'معتمرو تشرين', false, null], [$conv->isGroup(), $conv->display_name, (bool) $conv->bot_enabled, $conv->phone]);
        $rows = WaMessage::where('conversation_id', $conv->id)->orderBy('id')->get();
        $this->assertSame(['in', 'out'], $rows->pluck('direction')->all());
        $this->assertSame('أبو ماهر', data_get($rows[0]->payload, '_author.name'));
        $this->assertSame(0, WaConversation::where('phone', '962791110055')->count(), 'كاتب الرسالة ليس محادثة فردية');
    }
}

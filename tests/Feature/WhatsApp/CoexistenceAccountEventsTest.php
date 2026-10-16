<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaHistoryChunk;
use App\Models\WaMessage;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\HistoryHold;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * أحداث الحساب في التعايش (E39): PARTNER_REMOVED تنبيه وشارة بلا قفل، وhistory وsmb_app_state_sync بالأعداد وحدها
 * (لا رسالة ولا اسم)، والسجل السابق يُحفظ مشفّراً بانتظار القرار مع العلم coexistence_history_hold ثم يُمحى أو يُهمل،
 * ويشمله المحو بطلب العميل.
 */
class CoexistenceAccountEventsTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private const OUR_ACCOUNT = '102290129340398';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.coexistence_guard' => true, 'services.whatsapp.waba_id' => self::OUR_ACCOUNT]);
        $this->configureChannel()->forceFill(['wa_display_phone' => '15550783881'])->save();
        $this->admin = User::factory()->admin()->create();
    }

    private function titles(): array
    {
        return Notification::where('user_id', $this->admin->id)->pluck('title')->all();
    }

    public function test_partner_removed_warns_and_badges_the_channel_without_locking(): void
    {
        config(['features.env.channel_health' => true]);

        $this->postMetaWebhook('account_update_partner_removed.json')->assertOk();

        $e = WaAccountEvent::where('field', 'account_update')->sole();
        $this->assertSame(['PARTNER_REMOVED', 'warn', 'alert'], [$e->event, $e->severity, $e->effect]);
        $this->assertNotNull($e->handled_at);
        $this->assertContains('📱 انفصل ربط التعايش', $this->titles());
        $body = Notification::where('title', '📱 انفصل ربط التعايش')->value('body');
        $this->assertStringContainsString('خمول الهاتف الأساسي نحو 14 يوماً', $body);
        $s = WaBotSetting::current()->fresh();
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertFalse(ChannelHealth::blocksSending($s));
        $this->assertSame(['removed', 'PRIMARY_INACTIVITY'], [ChannelHealth::panel()['meta']['coexistence']['state'], ChannelHealth::panel()['meta']['coexistence']['reason']]);
    }

    public function test_the_legacy_path_alerts_without_suspending(): void
    {
        $this->postMetaWebhook('account_update_partner_removed.json')->assertOk();

        $this->assertContains('📱 انفصل ربط التعايش', $this->titles());
        $this->assertFalse((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_without_coexistence_flags_the_legacy_path_stays_silent(): void
    {
        config(['features.env.coexistence_guard' => false]);

        $this->postMetaWebhook('account_update_partner_removed.json')->assertOk();

        $this->assertNotContains('📱 انفصل ربط التعايش', $this->titles());
        $this->assertFalse((bool) WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_history_is_recorded_by_counts_only_and_creates_no_message(): void
    {
        $this->postMetaWebhook('history_batch.json')->assertOk();
        $this->postMetaWebhook('history_batch.json')->assertOk();

        $e = WaAccountEvent::where('field', 'history')->sole();
        $this->assertSame(['HISTORY_SYNC', 'info', 'alert'], [$e->event, $e->severity, $e->effect]);
        $this->assertSame(['phase' => 0, 'chunk_order' => 1, 'progress' => 100, 'threads' => 2, 'messages' => 3, 'error_codes' => [], 'held' => false], $e->payload);
        $this->assertStringNotContainsString('Old question', json_encode($e->payload));
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, WaConversation::count());
        $this->assertSame(0, WaHistoryChunk::count());
        $this->assertSame(1, Notification::where('title', '📚 وصل سجل المحادثات السابق من تطبيق الأعمال')->count());
        $this->assertStringContainsString('لم تُستورد ولم تُحفظ', Notification::where('title', 'like', '📚%')->value('body'));
    }

    public function test_contact_sync_is_counted_and_never_renames_anyone(): void
    {
        $conv = WhatsAppInbox::conversationFor('16505551234', 'الاسم الأصلي');

        $this->postMetaWebhook('smb_app_state_sync.json')->assertOk();

        $e = WaAccountEvent::where('field', 'smb_app_state_sync')->sole();
        $this->assertSame(['STATE_SYNC', 'none'], [$e->event, $e->effect]);
        $this->assertSame(['total' => 2, 'actions' => ['add' => 1, 'remove' => 1], 'types' => ['contact' => 2]], $e->payload);
        $this->assertStringNotContainsString('Sheena', json_encode($e->payload));
        $conv->refresh();
        $this->assertSame('الاسم الأصلي', $conv->wa_profile_name);
        $this->assertNull($conv->display_name);
    }

    public function test_history_is_held_encrypted_until_its_deadline_when_the_owner_opts_in(): void
    {
        config(['features.env.coexistence_history_hold' => true]);

        $this->postMetaWebhook('history_batch.json')->assertOk();

        $chunk = WaHistoryChunk::sole();
        $this->assertSame([WaHistoryChunk::HELD, 2, 3, 1], [$chunk->status, (int) $chunk->threads, (int) $chunk->messages, (int) $chunk->chunk_order]);
        $raw = (string) DB::table('wa_history_chunks')->value('payload');
        $this->assertStringNotContainsString('Old question', $raw, 'مشفّر على القرص');
        $this->assertSame('Old question from the customer.', $chunk->payload['history'][0]['threads'][0]['messages'][0]['text']['body']);
        $this->assertEqualsWithDelta(30, now()->diffInDays($chunk->purge_after), 1);
        $this->assertTrue(WaAccountEvent::where('field', 'history')->sole()->payload['held']);
        $this->assertStringContainsString('حُفظت مشفّرة بانتظار قرار الاستيراد', Notification::where('title', 'like', '📚%')->value('body'));
        $this->assertSame(0, WaMessage::count());

        $summary = HistoryHold::summary();
        $this->assertSame([1, 2, 3], [$summary['held'], $summary['threads'], $summary['messages']]);
        $a = HistoryHold::analyze('15550783881');
        $this->assertSame([1, 2, 3, 1, 2, 0], [$a['chunks'], $a['threads'], $a['messages'], $a['from_business'], $a['from_customers'], $a['existing_threads']]);
        $this->assertSame(['text' => 2, 'image' => 1], $a['types']);

        // بعد المدة يُمحى كل ساعة
        $this->travel(31)->days();
        $this->artisan('whatsapp:coexistence-history --prune')->assertSuccessful();
        $chunk->refresh();
        $this->assertSame(WaHistoryChunk::EXPIRED, $chunk->status);
        $this->assertNull(DB::table('wa_history_chunks')->value('payload'));
    }

    public function test_discarding_the_held_history_is_audited_and_irreversible(): void
    {
        config(['features.env.coexistence_history_hold' => true, 'audit.extended' => false]);
        $this->postMetaWebhook('history_batch.json')->assertOk();

        $this->artisan('whatsapp:coexistence-history --discard --force')->assertSuccessful();

        $this->assertSame(WaHistoryChunk::DISCARDED, WaHistoryChunk::sole()->status);
        $this->assertNull(DB::table('wa_history_chunks')->value('payload'));
        $log = AuditLog::where('action', AuditAction::WaHistoryDiscard->value)->sole();
        $this->assertSame(['chunks' => 1, 'messages' => 3, 'threads' => 2], $log->changes);
    }

    public function test_forgetting_a_customer_removes_their_threads_from_the_held_history(): void
    {
        config(['features.env.coexistence_history_hold' => true]);
        $this->postMetaWebhook('history_batch.json')->assertOk();
        $conv = WhatsAppInbox::conversationFor('16505551234');

        $eraser = app(ConversationEraser::class);
        $plan = $eraser->plan($conv);
        $this->assertSame(1, $plan->counts['history_threads']);
        $this->assertSame(2, WaHistoryChunk::sole()->threads, 'الخطة لا تكتب');

        $done = $eraser->execute($plan);

        $this->assertSame(1, $done['history_threads']);
        $chunk = WaHistoryChunk::sole();
        $this->assertSame([1, 1], [(int) $chunk->threads, (int) $chunk->messages]);
        $this->assertStringNotContainsString('16505551234', json_encode($chunk->payload));
        $this->assertSame('16505553333', $chunk->payload['history'][0]['threads'][0]['id']);
    }

    public function test_a_first_message_hidden_by_coexistence_tells_the_staff_where_to_read_it(): void
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('unsupported.json')), true);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['errors'][0]['code'] = 131060;

        $this->postRawWebhook((string) json_encode($payload))->assertOk();

        $this->assertStringContainsString('افتح المحادثة في تطبيق الأعمال', WaMessage::sole()->body);
    }

    public function test_without_any_coexistence_flag_history_keeps_the_e20_alert(): void
    {
        config(['features.env.coexistence_guard' => false]);

        $this->postMetaWebhook('history_batch.json')->assertOk();

        $this->assertSame(0, WaAccountEvent::count());
        $this->assertContains('⚠️ وصلت أحداث تعايش على رقم الإنتاج', $this->titles());
    }
}

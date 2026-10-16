<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Jobs\RefreshChannelHealth;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * أحداث حساب ميتا مع صحة القناة (E22): القفل لثلاث حالات قاطعة فقط، وREINSTATE يقترح الرفع ولا يرفع، وقيود رسائل
 * الشركة LIMITED لا تسكت الردود، وحدث حساب أو رقم آخر يُتجاهل، والمصدر المجهول لا يقفل. لا يُكتب wa_suspended أبداً.
 */
class AccountEventHandlerTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const OUR_ACCOUNT = '102290129340398';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.channel_health' => true, 'services.whatsapp.waba_id' => self::OUR_ACCOUNT, 'audit.extended' => false]);
        $this->configureChannel()->forceFill(['wa_display_phone' => '15550783881'])->save();
        $this->admin = User::factory()->admin()->create();
    }

    private function settings(): WaBotSetting
    {
        return WaBotSetting::current()->fresh();
    }

    private function titles(): array
    {
        return Notification::where('user_id', $this->admin->id)->pluck('title')->all();
    }

    private function event(string $field): WaAccountEvent
    {
        return WaAccountEvent::where('field', $field)->sole();
    }

    public function test_a_ban_blocks_sending_without_touching_the_manual_suspension(): void
    {
        $this->postMetaWebhook('account_update_ban_disable.json')->assertOk();

        $s = $this->settings();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertSame('webhook', $s->wa_health_source);
        $this->assertStringStartsWith('ban:DISABLE', $s->wa_health_reason);
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertSame(['critical', 'blocked'], [$this->event('account_update')->severity, $this->event('account_update')->effect]);
        $this->assertNotNull($this->event('account_update')->handled_at);
        $this->assertSame(['⛔ ميتا حجبت الإرسال على رقم الشركة'], $this->titles());
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaHealthChange->value)->count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::WaSuspendAuto->value)->count());
        Queue::assertPushed(RefreshChannelHealth::class);
    }

    public function test_reinstate_on_a_blocked_channel_suggests_unblocking_and_never_lifts(): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => 'BLOCKED', 'wa_health_source' => 'webhook'])->save();

        $this->postMetaWebhook('account_update_ban_reinstate.json')->assertOk();

        $s = $this->settings();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertTrue((bool) $s->wa_health_unblock_suggested);
        $this->assertFalse((bool) $s->wa_suspended);
        $this->assertSame(['✅ ميتا تشير إلى رفع القيد — راجع ثم ارفع القفل يدوياً'], $this->titles());
        $this->assertSame('unblock_suggested', $this->event('account_update')->effect);
    }

    public function test_reinstate_on_a_working_channel_locks_nothing(): void
    {
        $this->postMetaWebhook('account_update_ban_reinstate.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertFalse((bool) $this->settings()->wa_suspended);
        $this->assertSame([], $this->titles());
    }

    public function test_a_business_initiated_restriction_is_limited_and_customer_replies_still_go_out(): void
    {
        $this->postMetaWebhook('account_update_restriction_biz.json')->assertOk();

        $s = $this->settings();
        $this->assertSame('LIMITED', $s->wa_health);
        $this->assertSame(1761035000, $s->wa_health_until->getTimestamp());
        $this->assertSame(['RESTRICTED_BIZ_INITIATED_MESSAGING', 'RESTRICTED_ADD_PHONE_NUMBER_ACTION'], $s->wa_health_meta['restriction_types']);
        $this->assertSame(['⚠️ قيد جزئي من ميتا — ردود العملاء تعمل'], $this->titles());

        $this->fakeGraph();
        $conv = WaConversation::factory()->windowOpen()->create();
        $res = WhatsAppChannel::sendText($conv, 'أهلاً، كيف أخدمك؟', 'bot', sendKey: SendKey::optout('wamid.E22.LIMITED'));

        $this->assertTrue($res['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_customer_initiated_restriction_blocks_until_its_expiry_without_any_http_call(): void
    {
        $this->postMetaWebhook('account_update_restriction_customer.json')->assertOk();

        $s = $this->settings();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertSame(1761035000, $s->wa_health_until->getTimestamp());
        $this->assertFalse((bool) $s->wa_suspended);

        Http::fake();
        $conv = WaConversation::factory()->windowOpen()->create();
        $res = WhatsAppChannel::sendText($conv, 'مرحباً', 'staff', User::factory()->admin()->create()->id);

        $this->assertFalse($res['ok']);
        $this->assertSame(WhatsAppChannel::ERR_CHANNEL_BLOCKED, $res['code']);
        Http::assertNothingSent();
    }

    public function test_events_of_another_account_or_number_are_ignored_and_counted(): void
    {
        $this->postMetaWebhook('account_update_foreign_waba.json')->assertOk();
        $this->postMetaWebhook('quality_update_other_number.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertSame([], $this->titles());
        $rows = WaAccountEvent::orderBy('id')->get();
        $this->assertSame(['ignored_foreign', 'ignored_foreign'], $rows->pluck('effect')->all());
        $this->assertNull($rows[0]->payload, 'بيانات حساب آخر لا تُخزَّن');
        $this->assertSame(2, ChannelHealth::panel()['foreign_today']);
        Queue::assertNothingPushed();
    }

    public function test_an_event_from_an_unverified_source_never_locks_but_alerts_and_polls(): void
    {
        config(['services.whatsapp.waba_id' => '']);
        WaBotSetting::current()->forceFill(['wa_waba_id' => null, 'wa_waba_id_source' => null, 'wa_display_phone' => null])->save();

        $this->postMetaWebhook('account_update_ban_disable.json')->assertOk();
        $this->postMetaWebhook('account_update_violation.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertFalse((bool) $this->settings()->wa_suspended);
        $this->assertContains('⚠️ حدث حساب من ميتا لم يُتحقق من مصدره', $this->titles());
        $this->assertContains('⚠️ مخالفة سياسات على حساب الواتساب', $this->titles());
        $this->assertSame(['poll', 'poll'], WaAccountEvent::orderBy('id')->pluck('effect')->all());
        Queue::assertPushed(RefreshChannelHealth::class);
    }

    public function test_a_violation_alerts_and_polls_without_locking(): void
    {
        $this->postMetaWebhook('account_update_violation.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertSame('SCAM', $this->settings()->wa_health_meta['violation_type']);
        $this->assertSame(['⚠️ مخالفة سياسات على حساب الواتساب'], $this->titles());
        Queue::assertPushed(RefreshChannelHealth::class);
    }

    public function test_a_pin_reset_request_is_one_critical_alert_with_an_audit_row_and_no_requester(): void
    {
        $this->postMetaWebhook('security_pin_reset.json')->assertOk();

        $n = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertSame('🔐 طلب إعادة ضبط PIN لرقم الشركة', $n->title);
        $this->assertSame('error', $n->type);
        $this->assertStringNotContainsString('1234567890', $n->title.$n->body);
        $row = AuditLog::where('action', AuditAction::WaAccountEventCritical->value)->sole();
        $this->assertSame(['meta', 'security', 'error'], [$row->actor_type, $row->actor_ref, $row->severity]);
        $this->assertStringNotContainsString('1234567890', json_encode($row->changes));
        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
    }

    public function test_a_critical_account_alert_notifies_without_changing_health(): void
    {
        $raw = str_replace(['"INFORMATIONAL"', '1760430700'], ['"CRITICAL"', '1760430701'], file_get_contents($this->fixturePath('account_alerts_info.json')));
        $this->postRawWebhook($raw)->assertOk();
        $this->postMetaWebhook('account_alerts_info.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertSame(['⛔ تنبيه حرج من ميتا على الحساب'], $this->titles(), 'المعلوماتي بلا تنبيه');
        $this->assertSame(['critical', 'info'], WaAccountEvent::orderBy('id')->pluck('severity')->all());
    }

    public function test_old_quality_events_poll_and_never_lock_and_new_ones_are_information(): void
    {
        $flagged = str_replace(['THROUGHPUT_UPGRADE', '1760431000'], ['FLAGGED', '1760431001'], file_get_contents($this->fixturePath('quality_update_throughput.json')));
        $this->postRawWebhook($flagged)->assertOk();
        $this->postMetaWebhook('quality_update_throughput.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertFalse((bool) $this->settings()->wa_suspended);
        $this->assertSame([['warn', 'poll'], ['info', 'none']], WaAccountEvent::orderBy('id')->get()->map(fn ($e) => [$e->severity, $e->effect])->all());
        Queue::assertPushed(RefreshChannelHealth::class, 1);
    }

    public function test_capability_and_name_updates_are_recorded_with_the_tier_and_a_rejection_warns(): void
    {
        $this->postMetaWebhook('business_capability_update.json')->assertOk();
        $this->postMetaWebhook('name_update_rejected.json')->assertOk();

        $this->assertSame('TIER_1K', $this->settings()->wa_health_meta['tier']);
        $this->assertSame(['⚠️ ميتا رفضت الاسم المعروض للرقم'], $this->titles());
        $this->assertSame(['CAPABILITY', 'REJECTED'], WaAccountEvent::orderBy('id')->pluck('event')->all());
    }

    public function test_schedule_for_disable_and_offboarding_alert_and_poll_only(): void
    {
        $this->postMetaWebhook('account_update_ban_schedule.json')->assertOk();
        $this->postMetaWebhook('account_update_offboarded.json')->assertOk();

        $this->assertSame('UNKNOWN', $this->settings()->wa_health);
        $this->assertSame(['⛔ ميتا جدولت تعطيل حساب الواتساب', '⚠️ فُصل حساب واتساب للأعمال'], $this->titles());
        $this->assertStringContainsString('2026-10-21', Notification::where('title', '⛔ ميتا جدولت تعطيل حساب الواتساب')->value('body'));
    }

    public function test_the_stored_payload_is_the_raw_value_without_secrets(): void
    {
        $this->postMetaWebhook('account_update_violation.json')->assertOk();

        $this->assertSame(['event' => 'ACCOUNT_VIOLATION', 'violation_info' => ['violation_type' => 'SCAM']], $this->event('account_update')->payload);
        $this->assertSame(self::OUR_ACCOUNT, $this->event('account_update')->entry_id);
        $this->assertSame(1760430400, $this->event('account_update')->occurred_at->getTimestamp());
    }

    public function test_with_the_flag_off_the_old_suspension_path_is_unchanged(): void
    {
        config(['features.env.channel_health' => false]);

        $this->postMetaWebhook('account_update_ban_disable.json')->assertOk();

        $s = $this->settings();
        $this->assertTrue((bool) $s->wa_suspended);
        $this->assertSame('meta_event', $s->wa_suspended_source);
        $this->assertSame('UNKNOWN', $s->wa_health);
        $this->assertSame(0, WaAccountEvent::count());
    }
}

<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * أحداث حساب ميتا صفوف دائمة بفاعل meta (E09.9)، والإيقاف التلقائي صف خطأ دائماً،
 * والتوقيع المرفوض وفشل الربط بالعيّنات بلا جسم ولا رمز.
 */
class MetaAccountAuditTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const OUR_ACCOUNT = '102290129340398';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['audit.extended' => true]);
    }

    private function change(string $field, array $value): string
    {
        return json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => self::OUR_ACCOUNT, 'time' => 1760426040, 'changes' => [['field' => $field, 'value' => $value]],
        ]]]);
    }

    private function rows(AuditAction $action)
    {
        return AuditLog::where('action', $action->value)->orderBy('id')->get();
    }

    public function test_a_ban_suspends_sending_and_leaves_an_account_row_and_an_auto_suspend_row(): void
    {
        $this->postMetaWebhook('account_update_ban.json')->assertOk();

        $this->assertTrue((bool) WaBotSetting::current()->wa_suspended);
        $account = $this->rows(AuditAction::WaAccountUpdate)->sole();
        $this->assertSame(['meta', 'account_update', 'error', 'DISABLED_UPDATE'], [$account->actor_type, $account->actor_ref, $account->severity, $account->entity_label]);
        $this->assertSame('DISABLE', $account->changes['ban_info']['waba_ban_state']);

        $suspend = $this->rows(AuditAction::WaSuspendAuto)->sole();
        $this->assertSame(['meta', 'error'], [$suspend->actor_type, $suspend->severity]);
        $this->assertNull($suspend->user_id);

        // حدث مكرر بعد القفل: صف حساب ثانٍ، ولا صف إيقاف ثانٍ
        $this->postMetaWebhook('account_update_ban.json')->assertOk();
        $this->assertCount(2, $this->rows(AuditAction::WaAccountUpdate));
        $this->assertCount(1, $this->rows(AuditAction::WaSuspendAuto));
    }

    public function test_the_auto_suspend_row_is_written_even_when_extended_audit_is_off(): void
    {
        config(['audit.extended' => false]);

        $this->postMetaWebhook('account_update_ban.json')->assertOk();

        $this->assertCount(0, $this->rows(AuditAction::WaAccountUpdate));
        $this->assertCount(1, $this->rows(AuditAction::WaSuspendAuto));
    }

    public function test_alerts_map_their_severity_and_benign_events_do_not_suspend(): void
    {
        $this->postRawWebhook($this->change('account_alerts', [
            'entity_type' => 'WABA', 'entity_id' => self::OUR_ACCOUNT, 'alert_severity' => 'CRITICAL',
            'alert_status' => 'ACTIVE', 'alert_type' => 'OBA_APPROVED', 'alert_description' => 'x',
        ]))->assertOk();
        $this->postRawWebhook($this->change('account_alerts', ['alert_severity' => 'WARNING', 'alert_type' => 'INCREASED_CAPABILITIES_ELIGIBILITY']))->assertOk();
        $this->postRawWebhook($this->change('security', ['display_phone_number' => '15550783881', 'event' => 'PIN_CHANGED', 'requester' => '123']))->assertOk();
        $this->postRawWebhook($this->change('account_review_update', ['decision' => 'REJECTED']))->assertOk();
        $this->postRawWebhook($this->change('message_template_status_update', ['event' => 'APPROVED', 'message_template_id' => 1]))->assertOk();

        $this->assertSame(['error', 'warn'], $this->rows(AuditAction::WaAccountAlert)->pluck('severity')->all());
        $this->assertSame('OBA_APPROVED', $this->rows(AuditAction::WaAccountAlert)->first()->changes['alert_type']);
        $this->assertSame('warn', $this->rows(AuditAction::WaSecurity)->sole()->severity);
        $this->assertSame('error', $this->rows(AuditAction::WaReviewUpdate)->sole()->severity);
        $this->assertSame('info', $this->rows(AuditAction::WaWebhookOther)->sole()->severity);
        $this->assertFalse((bool) WaBotSetting::current()->wa_suspended);
        $this->assertCount(0, $this->rows(AuditAction::WaSuspendAuto));
    }

    public function test_quality_updates_read_the_new_limit_field_and_flagging_is_an_error(): void
    {
        $this->postRawWebhook($this->change('phone_number_quality_update', [
            'display_phone_number' => '15550783881', 'event' => 'UPGRADE', 'max_daily_conversations_per_business' => 'TIER_10K',
        ]))->assertOk();
        $this->postRawWebhook($this->change('phone_number_quality_update', ['display_phone_number' => '15550783881', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']))->assertOk();

        $rows = $this->rows(AuditAction::WaQualityUpdate);
        $this->assertSame(['TIER_10K', 'TIER_1K'], $rows->map(fn ($r) => $r->changes['limit'])->all());
        $this->assertSame(['info', 'error'], $rows->pluck('severity')->all());
    }

    public function test_customer_preferences_are_stored_as_counts_only(): void
    {
        $this->postRawWebhook($this->change('user_preferences', [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => '16505551234', 'profile' => ['name' => 'Test']]],
            'user_preferences' => [
                ['wa_id' => '16505551234', 'category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => 1760426040],
                ['wa_id' => '16505553333', 'category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => 1760426041],
            ],
        ]))->assertOk();

        $row = $this->rows(AuditAction::WaWebhookOther)->sole();
        $this->assertSame(['count' => 2, 'by_value' => ['stop' => 2]], $row->changes);
        $this->assertStringNotContainsString('16505551234', json_encode($row->toArray()));
    }

    public function test_events_of_another_business_account_leave_no_row(): void
    {
        $this->postMetaWebhook('text.json')->assertOk();   // نتعلّم حسابنا
        $foreign = str_replace('"'.self::OUR_ACCOUNT.'"', '"999999999999999"', $this->change('account_alerts', ['alert_severity' => 'CRITICAL']));

        $this->postRawWebhook($foreign)->assertOk();

        $this->assertCount(0, $this->rows(AuditAction::WaAccountAlert));
    }

    public function test_bad_signatures_and_failed_verification_are_sampled_without_body_or_token(): void
    {
        $body = $this->change('account_update', ['event' => 'SECRETBODY']);
        foreach (range(1, 3) as $i) {
            $this->postRawWebhook($body, 'wrong-secret')->assertForbidden();
        }
        $this->getJson('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=WRONGTOKEN123&hub_challenge=abc')->assertForbidden();

        $sig = $this->rows(AuditAction::WaWebhookBadSignature);
        $this->assertGreaterThanOrEqual(1, $sig->count());
        $this->assertLessThan(3 + 1, $sig->count());
        $this->assertTrue($sig->first()->changes['has_header']);
        $this->assertSame(strlen($body), $sig->first()->changes['bytes']);
        $verify = $this->rows(AuditAction::WaWebhookVerifyFailed)->sole();
        $this->assertTrue($verify->changes['verify_param_sent']);

        $dump = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString('SECRETBODY', $dump);
        $this->assertStringNotContainsString('WRONGTOKEN123', $dump);
        $this->assertCount(0, $this->rows(AuditAction::AccessDenied), 'رد 403 للويبهوك ليس رفض وصول');
    }
}

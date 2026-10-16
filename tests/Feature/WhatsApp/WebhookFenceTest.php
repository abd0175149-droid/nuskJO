<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\WebhookFence;
use App\Services\WhatsApp\WebhookFields;
use App\Services\WhatsApp\WebhookStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * السياج (E20.4) والحقول المجهولة (E20.10): حساب أعمال آخر أو رقم آخر أو بلا رقم. التسجيل يعدّ فقط،
 * والفرض يُسقط بلا تخزين محتوى وينبّه مرة في اليوم. والحقل المجهول يُسجَّل باسمه وعدده بلا قيمته.
 */
class WebhookFenceTest extends TestCase
{
    use CapturesLogs, ConfiguresWhatsApp, PatchesWhatsAppSettings, RefreshDatabase, SignsMetaWebhooks;

    private const OUR_ACCOUNT = '102290129340398';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
        config(['features.env.inbound_event_log' => true, 'services.whatsapp.waba_id' => self::OUR_ACCOUNT]);
    }

    private function enforce(): void
    {
        config(['features.env.webhook_fence_enforce' => true]);
    }

    private function fenceAlerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->where('title', 'like', '%رُفضت حمولة%')->count();
    }

    public function test_log_mode_processes_a_foreign_account_and_counts_it(): void
    {
        $this->postMetaWebhook('foreign_waba.json')->assertOk();

        $this->assertSame(1, WaMessage::count(), 'التسجيل لا يُسقط ما لم تكن E04 تسقطه');
        $this->assertSame(['rejected' => 0, 'would' => 1], WebhookFence::counts()['waba_mismatch']);
        $this->assertSame(0, $this->fenceAlerts());
        $this->assertSame(WaWebhookEvent::PROCESSED, WaWebhookEvent::sole()->status);
    }

    public function test_enforce_mode_drops_a_foreign_account_keeps_no_body_and_alerts_once_a_day(): void
    {
        $this->enforce();

        $this->postMetaWebhook('foreign_waba.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $event = WaWebhookEvent::sole();
        $this->assertSame(WaWebhookEvent::REJECTED, $event->status);
        $this->assertSame('waba_mismatch', $event->reject_reason);
        $this->assertSame('messages:1', $event->field_summary);
        $this->assertNull($event->getRawOriginal('raw_body'));
        $this->assertSame(1, WebhookFence::counts()['waba_mismatch']['rejected']);
        $this->assertSame(1, $this->fenceAlerts());

        // حمولة أخرى مرفوضة اليوم نفسه (بعد ساعتين): عدّاد يزيد، ولا إشعار ثانٍ
        $this->travel(2)->hours();
        $payload = json_decode((string) file_get_contents($this->fixturePath('foreign_waba.json')), true);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid.FIXTURE.waba.2';
        $this->postRawWebhook(json_encode($payload))->assertOk();
        $this->assertSame(2, WebhookFence::counts()['waba_mismatch']['rejected']);
        $this->assertSame(1, $this->fenceAlerts());
    }

    public function test_enforce_mode_rejects_a_missing_or_foreign_phone_number_id(): void
    {
        $this->enforce();

        $this->postMetaWebhook('missing_pnid.json')->assertOk();
        $this->postMetaWebhook('foreign_phone_number_id.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $counts = WebhookFence::counts();
        $this->assertSame(1, $counts['pnid_missing']['rejected']);
        $this->assertSame(1, $counts['pnid_mismatch']['rejected']);
        $this->assertSame(2, WaWebhookEvent::where('status', WaWebhookEvent::REJECTED)->whereNull('raw_body')->count());
    }

    public function test_enforce_mode_refuses_messages_before_our_number_is_set(): void
    {
        $this->enforce();
        $this->configureChannel(['wa_phone_number_id' => null]);
        config(['services.whatsapp.phone_number_id' => null]);

        $this->postMetaWebhook('text.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(1, WebhookFence::counts()['channel_unconfigured']['rejected']);
    }

    public function test_a_quality_warning_for_another_number_does_not_suspend_sending(): void
    {
        $this->enforce();
        $this->postMetaWebhook('text.json')->assertOk();   // نتعلّم رقمنا المعروض

        $this->postMetaWebhook('quality_update_other_number.json')->assertOk();

        $this->assertFalse((bool) WaBotSetting::current()->wa_suspended);
    }

    public function test_calls_are_logged_by_type_and_count_only(): void
    {
        $this->captureLogs();

        $this->postMetaWebhook('calls_event.json')->assertOk();

        $this->assertSame(0, WaMessage::count());
        $this->assertSame(['calls' => 1], WebhookFields::counts());
        $line = collect($this->logged)->firstWhere('message', 'webhook.calls');
        $this->assertSame('connect', $line['context']['events'][0]['event']);
        $logged = json_encode(array_column($this->logged, 'context'));
        $this->assertStringNotContainsString('16505551234', $logged);
        $this->assertStringNotContainsString('sdp', $logged);
        $this->assertStringNotContainsString('wacid', $logged);
        $this->assertSame(WaWebhookEvent::PROCESSED, WaWebhookEvent::sole()->status);
    }

    public function test_coexistence_echoes_alert_and_never_reach_the_bot(): void
    {
        $this->postMetaWebhook('smb_message_echoes.json')->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', 'like', '%تعايش%')->count());
    }

    public function test_an_unknown_field_is_logged_without_its_value_and_does_not_drop_the_message(): void
    {
        $this->captureLogs();

        $this->postMetaWebhook('unknown_field.json')->assertOk();

        $this->assertSame(1, WaMessage::count());
        $this->assertSame(['brand_new_field' => 1], WebhookFields::counts());
        $this->assertLogged('warning', 'webhook.unknown_field', ['field' => 'brand_new_field', 'count' => 1]);
        $this->assertStringNotContainsString('never be logged', json_encode(array_column($this->logged, 'context')));
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('body', 'like', '%brand_new_field%')->count());
        $this->assertSame(WaWebhookEvent::PROCESSED, WaWebhookEvent::sole()->status);
        $this->assertSame(['brand_new_field' => 1], WebhookStats::summary(true)['unknown_fields']);
    }

    public function test_a_manual_account_id_is_sensitive_and_learning_never_overwrites_it(): void
    {
        config(['services.whatsapp.waba_id' => null]);
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->patchSettings($owner, ['wa_waba_id' => '123456789012345'])->assertJsonValidationErrors('confirm');
        $this->patchSettings($owner, ['wa_waba_id' => 'abc'], true)->assertJsonValidationErrors('changes.wa_waba_id');
        $this->patchSettings($owner, ['wa_waba_id' => '123456789012345'], true)->assertOk()->assertJsonPath('settings.wa_waba_id', '123456789012345');

        $s = WaBotSetting::current();
        $this->assertSame('manual', $s->wa_waba_id_source);
        $this->assertSame(['id' => '123456789012345', 'source' => 'manual'], WebhookFence::account());

        // رسالة لرقمنا من حساب آخر (entry.id): يُعرض مكتشفاً، ولا يكتب فوق اليدوي
        $this->postMetaWebhook('text.json')->assertOk();
        $this->assertSame('123456789012345', WaBotSetting::current()->wa_waba_id);
        $summary = WebhookStats::summary(true);
        $this->assertSame(self::OUR_ACCOUNT, $summary['fence']['detected']);
        $this->assertTrue($summary['fence']['mismatch']);
        $this->assertContains('waba_mismatch', $summary['warnings']);

        // مسحه يعيده للتعلّم
        $this->patchSettings($owner, ['wa_waba_id' => null], true)->assertOk();
        $this->assertNull(WaBotSetting::current()->wa_waba_id_source);
        $this->postRawWebhook(json_encode(json_decode((string) file_get_contents($this->fixturePath('text.json')), true)))->assertOk();
        $this->assertSame([self::OUR_ACCOUNT, 'learned'], [WaBotSetting::current()->wa_waba_id, WaBotSetting::current()->wa_waba_id_source]);
    }

    public function test_the_environment_account_id_wins_and_learning_does_not_enforce(): void
    {
        $this->assertSame(['id' => self::OUR_ACCOUNT, 'source' => 'env'], WebhookFence::account());

        config(['services.whatsapp.waba_id' => null]);
        $this->postMetaWebhook('text.json')->assertOk();
        $this->assertSame('learned', WaBotSetting::current()->wa_waba_id_source);
        $this->assertSame(['id' => null, 'source' => null], WebhookFence::account(), 'المتعلَّم لا يفرض رفضاً');
    }
}

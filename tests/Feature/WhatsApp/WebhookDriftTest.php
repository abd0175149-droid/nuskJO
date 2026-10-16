<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\MetaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\TestCase;

/**
 * انحراف الويبهوك (E23): رابط غير رابطنا، أو override على الرقم أو الحساب، أو حقل مطلوب غير مشترك. تنبيه واحد لكل
 * تغيّر فعلي في الانحراف (لا في كل دورة)، وتنبيه عودة واحد حين يزول. والمحفوظ مضيف الرابط وحده.
 */
class WebhookDriftTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesMetaReads;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.meta_checks' => true, 'app.url' => self::OUR_URL, 'features.env.channel_monitor' => true]);
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->admin = User::factory()->admin()->create();
    }

    private function titles(): array
    {
        return Notification::where('user_id', $this->admin->id)->orderBy('id')->pluck('title')->all();
    }

    private function webhook(): array
    {
        return (array) WaBotSetting::current()->fresh()->wa_channel_health['webhook'];
    }

    public function test_a_callback_pointing_elsewhere_alerts_once_and_keeps_the_host_only(): void
    {
        $this->fakeMetaReads(['app' => 'app_subscriptions_wrong_url']);

        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->artisan('whatsapp:health', ['--force' => true])->assertSuccessful();

        $this->assertSame(['⚠️ ويبهوك ميتا لا يشير إلى نُسك'], $this->titles());
        $w = $this->webhook();
        $this->assertFalse($w['ok']);
        $this->assertContains('callback_mismatch', $w['issues']);
        $this->assertSame(['host' => 'aibot-platform.example.com', 'ours' => false], $w['callback']);
        $this->assertStringNotContainsString('tenant=demo', json_encode(WaBotSetting::current()->fresh()->wa_channel_health));
        $body = Notification::where('user_id', $this->admin->id)->value('body');
        $this->assertStringContainsString('aibot-platform.example.com', $body);
    }

    public function test_a_missing_field_is_reported_by_name(): void
    {
        // الرابط الغريب في الملف نفسه؛ هنا يهم الحقل الناقص
        $this->fakeMetaReads(['app' => 'app_subscriptions_wrong_url']);

        $this->artisan('whatsapp:health');

        $this->assertSame(['security'], $this->webhook()['missing_fields']);
        $this->assertContains('missing:security', $this->webhook()['issues']);
        $this->assertStringContainsString('security', (string) Notification::where('user_id', $this->admin->id)->value('body'));
    }

    public function test_a_phone_number_override_is_reported(): void
    {
        $this->fakeMetaReads(['node' => 'phone_node_override']);

        $this->artisan('whatsapp:health');

        $w = $this->webhook();
        $this->assertSame(['override:phone_number'], $w['issues']);
        $this->assertSame([['level' => 'phone_number', 'host' => 'aibot-platform.example.com', 'ours' => false]], $w['overrides']);
        $this->assertSame(['⚠️ ويبهوك ميتا لا يشير إلى نُسك'], $this->titles());
    }

    public function test_a_waba_override_from_subscribed_apps_is_reported(): void
    {
        $this->fakeMetaReads(['waba' => 'subscribed_apps_override']);

        $this->artisan('whatsapp:health');

        $this->assertSame(['override:waba'], $this->webhook()['issues']);
    }

    public function test_a_single_missing_field_has_its_own_title(): void
    {
        $subs = $this->graphFixture('app_subscriptions_ok');
        $subs['data'][0]['fields'] = array_values(array_filter($subs['data'][0]['fields'], fn ($f) => $f['name'] !== 'user_preferences'));
        $this->fakeMetaReads(['app' => fn () => Http::response($subs)]);

        $this->artisan('whatsapp:health');

        $this->assertSame(['⚠️ حقل user_preferences غير مشترك في ويبهوك ميتا'], $this->titles());
    }

    public function test_the_drift_clearing_sends_one_recovery_notice(): void
    {
        $wrong = true;
        $this->fakeMetaReads(['app' => function () use (&$wrong) {
            return Http::response($this->graphFixture($wrong ? 'app_subscriptions_wrong_url' : 'app_subscriptions_ok'));
        }]);

        $this->artisan('whatsapp:health');
        $wrong = false;
        $this->artisan('whatsapp:health', ['--force' => true]);
        $this->artisan('whatsapp:health', ['--force' => true]);

        $this->assertSame(['⚠️ ويبهوك ميتا لا يشير إلى نُسك', '✅ ويبهوك ميتا يشير إلى نُسك'], $this->titles());
        $this->assertTrue($this->webhook()['ok']);
    }

    public function test_evaluation_without_any_reading_is_unknown(): void
    {
        $this->assertNull(MetaHealth::evaluateWebhookConfig(null, null)['ok']);
    }

    public function test_an_override_to_our_own_url_is_not_drift(): void
    {
        config(['app.url' => self::OUR_URL]);
        $node = ['phone_number' => MetaHealth::urlSummary(self::OUR_URL.'/api/whatsapp/webhook/'), 'waba' => null, 'application' => MetaHealth::urlSummary(self::OUR_URL.'/api/whatsapp/webhook')];

        $w = MetaHealth::evaluateWebhookConfig($node, null);

        $this->assertTrue($w['ok']);
        $this->assertTrue($w['overrides'][0]['ours']);
    }
}

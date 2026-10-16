<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\RefreshChannelHealth;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\AccountEventHandler;
use App\Services\WhatsApp\MetaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * البصمة (E22): إعادات ميتا للحمولة نفسها صف واحد وتنبيه واحد وفحص واحد. حدث مماثل حقيقي لاحقاً (وقت entry آخر)
 * حدث جديد. والأثر الذي فشل يبقى بلا handled_at ويعيده الفحص التالي.
 */
class AccountEventDedupeTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.env.channel_health' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel()->forceFill(['wa_display_phone' => '15550783881'])->save();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_same_payload_three_times_is_one_row_one_alert_and_one_poll(): void
    {
        $this->captureLogs();
        foreach (range(1, 3) as $i) {
            $this->postMetaWebhook('security_pin_reset.json')->assertOk();
            $this->postMetaWebhook('account_update_violation.json')->assertOk();
        }

        $this->assertSame(2, WaAccountEvent::count());
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', '🔐 طلب إعادة ضبط PIN لرقم الشركة')->count());
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', '⚠️ مخالفة سياسات على حساب الواتساب')->count());
        Queue::assertPushed(RefreshChannelHealth::class, 1);
        $this->assertLogged('info', 'account.event.duplicate', ['field' => 'security', 'event' => 'PIN_RESET_REQUEST']);
    }

    public function test_a_real_identical_event_later_is_a_new_event(): void
    {
        $this->postMetaWebhook('security_pin_reset.json')->assertOk();
        $later = str_replace('1760430800', '1760517200', file_get_contents($this->fixturePath('security_pin_reset.json')));

        $this->postRawWebhook($later)->assertOk();

        $this->assertSame(2, WaAccountEvent::count());
    }

    public function test_the_fingerprint_ignores_key_order_and_the_outer_timestamp(): void
    {
        $a = AccountEventHandler::fingerprint('account_update', '1', 5, ['event' => 'X', 'info' => ['b' => 1, 'a' => 2], 'timestamp' => 10]);
        $b = AccountEventHandler::fingerprint('account_update', '1', 5, ['info' => ['a' => 2, 'b' => 1], 'event' => 'X', 'timestamp' => 99]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, AccountEventHandler::fingerprint('account_update', '1', 6, ['event' => 'X', 'info' => ['b' => 1, 'a' => 2]]));
        $this->assertSame(64, strlen($a));
    }

    public function test_a_failed_effect_stays_unhandled_and_the_next_poll_retries_it(): void
    {
        app()->instance(OpsAlerts::class, new class
        {
            public function notifyAdmins(): bool
            {
                throw new \RuntimeException('alerts down');
            }
        });

        $this->postMetaWebhook('account_update_ban_disable.json')->assertOk();

        $row = WaAccountEvent::sole();
        $this->assertNull($row->handled_at);
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);

        app()->forgetInstance(OpsAlerts::class);
        config(['ops.meta_checks' => false]);
        (new RefreshChannelHealth)->handle(app(MetaHealth::class));

        $this->assertNotNull($row->fresh()->handled_at);
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }
}

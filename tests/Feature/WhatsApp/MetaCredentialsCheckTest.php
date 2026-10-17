<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * فحص رمز ميتا اليومي (debug_token): رمز التطبيق في ترويسة لا في الرابط، ولا رمز ولا سر في المحفوظ
 * ولا في السجل، والتنبيه مرة في الساعة لكل سبب، وانقطاع الاتصال ينبّه بعد مرتين.
 */
class MetaCredentialsCheckTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const APP_ID = '1234567890';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.meta_checks' => true]);
        $this->configureChannel(['wa_app_id' => self::APP_ID]);
        $this->admin = User::factory()->admin()->create();
    }

    private function token(array $data): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => $data + [
            'app_id' => self::APP_ID, 'is_valid' => true, 'expires_at' => 0,
            'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management'],
        ]])]);
    }

    private function stored(): array
    {
        return WaBotSetting::current()->fresh()->wa_credentials_status;
    }

    private function alerts(): array
    {
        return Notification::where('user_id', $this->admin->id)->pluck('title')->all();
    }

    public function test_a_valid_never_expiring_token_is_ok(): void
    {
        $this->token([]);

        $this->artisan('whatsapp:credentials-check')->assertSuccessful();

        $status = $this->stored();
        $this->assertSame('ok', $status['state']);
        $this->assertTrue($status['never_expires']);
        $this->assertSame([], $status['missing_scopes']);
        $this->assertTrue($status['app_matches']);
        $this->assertSame([], $this->alerts());
        $stored = json_encode($status);
        $this->assertStringNotContainsString('test-token', $stored);
        $this->assertStringNotContainsString(self::TEST_APP_SECRET, $stored);
    }

    public function test_the_app_token_travels_in_the_header_only(): void
    {
        $this->token([]);

        $this->artisan('whatsapp:credentials-check');

        Http::assertSent(function (Request $r) {
            return str_starts_with($r->url(), 'https://graph.facebook.com/v25.0/debug_token?')
                && $r->header('Authorization')[0] === 'Bearer '.self::APP_ID.'|'.self::TEST_APP_SECRET
                && !str_contains($r->url(), self::TEST_APP_SECRET)
                && !str_contains($r->url(), 'access_token');
        });
    }

    public static function problems(): array
    {
        return [
            'revoked' => [['is_valid' => false], 'critical', 'token_invalid', '⛔ رمز ميتا غير صالح — الإرسال متوقف'],
            'missing scope' => [['scopes' => ['whatsapp_business_messaging']], 'critical', 'missing_scopes', '⛔ رمز ميتا بلا صلاحيات لازمة'],
            'expires in 10 days' => [['expires_at' => 'days:10'], 'warn', 'expiring', '⚠️ رمز ميتا يقترب من الانتهاء'],
            'expires in 2 days' => [['expires_at' => 'days:2'], 'critical', 'expiring', '⚠️ رمز ميتا يقترب من الانتهاء'],
            'another app' => [['app_id' => '999'], 'warn', 'app_mismatch', '⚠️ رمز ميتا من تطبيق آخر'],
        ];
    }

    #[DataProvider('problems')]
    public function test_a_problem_alerts_once_an_hour(array $data, string $state, string $reason, string $title): void
    {
        if (isset($data['expires_at'])) {
            $data['expires_at'] = now()->addDays((int) substr($data['expires_at'], 5))->addHour()->getTimestamp();
        }
        $this->token($data);

        $this->artisan('whatsapp:credentials-check');
        $this->artisan('whatsapp:credentials-check');

        $this->assertSame($state, $this->stored()['state']);
        $this->assertSame([$reason], $this->stored()['reasons']);
        $this->assertSame([$title], $this->alerts());
    }

    public function test_a_rejected_app_secret_is_its_own_critical_reason(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token signature.', 'code' => 190]], 400)]);

        $this->artisan('whatsapp:credentials-check');

        $this->assertSame('critical', $this->stored()['state']);
        $this->assertSame(['app_credentials_invalid'], $this->stored()['reasons']);
    }

    public function test_an_unreachable_meta_alerts_after_two_failures_and_logs_no_secret(): void
    {
        $this->captureLogs();
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('cURL error 28 for https://graph.facebook.com/v25.0/debug_token?input_token=test-token')]);

        $this->artisan('whatsapp:credentials-check');
        $this->assertSame('unreachable', $this->stored()['state']);
        $this->assertSame([], $this->alerts());

        $this->artisan('whatsapp:credentials-check');
        $this->assertSame(['⚠️ تعذّر فحص رمز ميتا'], $this->alerts());

        $logged = json_encode($this->logged, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('test-token', $logged);
        $this->assertStringNotContainsString(self::TEST_APP_SECRET, $logged);
    }

    public function test_recovering_from_critical_says_so_once(): void
    {
        // تزييف واحد بحالة متغيرة: أنماط Http::fake تتراكم والأول يغلب
        $valid = false;
        Http::fake(['graph.facebook.com/*' => function () use (&$valid) {
            return Http::response(['data' => ['app_id' => self::APP_ID, 'is_valid' => $valid, 'expires_at' => 0,
                'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management']]]);
        }]);

        $this->artisan('whatsapp:credentials-check');
        $valid = true;
        $this->artisan('whatsapp:credentials-check');
        $this->artisan('whatsapp:credentials-check');

        $this->assertSame(['⛔ رمز ميتا غير صالح — الإرسال متوقف', '✅ عادت قناة ميتا سليمة'], $this->alerts());
    }

    public function test_without_an_app_id_nothing_is_called(): void
    {
        WaBotSetting::current()->forceFill(['wa_app_id' => null])->save();
        Http::fake();

        $this->artisan('whatsapp:credentials-check')->assertSuccessful();

        $this->assertSame('not_configured', $this->stored()['state']);
        Http::assertNothingSent();
        $this->assertSame([], $this->alerts());
    }

    public function test_the_flag_switches_the_check_off(): void
    {
        config(['ops.meta_checks' => false]);
        Http::fake();

        $this->artisan('whatsapp:credentials-check')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(WaBotSetting::current()->fresh()->wa_credentials_status);
    }

    public function test_the_check_never_suspends_sending(): void
    {
        $this->token(['is_valid' => false]);

        $this->artisan('whatsapp:credentials-check');

        $this->assertFalse(WaBotSetting::current()->fresh()->wa_suspended);
    }
}

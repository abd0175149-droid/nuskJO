<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\Ops\OpsHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\TestCase;

/**
 * whatsapp:health (E23): عقدة الرقم كل 30 دقيقة إلى آلة الحالات، والاشتراكات كل ساعة أو مع --force، والاسم القديم
 * whatsapp:health-status بديل له. فشل الشبكة لا يغيّر شيئاً حتى المرة الثانية، ولا رمز في أي سطر سجل.
 */
class WhatsAppHealthCommandTest extends TestCase
{
    use CapturesLogs;
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

    private function stored(): array
    {
        return (array) WaBotSetting::current()->fresh()->wa_channel_health;
    }

    public function test_an_available_number_feeds_the_state_machine_once_and_keeps_a_summary_without_ids(): void
    {
        config(['features.env.channel_health' => true]);
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health')->assertSuccessful();

        $s = WaBotSetting::current()->fresh();
        $this->assertSame('AVAILABLE', $s->wa_health);
        $this->assertNotNull($s->wa_health_checked_at);
        $summary = $this->stored();
        $this->assertSame('ok', $summary['state']);
        $this->assertSame(['status' => 'CONNECTED', 'name_status' => 'APPROVED', 'throughput' => 'STANDARD', 'messaging_limit' => 'TIER_2K'], $summary['number']);
        $this->assertTrue($summary['webhook']['ok']);
        $this->assertSame(1, $this->metaCallCount('node'));
        $this->assertSame(1, $this->metaCallCount('waba'));
        $this->assertSame(1, $this->metaCallCount('app'));
        // معرّفات الكيانات لا تُحفظ (عدا WABA المكتشف في عموده)، ولا الرابط كاملاً
        $json = json_encode($summary);
        $this->assertStringNotContainsString('222170244656060', $json);
        $this->assertStringNotContainsString('api/whatsapp/webhook', $json);
        $this->assertSame(0, Notification::count());
    }

    public function test_the_node_request_carries_the_extended_fields(): void
    {
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health');

        $node = collect($this->metaRequests)->first(fn ($r) => !str_contains($r->url(), 'subscri'));
        parse_str((string) parse_url($node->url(), PHP_URL_QUERY), $q);
        foreach (['health_status', 'quality_rating', 'status', 'name_status', 'throughput', 'webhook_configuration', 'whatsapp_business_manager_messaging_limit'] as $field) {
            $this->assertContains($field, explode(',', $q['fields']));
        }
        $this->assertSame('GET', $node->method());
    }

    public function test_subscriptions_are_read_once_an_hour_unless_forced(): void
    {
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health');
        $this->artisan('whatsapp:health');
        $this->assertSame(2, $this->metaCallCount('node'));
        $this->assertSame(1, $this->metaCallCount('app'));

        $this->artisan('whatsapp:health', ['--force' => true]);
        $this->assertSame(2, $this->metaCallCount('app'));
        $this->assertSame(2, $this->metaCallCount('waba'));
        // كل النداءات قراءة
        $this->assertSame([], array_filter($this->metaRequests, fn ($r) => $r->method() !== 'GET'));
    }

    public function test_one_network_failure_changes_nothing_and_the_second_alerts_once(): void
    {
        config(['features.env.channel_health' => true]);
        $this->captureLogs();
        $this->fakeMetaReads(['node' => fn () => throw new ConnectionException('cURL error 7: https://graph.facebook.com/v25.0/x?access_token=test-token')]);

        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->assertSame(0, Notification::count());
        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);

        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->artisan('whatsapp:health')->assertSuccessful();
        $this->assertSame(['⚠️ تعذّر فحص صحة القناة'], Notification::where('user_id', $this->admin->id)->pluck('title')->all());
        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);

        $logs = json_encode($this->logged);
        $this->assertStringNotContainsString('test-token', $logs);
        $this->assertStringNotContainsString('access_token', $logs);
    }

    public function test_the_old_name_runs_the_same_monitor(): void
    {
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health-status')->assertSuccessful();

        $this->assertSame(1, $this->metaCallCount('node'));
        $this->assertSame(1, $this->metaCallCount('app'));
        $this->assertArrayHasKey('webhook', $this->stored());
    }

    public function test_with_the_flag_off_the_hourly_check_stays_as_it_was(): void
    {
        config(['features.env.channel_monitor' => false]);
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health')->assertSuccessful();

        $this->assertSame(['node'], $this->metaCalls);
        parse_str((string) parse_url($this->metaRequests[0]->url(), PHP_URL_QUERY), $q);
        $this->assertSame('health_status,quality_rating,verified_name', $q['fields']);
        $this->assertArrayNotHasKey('webhook', $this->stored());
    }

    public function test_the_schedule_runs_wa_health_every_thirty_minutes_and_drops_the_old_hourly_entry(): void
    {
        $events = collect(app(Schedule::class)->events());

        $health = $events->filter(fn ($e) => str_contains((string) $e->command, 'whatsapp:health') && !str_contains((string) $e->command, 'expiry'));
        $this->assertCount(1, $health);
        $this->assertSame('7,37 * * * *', $health->first()->expression);
        $this->assertFalse($events->contains(fn ($e) => str_contains((string) $e->command, 'health-status')));
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'whatsapp:doctor --budget=20 --store')));
    }

    public function test_the_half_hour_run_is_skipped_while_the_flag_is_off(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'whatsapp:health') && !str_contains((string) $e->command, 'expiry'));

        config(['features.env.channel_monitor' => false]);
        $this->travelTo(now()->setTime(10, 37));
        $this->assertFalse($event->filtersPass($this->app));
        $this->travelTo(now()->setTime(11, 7));
        $this->assertTrue($event->filtersPass($this->app));
        config(['features.env.channel_monitor' => true]);
        $this->travelTo(now()->setTime(11, 37));
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_an_unknown_waba_is_discovered_from_the_health_entities(): void
    {
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health');

        $s = WaBotSetting::current()->fresh();
        $this->assertSame('102290129340398', $s->wa_waba_id);
        $this->assertSame('discovered', $s->wa_waba_id_source);
        $this->assertSame('102290129340398', OpsHealth::store()->get('wa:waba:detected'));
    }

    public function test_a_different_configured_waba_alerts_and_writes_nothing(): void
    {
        config(['services.whatsapp.waba_id' => '102290129349999']);
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health');

        $this->assertNull(WaBotSetting::current()->fresh()->wa_waba_id);
        $this->assertFalse($this->stored()['waba_match']);
        $this->assertSame(['⚠️ معرّف حساب الأعمال لا يطابق فحص ميتا'], Notification::where('user_id', $this->admin->id)->pluck('title')->all());
    }

    public function test_an_unknown_field_error_retries_once_without_the_optional_fields(): void
    {
        $n = 0;
        $this->fakeMetaReads(['node' => function () use (&$n) {
            return ++$n === 1
                ? Http::response(['error' => ['message' => '(#100) Tried accessing nonexisting field (webhook_configuration)', 'type' => 'OAuthException', 'code' => 100]], 400)
                : Http::response($this->graphFixture('phone_node_ok'));
        }]);

        $this->artisan('whatsapp:health');

        $this->assertSame(2, $this->metaCallCount('node'));
        $nodes = array_values(array_filter($this->metaRequests, fn ($r) => !str_contains($r->url(), 'subscri')));
        parse_str((string) parse_url($nodes[1]->url(), PHP_URL_QUERY), $q);
        $this->assertStringNotContainsString('webhook_configuration', $q['fields']);
        $this->assertSame('ok', $this->stored()['state']);
        $this->assertFalse($this->stored()['webhook']['configuration_available']);
    }

    public function test_meta_checks_off_makes_no_call(): void
    {
        config(['ops.meta_checks' => false]);
        $this->fakeMetaReads();

        $this->artisan('whatsapp:health')->expectsOutputToContain('مطفأة')->assertSuccessful();

        Http::assertNothingSent();
    }
}

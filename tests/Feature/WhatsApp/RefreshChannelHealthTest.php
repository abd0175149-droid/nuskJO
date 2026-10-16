<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\RefreshChannelHealth;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\MetaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الفحص مرجع الحالة (E22): health_status يضع AVAILABLE وLIMITED آلياً من غير القفل وBLOCKED من أي حالة، والقفل لا يُرفع
 * إلا باقتراح وتنبيه. الرمز الباطل TOKEN_INVALID ولا يُمسح إلا بفحص بدأه إنسان. فشل الشبكة بلا انتقال.
 */
class RefreshChannelHealthTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.channel_health' => true, 'ops.meta_checks' => true, 'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    private function health(string $fixture): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/'.$fixture)), true))]);
    }

    private function fixture(string $fixture): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/'.$fixture)), true);
    }

    private function poll(bool $human = false): array
    {
        return app(MetaHealth::class)->channel($human);
    }

    private function state(string $health, array $extra = []): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => $health] + $extra)->save();
    }

    private function s(): WaBotSetting
    {
        return WaBotSetting::current()->fresh();
    }

    private function titles(): array
    {
        return Notification::where('user_id', $this->admin->id)->orderBy('id')->pluck('title')->all();
    }

    public function test_blocked_takes_metas_first_blocked_entity_and_keeps_the_solution_without_ids(): void
    {
        $this->health('health_blocked.json');

        $this->poll();

        $s = $this->s();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertSame('poll', $s->wa_health_source);
        $this->assertSame('poll:141010 — The Business has not passed business verification.', $s->wa_health_reason);
        $this->assertSame('Visit business settings and start or resolve the business verification request.', $s->wa_health_meta['possible_solution']);
        $this->assertSame('RED', $s->wa_health_meta['quality_rating']);
        $this->assertTrue($s->wa_health_meta['waba_match']);
        $this->assertNotNull($s->wa_health_checked_at);
        $this->assertStringNotContainsString('102290129340398', json_encode([$s->wa_health_meta, $s->wa_health_reason]));
        // تنبيه الانتقال وحده (لا «ميتا أوقفت الإرسال» ثانٍ من الفحص)، وتنبيه الجودة الحمراء كما كان
        $this->assertSame(['⚠️ جودة رقم الواتساب حمراء', '⛔ ميتا حجبت الإرسال على رقم الشركة'], $this->titles());
        $this->assertFalse((bool) $s->wa_suspended);
    }

    public function test_available_on_a_blocked_channel_suggests_unblocking_and_does_not_lift(): void
    {
        $this->state('BLOCKED');
        $this->health('health_available.json');

        $this->poll();
        $this->poll();

        $s = $this->s();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertTrue((bool) $s->wa_health_unblock_suggested);
        $this->assertSame(['✅ ميتا تشير إلى رفع القيد — راجع ثم ارفع القفل يدوياً'], $this->titles());
    }

    public function test_limited_then_available_returns_to_available_automatically(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push($this->fixture('health_limited.json'))->push($this->fixture('health_available.json'))]);

        $this->poll();
        $this->assertSame('LIMITED', $this->s()->wa_health);
        $this->assertStringContainsString('250 business-initiated', $this->s()->wa_health_reason);

        $this->poll();
        $this->assertSame('AVAILABLE', $this->s()->wa_health);
        $this->assertSame(['⚠️ قيد جزئي من ميتا — ردود العملاء تعمل', '✅ رُفع القيد الجزئي من ميتا'], $this->titles());
    }

    public function test_a_190_on_the_check_is_token_invalid_and_only_a_human_check_clears_it(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->fixture('error_190.json'), 401)->push($this->fixture('health_available.json'))->push($this->fixture('health_available.json'))]);

        $this->poll();
        $this->assertSame('TOKEN_INVALID', $this->s()->wa_health);
        $this->assertStringStartsWith('token:190', $this->s()->wa_health_reason);

        // فحص مجدول ناجح: اقتراح وتنبيه فقط
        $this->poll();
        $this->assertSame('TOKEN_INVALID', $this->s()->wa_health);
        $this->assertTrue((bool) $this->s()->wa_health_unblock_suggested);

        // بعد حفظ رمز جديد: فحص بدأه إنسان
        $this->poll(human: true);
        $this->assertSame('AVAILABLE', $this->s()->wa_health);
        $this->assertSame(['🔑 رمز ميتا غير صالح — جدّد System User token', '✅ رمز ميتا يعمل الآن — اضغط «افحص الآن»', '✅ رمز ميتا يعمل الآن'], $this->titles());
    }

    public function test_an_invalid_token_from_the_daily_check_is_token_invalid_with_one_alert(): void
    {
        WaBotSetting::current()->forceFill(['wa_app_id' => '100000000000002'])->save();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => ['is_valid' => false, 'app_id' => '100000000000002',
            'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management'], 'expires_at' => 0]])]);

        $this->artisan('whatsapp:credentials-check')->assertSuccessful();

        $this->assertSame(['TOKEN_INVALID', 'token_check'], [$this->s()->wa_health, $this->s()->wa_health_source]);
        $this->assertSame(['🔑 رمز ميتا غير صالح — جدّد System User token'], $this->titles());
    }

    public function test_saving_a_new_token_queues_a_human_initiated_check(): void
    {
        Queue::fake();
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->postJson('/api/whatsapp/settings/secrets/wa_token', ['value' => str_repeat('n', 40), 'reason' => 'routine'])->assertOk();

        Queue::assertPushed(RefreshChannelHealth::class, fn ($job) => $job->humanInitiated === true);
    }

    public function test_a_missing_scope_on_the_check_is_token_invalid(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 10, 'type' => 'OAuthException', 'message' => 'Application does not have permission for this action']], 403)]);

        $this->poll();

        $this->assertSame('TOKEN_INVALID', $this->s()->wa_health);
        $this->assertStringStartsWith('scope_missing', $this->s()->wa_health_reason);
    }

    public function test_network_failure_twice_alerts_once_and_changes_nothing(): void
    {
        $this->state('AVAILABLE');
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

        $this->poll();
        $this->poll();
        $this->poll();

        $this->assertSame('AVAILABLE', $this->s()->wa_health);
        $this->assertNull($this->s()->wa_health_checked_at);
        $this->assertSame(['⚠️ تعذّر فحص صحة القناة'], $this->titles());
    }

    public function test_a_different_business_account_at_meta_alerts_without_locking(): void
    {
        config(['services.whatsapp.waba_id' => '109999999999999']);
        $this->health('health_available.json');

        $this->poll();

        $this->assertSame('AVAILABLE', $this->s()->wa_health);
        $this->assertFalse($this->s()->wa_health_meta['waba_match']);
        $this->assertSame(['⚠️ معرّف حساب الأعمال لا يطابق فحص ميتا'], $this->titles());
    }

    public function test_the_job_is_unique_and_queued_on_default(): void
    {
        Queue::fake();

        RefreshChannelHealth::dispatch();
        RefreshChannelHealth::dispatch();
        RefreshChannelHealth::dispatch(humanInitiated: true);

        Queue::assertPushed(RefreshChannelHealth::class, 2);
        $job = new RefreshChannelHealth;
        $this->assertSame([1, 20, 300], [$job->tries, $job->timeout, $job->uniqueFor]);
        $this->assertNull($job->queue, 'الطابور default لا طابور الردود');
    }

    public function test_an_expired_restriction_triggers_a_check_and_a_confirmed_limited_returns_to_available(): void
    {
        Queue::fake();
        $this->state('LIMITED', ['wa_health_until' => now()->subMinute()]);

        $this->artisan('whatsapp:health-expiry')->assertSuccessful();
        Queue::assertPushed(RefreshChannelHealth::class);

        $this->health('health_available.json');
        (new RefreshChannelHealth)->handle(app(MetaHealth::class));
        $this->assertSame('AVAILABLE', $this->s()->wa_health);
    }

    public function test_an_expired_block_only_suggests_unblocking(): void
    {
        $this->state('BLOCKED', ['wa_health_until' => now()->subMinute()]);
        $this->health('health_available.json');

        $this->artisan('whatsapp:health-expiry')->assertSuccessful();

        $this->assertSame('BLOCKED', $this->s()->wa_health);
        $this->assertTrue((bool) $this->s()->wa_health_unblock_suggested);
    }

    public function test_with_the_flag_off_the_check_records_but_never_moves_the_state(): void
    {
        config(['features.env.channel_health' => false]);
        $this->health('health_blocked.json');

        $this->poll();

        $this->assertSame('UNKNOWN', $this->s()->wa_health);
        $this->assertNotNull($this->s()->wa_health_checked_at);
        $this->assertSame(['⛔ ميتا أوقفت الإرسال من رقمنا', '⚠️ جودة رقم الواتساب حمراء'], $this->titles());
    }
}

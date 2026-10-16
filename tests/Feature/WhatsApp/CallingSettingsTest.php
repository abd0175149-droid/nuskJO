<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * إعدادات الاتصال وبطاقتا المكالمات والتعايش (E39): القراءة من ميتا وحفظ آخر قراءة، والتنبيه الأحمر للاتصال المفعّل بلا
 * طريقة رد، ونص المتابعة بحارسه وتدقيقه، والأمر المؤكد وحده يكتب عند ميتا. وحامل التعايش يملك whatsapp.view_all.
 * والبطاقتان وما تحتهما 403 لمن لا يملك whatsapp.settings.
 */
class CallingSettingsTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.calls_webhook' => true, 'features.env.coexistence_guard' => true, 'audit.extended' => false]);
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
    }

    /** ميتا: GET settings بالحالة المعطاة، وPOST settings وcalls بنجاح */
    private function fakeMeta(string $status = 'ENABLED'): void
    {
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $r) use ($status) {
            if ($r->method() === 'GET' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/settings')) {
                return Http::response(['calling' => ['status' => $status, 'call_icon_visibility' => 'DEFAULT', 'callback_permission_status' => 'ENABLED',
                    'call_hours' => ['status' => 'ENABLED', 'timezone_id' => 'Asia/Amman'], 'sip' => ['status' => 'DISABLED']]]);
            }

            return Http::response(['success' => true]);
        }]);
    }

    public function test_every_card_route_is_forbidden_without_the_settings_permission(): void
    {
        $agent = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'], 'agent')->create();
        Http::fake();

        $this->actingAs($agent)->getJson('/api/whatsapp/calling')->assertForbidden();
        $this->actingAs($agent)->postJson('/api/whatsapp/calling/refresh')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/whatsapp/calling/settings', ['answer_mode' => 'none', 'auto_reject' => false])->assertForbidden();
        $this->actingAs($agent)->getJson('/api/whatsapp/coexistence')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/whatsapp/coexistence/settings', ['holder_user_id' => $agent->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_cards_do_not_exist_while_their_flags_are_off(): void
    {
        config(['features.env.calls_webhook' => false, 'features.env.coexistence_guard' => false]);

        $this->actingAs($this->owner)->getJson('/api/whatsapp/calling')->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/whatsapp/coexistence')->assertNotFound();
        $this->actingAs($this->owner)->get('/whatsapp/settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('calling', null)->where('coexistence', null));
    }

    public function test_reading_from_meta_keeps_a_snapshot_and_warns_when_calling_is_on_without_an_answer_method(): void
    {
        $this->fakeMeta('ENABLED');
        $version = (int) WaBotSetting::current()->settings_version;

        $res = $this->actingAs($this->owner)->postJson('/api/whatsapp/calling/refresh')->assertOk();

        $res->assertJsonPath('calling.snapshot.calling.status', 'ENABLED')
            ->assertJsonPath('calling.snapshot.source', 'read')
            ->assertJsonPath('calling.warning', true)
            ->assertJsonMissingPath('calling.snapshot.calling.sip');
        $this->assertSame($version, (int) WaBotSetting::current()->fresh()->settings_version, 'القراءة لا ترفع نسخة الإعدادات');
        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

        // بعد اعتماد طريقة رد يزول التنبيه
        $this->actingAs($this->owner)->putJson('/api/whatsapp/calling/settings', ['answer_mode' => 'webrtc', 'auto_reject' => false])
            ->assertOk()->assertJsonPath('calling.warning', false);
    }

    public function test_the_followup_text_passes_the_rule_guard_and_is_audited(): void
    {
        $this->actingAs($this->owner)->putJson('/api/whatsapp/calling/settings', [
            'answer_mode' => 'none', 'auto_reject' => true, 'followup_text' => 'السعر 300 دينار، اتصل على 0790000000',
        ])->assertJsonValidationErrors('followup_text');
        $this->assertNull(WaBotSetting::current()->fresh()->calls_followup_text);

        $this->actingAs($this->owner)->putJson('/api/whatsapp/calling/settings', [
            'answer_mode' => 'none', 'auto_reject' => true, 'followup_text' => 'وصلتنا مكالمتك. اكتب لنا هنا أو اتصل بنا على {company.phone}.',
        ])->assertOk()->assertJsonPath('calling.followup.text', 'وصلتنا مكالمتك. اكتب لنا هنا أو اتصل بنا على {company.phone}.');

        $s = WaBotSetting::current()->fresh();
        $this->assertTrue($s->calls_auto_reject);
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaCallsFollowupUpdate->value)->count());
    }

    public function test_account_settings_update_refreshes_the_snapshot_when_calls_are_on(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'time' => 1760425800, 'changes' => [[
            'field' => 'account_settings_update',
            'value' => ['phone_number_id' => self::TEST_PHONE_NUMBER_ID, 'timestamp' => 1760425800, 'type' => 'call_settings',
                'calling' => ['status' => 'DISABLED', 'call_icon_visibility' => 'DISABLE_ALL']],
        ]]]]];

        $this->postRawWebhook((string) json_encode($payload))->assertOk();

        $snap = WaBotSetting::current()->fresh()->calling_snapshot;
        $this->assertSame(['status' => 'DISABLED', 'call_icon_visibility' => 'DISABLE_ALL'], $snap['calling']);
        $this->assertSame('webhook', $snap['source']);
        $this->assertSame('CALL_SETTINGS', WaAccountEvent::where('field', 'account_settings_update')->sole()->event);
    }

    public function test_the_command_refuses_without_the_flag_or_an_answer_method_and_writes_nothing(): void
    {
        $this->fakeMeta('DISABLED');
        $file = tempnam(sys_get_temp_dir(), 'calling');
        file_put_contents($file, json_encode(['calling' => ['status' => 'ENABLED', 'call_icon_visibility' => 'DEFAULT']]));

        config(['features.env.calls_webhook' => false]);
        $this->artisan('whatsapp:calling-settings', ['--apply' => $file])->assertFailed();
        config(['features.env.calls_webhook' => true]);
        $this->artisan('whatsapp:calling-settings', ['--apply' => $file])->assertFailed();

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        $this->assertSame(0, AuditLog::where('action', AuditAction::WaCallingSettingsApply->value)->count());
        @unlink($file);
    }

    public function test_the_command_applies_after_confirmation_and_is_always_audited(): void
    {
        $this->fakeMeta('DISABLED');
        DB::table('wa_bot_settings')->update(['calls_answer_mode' => 'webrtc']);
        $file = tempnam(sys_get_temp_dir(), 'calling');
        file_put_contents($file, json_encode(['calling' => ['status' => 'ENABLED', 'call_icon_visibility' => 'DEFAULT', 'callback_permission_status' => 'ENABLED']]));

        $this->artisan('whatsapp:calling-settings', ['--apply' => $file])
            ->expectsConfirmation('تطبيق هذه الإعدادات على رقم الشركة عند ميتا؟', 'no')->assertSuccessful();
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');

        $this->artisan('whatsapp:calling-settings', ['--apply' => $file])
            ->expectsConfirmation('تطبيق هذه الإعدادات على رقم الشركة عند ميتا؟', 'yes')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/'.self::TEST_PHONE_NUMBER_ID.'/settings')
            && $r['calling']['status'] === 'ENABLED');
        $log = AuditLog::where('action', AuditAction::WaCallingSettingsApply->value)->sole();
        $this->assertSame('DISABLED', $log->changes['before']['status']);
        $this->assertSame('ENABLED', $log->changes['after']['status']);
        @unlink($file);
    }

    public function test_the_holder_must_see_every_conversation_and_the_change_is_audited(): void
    {
        $narrow = User::factory()->withPermissions(['whatsapp.view'], 'narrow')->create();
        $wide = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all'], 'wide')->create();

        $this->actingAs($this->owner)->putJson('/api/whatsapp/coexistence/settings', ['holder_user_id' => $narrow->id])
            ->assertJsonValidationErrors('holder_user_id');
        $this->assertNull(WaBotSetting::current()->fresh()->coexistence_holder_user_id);

        $this->actingAs($this->owner)->putJson('/api/whatsapp/coexistence/settings', ['holder_user_id' => $wide->id, 'echo_pause_minutes' => 120])
            ->assertOk()->assertJsonPath('coexistence.holder_id', $wide->id)->assertJsonPath('coexistence.holder_valid', true)
            // المرشّحون: من يرى كل المحادثات وحده
            ->assertJsonPath('coexistence.candidates', [['id' => $wide->id, 'name' => $wide->name]]);

        $s = WaBotSetting::current()->fresh();
        $this->assertSame([$wide->id, 120], [(int) $s->coexistence_holder_user_id, (int) $s->echo_pause_minutes]);
        $log = AuditLog::where('action', AuditAction::WaCoexistenceHolderSet->value)->sole();
        $this->assertSame($wide->id, $log->changes['holder_to'] ?? $log->meta['holder_to'] ?? null);
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaOptout;
use App\Services\WhatsApp\UserPreferencesHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * تفضيلات واتساب (E21، العلم user_preferences_optout): العميل أوقف التسويق من تطبيقه فيُسجَّل في wa_optouts بالمصدر
 * meta_pref، وresume تحذف الإيقاف الأقدم منها فقط، وحدث رقم آخر يُعدّ ولا يُكتب، ورقم الإدارة لا يُسجَّل. لا رسالة للعميل.
 */
class UserPreferencesTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.user_preferences_optout' => true, 'audit.extended' => true]);
        $this->assertTrue(UserPreferencesHandler::enabled());
    }

    private function prefs(array $items, string $pnid = self::TEST_PHONE_NUMBER_ID): string
    {
        return (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'user_preferences', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $pnid],
            'user_preferences' => $items,
        ]]]]]]);
    }

    public function test_stop_records_the_customer_once_and_writes_no_message(): void
    {
        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();
        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();

        $row = WaOptout::sole();
        $this->assertSame(['16505551234', 'meta_pref', UserPreferencesHandler::REASON_STOP], [$row->phone, $row->source, $row->reason]);
        $this->assertSame(1760426040, $row->created_at->getTimestamp(), 'وقت الحدث من ميتا');
        Http::assertNothingSent();

        $audit = AuditLog::where('action', AuditAction::WaOptoutMeta->value)->get();
        $this->assertCount(2, $audit);
        $this->assertSame(['meta', 'user_preferences', ['count' => 1, 'source' => 'meta_pref']], [$audit[0]->actor_type, $audit[0]->actor_ref, $audit[0]->changes]);
        $this->assertStringNotContainsString('16505551234', json_encode($audit->toArray()));
    }

    public function test_a_later_resume_removes_it(): void
    {
        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();
        $this->postMetaWebhook('user_preferences_resume.json')->assertOk();

        $this->assertSame(0, WaOptout::count());
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaOptinMeta->value)->count());
    }

    public function test_an_older_resume_never_lifts_a_newer_stop_typed_by_the_customer(): void
    {
        WaOptout::create(['phone' => '16505551234', 'reason' => 'طلب العميل', 'source' => 'text', 'created_at' => now()]);

        $this->postMetaWebhook('user_preferences_resume.json')->assertOk();

        $this->assertSame('text', WaOptout::sole()->source);
    }

    public function test_an_old_stop_replayed_by_meta_does_not_rewrite_a_newer_record(): void
    {
        WaOptout::create(['phone' => '16505551234', 'reason' => 'طلب العميل', 'source' => 'button', 'created_at' => now()]);

        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();

        $this->assertSame(['button', 'طلب العميل'], [WaOptout::sole()->source, WaOptout::sole()->reason]);
    }

    public function test_an_event_for_another_number_writes_nothing_and_is_counted(): void
    {
        $this->postMetaWebhook('user_preferences_foreign_phone.json')->assertOk();

        $this->assertSame(0, WaOptout::count());
        $this->assertSame(1, (int) Cache::get('wa:userpref:foreign:'.now()->format('Ymd')));
        $this->assertSame(0, AuditLog::where('action', AuditAction::WaOptoutMeta->value)->count());
    }

    public function test_an_admin_number_is_never_recorded(): void
    {
        WaAdminNumber::create(['phone' => '16505551234', 'name' => 'المدير', 'is_active' => true]);

        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();

        $this->assertSame(0, WaOptout::count());
    }

    public function test_only_marketing_stop_and_resume_count(): void
    {
        $this->postRawWebhook($this->prefs([
            ['wa_id' => '16505551234', 'category' => 'utility_messages', 'value' => 'stop', 'timestamp' => 1760426040],
            ['wa_id' => '16505551234', 'category' => 'marketing_messages', 'value' => 'pause', 'timestamp' => 1760426040],
            ['category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => 1760426040],
        ]))->assertOk();

        $this->assertSame(0, WaOptout::count());
    }

    public function test_a_customer_known_by_business_id_only_is_recorded_by_that_id(): void
    {
        $this->postRawWebhook($this->prefs([['user_id' => 'US.13491208655302741918', 'category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => 1760426040]]))->assertOk();
        $this->assertSame('US.13491208655302741918', WaOptout::sole()->wa_user_id);

        // المعرّف لمحادثة رقمها معروف: يُسجَّل بالرقم
        WaOptout::query()->delete();
        WaConversation::factory()->create(['phone' => '16505553333', 'wa_phone' => '16505553333', 'wa_user_id' => 'US.13491208655302741918']);
        $this->postRawWebhook($this->prefs([['user_id' => 'US.13491208655302741918', 'category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => 1760426041]]))->assertOk();
        $this->assertSame(['16505553333', null], [WaOptout::sole()->phone, WaOptout::sole()->wa_user_id]);
    }

    public function test_with_the_flag_off_the_field_is_only_audited_as_counts(): void
    {
        config(['features.env.user_preferences_optout' => false]);

        $this->postMetaWebhook('user_preferences_stop.json')->assertOk();

        $this->assertSame(0, WaOptout::count());
        $this->assertSame(['count' => 1, 'by_value' => ['stop' => 1]], AuditLog::where('action', AuditAction::WaWebhookOther->value)->sole()->changes);
    }

    public function test_a_typed_stop_and_the_optout_button_record_their_source(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(1760425800 + 60));
        $this->fakeGraph();

        $this->postMetaWebhook('optout_iqaf.json')->assertOk();
        $this->assertSame('text', WaOptout::sole()->source);

        WaOptout::query()->delete();
        $raw = str_replace('"offer:12"', '"optout"', (string) file_get_contents($this->fixturePath('interactive_button_reply.json')));
        $this->postRawWebhook($raw)->assertOk();
        $this->assertSame('button', WaOptout::sole()->source);
    }
}

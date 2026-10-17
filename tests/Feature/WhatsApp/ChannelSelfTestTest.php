<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaMessage;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\ChannelSelfTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesMetaReads;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * «اختبار الاتصال» (E23): قراءات رسمية، وارتداد موقّع بالكائن nuskjo_selftest بلا entry ولا messages، يُعترض بعد التوقيع
 * وقبل أي حفظ أو معالجة. nonce غريب يُتجاهل بصمت، والتوقيع الخاطئ 403 كما هو.
 */
class ChannelSelfTestTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesMetaReads;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.channel_selftest' => true, 'features.env.inbound_event_log' => true, 'app.url' => self::OUR_URL,
            'services.whatsapp.waba_id' => '102290129340398']);
        $this->configureChannel(['wa_app_id' => self::OUR_APP_ID]);
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
    }

    /** Graph من الملفات، والارتداد يمر فعلاً بمسار الويبهوك داخل التطبيق نفسه */
    private function fakeAll(array $overrides = []): void
    {
        $this->fakeMetaReads($overrides);
        Http::fake([self::OUR_URL.'/*' => function (Request $r) {
            $res = $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $r->header('X-Hub-Signature-256')[0] ?? '',
            ], $r->body());

            return Http::response($res->getContent(), $res->getStatusCode());
        }]);
    }

    public function test_all_steps_pass_on_a_healthy_channel_and_the_loopback_leaves_no_trace(): void
    {
        Queue::fake();
        Cache::forever('wa:sig:last_ok_at', now()->subHour()->toIso8601String());
        $this->fakeAll();

        $res = $this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->assertOk();

        $steps = collect($res->json('steps'))->keyBy('key');
        foreach (['node', 'app_secret', 'waba', 'loopback', 'last_real'] as $key) {
            $this->assertTrue($steps[$key]['ok'], $key.': '.$steps[$key]['detail']);
        }
        $this->assertTrue($res->json('ok'));

        $loop = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (Request $r) => str_starts_with($r->url(), self::OUR_URL));
        $body = json_decode($loop->body(), true);
        $this->assertSame(ChannelSelfTest::OBJECT, $body['object']);
        $this->assertSame(['object', 'nonce', 'ts'], array_keys($body));
        $this->assertStringNotContainsString('messages', $loop->body());
        $this->assertStringNotContainsString('entry', $loop->body());

        $this->assertSame(0, DB::table('wa_conversations')->count());
        $this->assertSame(0, WaMessage::count());
        $this->assertSame(0, DB::table('wa_webhook_events')->count());
        Queue::assertNotPushed(ProcessWhatsAppReply::class);
        // الارتداد لا يحدّث «آخر توقيع صحيح» من ميتا
        $this->assertSame(now()->subHour()->toIso8601String(), Cache::get('wa:sig:last_ok_at'));
        $this->assertSame([], array_filter($this->metaRequests, fn ($r) => $r->method() !== 'GET'));
    }

    public function test_a_foreign_callback_is_explained(): void
    {
        $this->fakeAll(['app' => 'app_subscriptions_wrong_url']);

        $steps = collect($this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->json('steps'))->keyBy('key');

        $this->assertFalse($steps['app_secret']['ok']);
        $this->assertStringContainsString('aibot-platform.example.com وليس إلى نُسك', $steps['app_secret']['detail']);
        $this->assertTrue($steps['loopback']['ok']);
    }

    public function test_without_an_app_id_the_secret_step_is_not_possible(): void
    {
        $this->configureChannel(['wa_app_id' => null]);
        $this->fakeAll();

        $steps = collect($this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->json('steps'))->keyBy('key');

        $this->assertNull($steps['app_secret']['ok']);
        $this->assertStringContainsString('أدخل App ID', $steps['app_secret']['detail']);
    }

    public function test_the_receiving_side_marks_our_nonce_and_stores_nothing(): void
    {
        Queue::fake();
        $nonce = str_repeat('a', 32);
        OpsHealth::store()->put('wa:selftest:'.$nonce, 'pending', 120);

        $this->postRawWebhook(json_encode(['object' => 'nuskjo_selftest', 'nonce' => $nonce, 'ts' => time()]))->assertOk();

        $this->assertSame('received', OpsHealth::store()->get('wa:selftest:'.$nonce));
        $this->assertSame(0, DB::table('wa_webhook_events')->count());
        $this->assertSame(0, DB::table('wa_conversations')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_nonce_we_did_not_issue_is_ignored_and_a_bad_signature_is_refused(): void
    {
        $raw = json_encode(['object' => 'nuskjo_selftest', 'nonce' => str_repeat('b', 32), 'ts' => time()]);

        $this->postRawWebhook($raw)->assertOk();
        $this->assertNull(OpsHealth::store()->get('wa:selftest:'.str_repeat('b', 32)));
        $this->assertSame(0, DB::table('wa_webhook_events')->count());

        $this->postRawWebhook($raw, 'wrong-secret')->assertForbidden();
    }

    public function test_with_the_flag_off_there_is_no_button_and_no_interception(): void
    {
        config(['features.env.channel_selftest' => false]);

        $this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->assertNotFound();
        $this->assertFalse(ChannelSelfTest::intercepts(json_encode(['object' => 'nuskjo_selftest', 'nonce' => str_repeat('c', 32)])));
    }

    public function test_permission_and_rate_limit(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view'], 'staff')->create();
        $this->actingAs($staff)->postJson('/api/whatsapp/channel-test')->assertForbidden();

        $this->fakeAll();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->assertOk();
        }
        $this->actingAs($this->owner)->postJson('/api/whatsapp/channel-test')->assertStatus(429);
    }
}

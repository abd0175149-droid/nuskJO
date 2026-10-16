<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * رد ميتا المتزامن مصنَّفاً (E18، علم meta_error_v2): صف failed بالرمز والفئة وfbtrace_id ونص عربي، ونتيجة بالفئة والإجراء،
 * وتنبيه واحد في الساعة، وwa_last_error للقناة وحدها، والإيقاف الآلي على 368 وحده، وفشل الشبكة يترك صفاً، وسطر سجل بالمعرّفات.
 */
class ChannelErrorClassificationTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.meta_error_v2' => true, 'audit.extended' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/whatsapp/{$name}")), true);
    }

    private function failWith(string $fixture, int $status = 400): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response($this->fixture($fixture), $status)]);
    }

    private function send(string $source = 'bot', ?int $staffId = null): array
    {
        return WhatsAppChannel::sendText(WaConversation::factory()->windowOpen()->create(), 'أهلاً بك', $source, $staffId);
    }

    private function adminAlerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->count();
    }

    public function test_190_stores_the_four_columns_and_an_arabic_reason(): void
    {
        $this->failWith('error_190.json', 401);

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['failed', 190, 'TOKEN_INVALID', 'auth', 'Ax1TokenExpired000000AA'],
            [$row->status, $row->meta_code, $row->error_code, $row->error_category, $row->fbtrace_id]);
        $this->assertStringNotContainsString('OAuth', $row->error_message);
        $this->assertStringNotContainsString('Session has expired', $row->error_message);
        $this->assertSame('رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.', $row->error_message);

        $this->assertFalse($result['ok']);
        $this->assertSame(['TOKEN_INVALID', 'auth', false, 'channel', 190], [$result['code'], $result['category'], $result['retryable'], $result['scope'], $result['meta_code']]);
        $this->assertNotSame('', $result['action']);
    }

    public function test_details_are_kept_masked_in_the_payload_only(): void
    {
        $body = $this->fixture('error_131026.json');
        $body['error']['error_data']['details'] = 'Recipient 16505551234 cannot be reached';
        $this->fakeGraph(['graph.facebook.com/*' => Http::response($body, 400)]);

        $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['RECIPIENT_UNREACHABLE', 'Recipient ••• cannot be reached'], [data_get($row->payload, '_meta_error.code'), data_get($row->payload, '_meta_error.details')]);
        $this->assertStringNotContainsString('16505551234', (string) $row->error_message);
    }

    public function test_repeated_190_alerts_once_and_counts_on_the_channel(): void
    {
        $this->failWith('error_190.json', 401);

        $this->send();
        $this->send();
        $this->send();

        $this->assertSame(1, $this->adminAlerts(), 'مرة في الساعة لكل رمز');
        $last = WaBotSetting::current()->wa_last_error;
        $this->assertSame([190, 'TOKEN_INVALID', 'auth', 3], [$last['meta_code'], $last['error_code'], $last['category'], $last['count_24h']]);
        $this->assertNotEmpty($last['action']);

        $this->assertSame(190, WaBotSetting::current()->wa_last_error['meta_code']);
    }

    public function test_a_single_customer_error_does_not_touch_the_channel_card(): void
    {
        WaBotSetting::whereKey(WaBotSetting::current()->id)->update(['wa_last_error' => json_encode(['meta_code' => 190, 'error_code' => 'TOKEN_INVALID', 'at' => now()->toIso8601String(), 'count_24h' => 1])]);
        $this->failWith('error_131026.json');

        $this->send();

        $this->assertSame(190, WaBotSetting::current()->wa_last_error['meta_code'], 'خطأ عميل واحد لا يمس آخر خطأ القناة');
    }

    public function test_368_suspends_with_a_safe_audit_row(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 368, 'message' => 'Temporarily blocked for policies violations', 'fbtrace_id' => 'Ax9']], 400)]);

        $this->send();

        $s = WaBotSetting::current();
        $this->assertTrue($s->wa_suspended);
        $this->assertSame('meta_event', $s->wa_suspended_source);
        $this->assertSame(1, AuditLog::where('action', 'wa.suspend_auto')->count());
    }

    public function test_131031_alerts_but_does_not_suspend_by_default(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131031, 'message' => 'Account has been locked']], 400)]);

        $result = $this->send();

        $this->assertFalse(WaBotSetting::current()->wa_suspended);
        $this->assertSame(1, $this->adminAlerts());
        $this->assertSame('ACCOUNT_LOCKED', $result['code']);
    }

    public function test_unknown_code_counts_and_alerts_once_after_three(): void
    {
        $this->failWith('error_unknown_999999.json');

        $this->send();
        $this->send();
        $this->assertSame(0, $this->adminAlerts());
        $this->send();
        $this->send();

        $this->assertSame(1, $this->adminAlerts());
        $this->assertSame(4, (int) Cache::get('wa:meta:unknown:999999:'.now()->format('Ymd')));
        $this->assertSame('META_ERROR', WaMessage::latest('id')->first()->error_code);
        $this->assertSame('رفضت ميتا الرسالة (رمز 999999). أُبلغ المسؤول.', WaMessage::latest('id')->first()->error_message);
    }

    public function test_billing_and_unregistered_number_reach_the_channel_card(): void
    {
        // Http::fake يراكم الأنماط والأول يغلب: تسلسل لردّين مختلفين
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->fixture('error_131042.json'), 400)
            ->push($this->fixture('error_133010.json'), 400)]);
        $this->assertSame('BILLING_ISSUE', $this->send()['code']);
        $this->assertSame('BILLING_ISSUE', WaBotSetting::current()->wa_last_error['error_code']);

        $this->assertSame('CHANNEL_NOT_REGISTERED', $this->send()['code']);
        $this->assertSame('auth', WaBotSetting::current()->wa_last_error['category']);
    }

    public function test_pair_rate_limit_is_retryable_and_leaves_the_channel_alone(): void
    {
        $this->failWith('error_131056.json');

        $result = $this->send();

        $this->assertSame(['PAIR_RATE_LIMITED', 'throttle', true], [$result['code'], $result['category'], $result['retryable']]);
        $this->assertNull(WaBotSetting::current()->wa_last_error);
        $this->assertSame(0, $this->adminAlerts());
    }

    public function test_connection_failure_leaves_a_visible_row_without_leaking_the_url(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::failedConnection('cURL error 7: Failed to connect to graph.facebook.com port 443 after 3 ms: appsecret_proof=abc')]);

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['failed', 'HTTP_CONNECT_FAILED', 'transient', 'bot'], [$row->status, $row->error_code, $row->error_category, $row->source]);
        $this->assertSame('HTTP_CONNECT_FAILED', $result['code']);
        foreach ([$result['error'], $row->error_message] as $text) {
            $this->assertStringNotContainsString('graph.facebook.com', $text);
            $this->assertStringNotContainsString('appsecret_proof', $text);
            $this->assertStringNotContainsString('cURL', $text);
        }
    }

    public function test_timeout_is_unconfirmed_and_the_send_endpoint_answers_503(): void
    {
        $this->fakeGraph(['graph.facebook.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received')]);
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'موعدك غداً'])
            ->assertStatus(503)->assertJsonPath('code', 'HTTP_TIMEOUT')->assertJsonPath('category', 'transient')->assertJsonPath('retryable', false);

        $this->assertSame(['unconfirmed', 'HTTP_TIMEOUT'], [WaMessage::sole()->status, WaMessage::sole()->error_code]);
    }

    public function test_send_endpoint_returns_category_and_action_on_failure(): void
    {
        $this->failWith('error_131026.json');
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $conv = WaConversation::factory()->windowOpen()->create();

        $this->actingAs($staff)->postJson("/api/whatsapp/conversations/{$conv->id}/send", ['body' => 'مرحباً'])
            ->assertStatus(422)->assertJsonPath('category', 'caller')->assertJsonPath('code', 'RECIPIENT_UNREACHABLE')
            ->assertJsonPath('action', 'اطلب من العميل تحديث التطبيق أو تواصل معه بطريقة أخرى.');
    }

    public function test_local_locks_carry_their_category_without_a_row(): void
    {
        $this->fakeGraph();
        $result = WhatsAppChannel::sendText(WaConversation::factory()->create(['last_inbound_at' => now()->subDays(2)]), 'أهلاً');

        $this->assertSame(['WINDOW_EXPIRED', 'window', false], [$result['code'], $result['category'], $result['retryable']]);
        $this->assertSame(0, WaMessage::count());
        Http::assertNothingSent();
    }

    public function test_the_failure_log_line_carries_ids_only(): void
    {
        $this->captureLogs();
        $this->failWith('error_190.json', 401);

        $this->send();

        $line = collect($this->logged)->firstWhere('message', 'wa.send_failed');
        $this->assertNotNull($line);
        $this->assertSame([], array_keys(array_diff_key($line['context'], WaLog::filter($line['context']))), 'كل المفاتيح من القائمة المسموحة');
        $this->assertSame(['TOKEN_INVALID', 'auth', 190, 401], [$line['context']['error_code'], $line['context']['category'], $line['context']['meta_code'], $line['context']['http']]);
        $serialized = json_encode($this->logged, JSON_UNESCAPED_UNICODE);
        foreach (['EAA', 'Bearer', 'test-token', '16505551234', 'أهلاً بك', 'Session has expired'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized, $secret);
        }
    }

    public function test_shadow_mode_writes_the_classification_but_shows_the_old_text(): void
    {
        config(['features.env.meta_error_v2' => false]);
        $this->failWith('error_131026.json');

        $result = $this->send();

        $row = WaMessage::sole();
        $this->assertSame(['RECIPIENT_UNREACHABLE', 'caller'], [$row->error_code, $row->error_category]);
        $this->assertStringStartsWith('[131026] تعذّر التسليم', $row->error_message);
        $this->assertSame('UNDELIVERABLE', $result['code']);
        $this->assertArrayNotHasKey('category', $result, 'شكل النتيجة القديم في وضع الظل');
        $this->assertSame('RECIPIENT_UNREACHABLE', data_get($row->payload, '_meta_error.code'));
    }

    public function test_bot_note_never_carries_meta_text(): void
    {
        $code = (string) file_get_contents(app_path('Services/WhatsApp/BotTools.php'));

        $this->assertDoesNotMatchRegularExpression("/'note'\s*=>[^\n]*\\\$res\['error'\]/", $code);
    }
}

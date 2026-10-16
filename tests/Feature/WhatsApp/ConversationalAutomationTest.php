<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAutoRule;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ConversationalComponents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الأوامر وأسئلة البداية الرسمية (E37): تحقق كامل قبل أي نداء، ونداء واحد لميتا من الخادم عبر GraphClient، ورفض عند
 * الإيقاف الطارئ، وخطأ ميتا يُحفظ برمزه وفئته بلا رمز الوصول، والقراءة تقارن ما في واتساب بالمحفوظ.
 */
class ConversationalAutomationTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.conversational_components' => true]);
        $this->configureChannel();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings', 'whatsapp.auto_rules'], 'owner')->create();
    }

    private function desired(array $over = []): array
    {
        return $over + [
            'prompts' => ['أين موقع مكتبكم؟', 'ما هي مواعيد الدوام؟'],
            'commands' => [['command_name' => 'الموقع', 'command_description' => 'عنوان المكتب']],
        ];
    }

    private function sync(array $body)
    {
        return $this->actingAs($this->owner)->postJson('/api/whatsapp/conversational-automation/sync', $body);
    }

    public function test_sync_sends_one_official_request_and_stores_the_result(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        WaAutoRule::create(['name' => 'الموقع', 'is_active' => true, 'match_type' => 'exact', 'patterns' => ['/الموقع'], 'reply_text_ar' => 'مكتبنا في {company.address}']);
        $version = (int) WaBotSetting::current()->settings_version;

        $res = $this->sync($this->desired())->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://graph.facebook.com/v25.0/'.self::TEST_PHONE_NUMBER_ID.'/conversational_automation'
            && $r->data() === ['enable_welcome_message' => false, 'prompts' => ['أين موقع مكتبكم؟', 'ما هي مواعيد الدوام؟'],
                'commands' => [['command_name' => 'الموقع', 'command_description' => 'عنوان المكتب']]]);
        $stored = ConversationalComponents::stored();
        $this->assertSame($this->desired(), $stored['desired']);
        $this->assertNotNull($stored['synced_at']);
        $this->assertNull($stored['last_error']);
        $this->assertGreaterThan($version, (int) WaBotSetting::current()->fresh()->settings_version);
        // الأمر مربوط بقاعدة exact، والسؤالان بلا قاعدة: تحذير غير مانع
        $this->assertSame([true], $res->json('state.linked.commands'));
        $this->assertCount(2, $res->json('warnings'));
        $audit = AuditLog::where('action', AuditAction::WaConvAutomationSync->value)->sole();
        $this->assertSame(['ok' => true, 'prompts' => 2, 'commands' => 1, 'cleared' => false], array_intersect_key($audit->changes, array_flip(['ok', 'prompts', 'commands', 'cleared'])));
        $this->assertArrayHasKey('conversational_automation', $audit->changes['changed']);
    }

    public static function invalid(): array
    {
        return [
            'prompt of 81' => [['prompts' => [str_repeat('س', 81)]], 'prompts.0'],
            'command of 33' => [['commands' => [['command_name' => str_repeat('a', 33), 'command_description' => 'x']]], 'commands.0.command_name'],
            'five prompts' => [['prompts' => ['أ؟', 'ب؟', 'ج؟', 'د؟', 'هـ؟']], 'prompts'],
            'emoji in prompt' => [['prompts' => ['أين موقعكم 📍']], 'prompts.0'],
            'leading slash' => [['commands' => [['command_name' => '/الموقع', 'command_description' => 'x']]], 'commands.0.command_name'],
            'description of 257' => [['commands' => [['command_name' => 'hours', 'command_description' => str_repeat('x', 257)]]], 'commands.0.command_description'],
            'thirty one commands' => [['commands' => array_map(fn ($i) => ['command_name' => "c{$i}", 'command_description' => 'x'], range(1, 31))], 'commands'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_lists_are_refused_before_any_call(array $over, string $field): void
    {
        Http::fake();

        $this->sync($this->desired($over))->assertStatus(422)->assertJsonValidationErrors($field);

        Http::assertNothingSent();
        $this->assertSame([], ConversationalComponents::stored()['desired']['prompts']);
    }

    public function test_the_kill_switch_refuses_without_a_call(): void
    {
        Http::fake();
        WaBotSetting::current()->forceFill(['wa_suspended' => true])->save();

        $this->sync($this->desired())->assertStatus(409)->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertSame('SENDING_SUSPENDED', ConversationalComponents::stored()['last_error']['code']);
    }

    public function test_a_meta_rejection_is_kept_by_code_without_the_token(): void
    {
        $this->captureLogs();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => '(#100) Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'fbtrace_id' => 'TRACE9',
        ]], 400)]);

        $this->sync($this->desired())->assertStatus(409)->assertJsonPath('ok', false);

        $error = ConversationalComponents::stored()['last_error'];
        $this->assertSame([100, 'TRACE9'], [$error['meta_code'], $error['fbtrace_id']]);
        $this->assertNotEmpty($error['code']);
        $this->assertSame([], ConversationalComponents::stored()['desired']['prompts'], 'المعتمد لا يتغير بلا مزامنة ناجحة');
        $everything = json_encode([$this->logged, AuditLog::all()->toArray(), WaBotSetting::current()->fresh()->conversational_automation], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('test-token', $everything);
        $this->assertStringNotContainsString('Invalid parameter', $everything);
        $this->assertLogged('warning', 'wa.conv_automation_failed', ['meta_code' => 100, 'error_code' => $error['code'], 'category' => $error['category'], 'fbtrace_id' => 'TRACE9', 'http' => 400]);
    }

    public function test_clearing_sends_empty_lists(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $this->sync($this->desired());

        $this->sync(['prompts' => [], 'commands' => []])->assertOk();

        Http::assertSent(fn (Request $r) => $r->data() === ['enable_welcome_message' => false, 'prompts' => [], 'commands' => []]);
        $this->assertSame(['prompts' => [], 'commands' => []], ConversationalComponents::stored()['desired']);
        $this->assertTrue(AuditLog::where('action', AuditAction::WaConvAutomationSync->value)->latest('id')->first()->changes['cleared']);
    }

    public function test_reading_compares_what_whatsapp_shows_with_what_we_saved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['success' => true])
            ->push(['conversational_automation' => ['enable_welcome_message' => false, 'prompts' => ['أين موقع مكتبكم؟', 'ما هي مواعيد الدوام؟'],
                'commands' => [['command_name' => 'الموقع', 'command_description' => 'عنوان المكتب']]], 'id' => self::TEST_PHONE_NUMBER_ID])
            ->push(['conversational_automation' => ['prompts' => ['سؤال قديم']], 'id' => self::TEST_PHONE_NUMBER_ID])]);
        $this->sync($this->desired());
        $version = (int) WaBotSetting::current()->fresh()->settings_version;

        $same = $this->actingAs($this->owner)->getJson('/api/whatsapp/conversational-automation')->assertOk();
        $this->assertTrue($same->json('same'));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), self::TEST_PHONE_NUMBER_ID.'?fields=conversational_automation'));

        $differs = $this->actingAs($this->owner)->getJson('/api/whatsapp/conversational-automation')->assertOk();
        $this->assertFalse($differs->json('same'));
        $this->assertSame(['سؤال قديم'], $differs->json('live.prompts'));
        $this->assertSame(['سؤال قديم'], ConversationalComponents::stored()['last_snapshot']['prompts']);
        $this->assertSame($version, (int) WaBotSetting::current()->fresh()->settings_version, 'القراءة لا ترفع نسخة الإعدادات');
    }

    public function test_the_flag_and_the_settings_permission_guard_both_routes(): void
    {
        Http::fake();
        $rulesOnly = User::factory()->withPermissions(['whatsapp.auto_rules'], 'rules-only')->create();
        $this->actingAs($rulesOnly)->postJson('/api/whatsapp/conversational-automation/sync', $this->desired())->assertForbidden();
        $this->actingAs($rulesOnly)->getJson('/api/whatsapp/conversational-automation')->assertForbidden();

        config(['features.env.conversational_components' => false]);
        $this->sync($this->desired())->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/whatsapp/conversational-automation')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_page_shows_the_panel_to_settings_holders_only(): void
    {
        $this->actingAs($this->owner)->get('/whatsapp/auto-rules')->assertOk()
            ->assertInertia(fn ($page) => $page->where('automation.enabled', true)->where('automation.desired.prompts', []));

        $rulesOnly = User::factory()->withPermissions(['whatsapp.auto_rules'], 'rules-only')->create();
        $this->actingAs($rulesOnly)->get('/whatsapp/auto-rules')->assertOk()->assertInertia(fn ($page) => $page->where('automation', null));
    }
}

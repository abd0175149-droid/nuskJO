<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\WaAdminNumber;
use App\Models\WaAutoRule;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AutoRuleEngine;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\ReplyRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** نطاق رد البوت (2026-11-06): في «pilot» يرد على المحادثات المعلَّمة أو هواتف القائمة وحدها، والباقي للموظفين */
class BotPilotScopeTest extends TestCase
{
    use BuildsWhatsAppStaff, ConfiguresWhatsApp, RefreshDatabase;

    private WaConversation $conv;

    private int $modelCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->fakeGateway();
        Http::fake(['generativelanguage.googleapis.com/*' => function () {
            $this->modelCalls++;

            return Http::response($this->geminiResponse('أهلاً بك، كيف أخدمك؟'));
        }]);
        $this->conv = WaConversation::factory()->create(['phone' => '962791112233', 'bot_enabled' => true, 'last_inbound_at' => now()]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'مرحبا، عندكم عمرة؟']);
    }

    protected function tearDown(): void
    {
        ReplyRun::clear();
        AutoRuleEngine::flush();
        parent::tearDown();
    }

    private function scope(string $scope, array $phones = []): void
    {
        WaBotSetting::current()->forceFill(['bot_scope' => $scope, 'bot_pilot_phones' => $phones ? json_encode($phones) : null])->save();
    }

    public function test_the_fresh_default_is_all_and_the_bot_replies(): void
    {
        $this->assertSame('all', WaBotSetting::current()->botScope());
        $this->assertTrue(BotEngine::reply($this->conv)['sent']);
        $this->assertSame(1, $this->modelCalls);
    }

    public function test_pilot_with_nobody_chosen_leaves_the_customer_to_staff(): void
    {
        $this->scope('pilot');

        $result = BotEngine::reply($this->conv);

        $this->assertSame(['sent' => false, 'reason' => 'not_in_pilot'], $result);
        $this->assertSame(0, $this->modelCalls);
        $this->assertSame([], $this->gatewaySends());
        $conv = $this->conv->fresh();
        $this->assertSame([true, 'not_in_pilot'], [$conv->needs_attention, $conv->attention_reason]);
        $this->assertFalse(BotEngine::willReply($conv));
    }

    public function test_a_listed_phone_gets_replies_whatever_its_written_form(): void
    {
        $this->scope('pilot', ['962791112233']);

        $this->assertTrue(BotEngine::reply($this->conv)['sent']);
        $this->assertSame(1, $this->modelCalls);
    }

    public function test_a_flagged_conversation_gets_replies(): void
    {
        $this->scope('pilot', ['962780000000']);
        $this->conv->forceFill(['bot_pilot' => true])->save();

        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);
    }

    public function test_admin_numbers_are_not_affected(): void
    {
        $this->scope('pilot');
        WaAdminNumber::factory()->withPermissions([])->create(['phone' => $this->conv->phone]);

        $result = BotEngine::reply($this->conv);

        $this->assertNotSame('not_in_pilot', $result['reason'] ?? null);
        $this->assertSame(1, $this->modelCalls);
    }

    public function test_auto_rules_do_not_answer_outside_the_pilot(): void
    {
        config(['features.env.auto_rules' => true]);
        WaBotSetting::current()->forceFill(['auto_rules_mode' => 'live'])->save();
        Setting::set('company_address', 'عمّان');
        $rule = WaAutoRule::create([
            'name' => 'الموقع', 'is_active' => true, 'match_type' => 'any_of', 'patterns' => ['موقعكم'],
            'reply_text_ar' => 'مكتبنا في {company.address}.', 'reply_text_en' => null, 'max_words' => 6, 'cooldown_minutes' => 360,
        ]);
        $rule->forceFill(['validated_at' => now()])->saveQuietly();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'وين موقعكم؟']);
        $this->scope('pilot');

        $this->assertSame('not_in_pilot', BotEngine::reply($this->conv)['reason']);
        $this->assertSame(0, WaMessage::where('conversation_id', $this->conv->id)->where('direction', 'out')->count());
    }

    public function test_the_scope_route_normalizes_phones_rejects_bad_ones_and_audits(): void
    {
        config(['audit.extended' => true]);
        $admin = $this->staffWith('pilot-admin', ['whatsapp.settings']);

        $this->actingAs($admin)->putJson('/api/whatsapp/bot-policy/scope', ['scope' => 'pilot', 'phones' => "0791112233\nأحمد"])
            ->assertStatus(422)->assertJsonPath('invalid', ['أحمد']);
        $this->assertSame('all', WaBotSetting::current()->botScope());

        $this->actingAs($admin)->putJson('/api/whatsapp/bot-policy/scope', ['scope' => 'pilot', 'phones' => "0791112233\n+962 79 111 2233\n00966501234567"])
            ->assertOk()->assertJsonPath('pilot.phones', ['962791112233', '966501234567']);
        $s = WaBotSetting::current();
        $this->assertSame('pilot', $s->botScope());
        $this->assertTrue($s->inBotPilot($this->conv));
        $this->assertTrue(AuditLog::where('action', 'wa.bot_pilot_update')->where('entity_type', 'wa_bot_settings')->exists());
    }

    public function test_the_conversation_toggle_needs_the_settings_permission_and_audits(): void
    {
        config(['audit.extended' => true]);
        $this->scope('pilot');
        $this->actingAs($this->staffWith('pilot-agent', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all']))
            ->postJson("/api/whatsapp/conversations/{$this->conv->id}/bot-pilot", ['on' => true])->assertForbidden();
        $this->assertFalse((bool) $this->conv->fresh()->bot_pilot);

        $this->actingAs($this->staffWith('pilot-lead', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.settings']))
            ->postJson("/api/whatsapp/conversations/{$this->conv->id}/bot-pilot", ['on' => true])->assertOk()->assertJsonPath('bot_pilot', true);
        $this->assertTrue((bool) $this->conv->fresh()->bot_pilot);
        $this->assertTrue(AuditLog::where('action', 'wa.bot_pilot_update')->where('entity_type', 'wa_conversation')->where('entity_id', $this->conv->id)->exists());
        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);
    }

    public function test_the_page_and_shared_props_show_the_pilot(): void
    {
        $this->scope('pilot', ['962791112233']);
        $this->conv->forceFill(['bot_pilot' => true])->save();

        $this->actingAs($this->staffWith('pilot-viewer', ['whatsapp.view', 'whatsapp.settings']))->get('/whatsapp/bot-permissions')->assertOk()
            ->assertInertia(fn ($p) => $p->where('pilot.scope', 'pilot')->where('pilot.phones', ['962791112233'])
                ->where('pilot.conversations.0.id', $this->conv->id)
                ->where('waBotPilot', ['scope' => 'pilot', 'phones' => 1, 'conversations' => 1]));
    }
}

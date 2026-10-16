<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\EnrollsSecondFactor;
use Tests\TestCase;

/**
 * رقم الإدارة وحالة مستخدمه (E27، العلم admin_number_mfa): الرقم الذي يعدّل مربوط بحساب ويشترط عامله الثاني،
 * وإلا فالمساعد موقوف لهذا الرقم برسالة واضحة مرة في اليوم، ولا يُخدم كعميل.
 */
class AdminNumberMfaTest extends TestCase
{
    use ConfiguresWhatsApp, EnrollsSecondFactor, RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.admin_number_mfa' => true]);
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    private function say(string $body): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => $body]);
    }

    private function save(User $actor, array $data, ?WaAdminNumber $existing = null)
    {
        $payload = $data + ['phone' => '+962791112233', 'name' => 'المدير', 'permissions' => ['kb.add'], 'is_active' => true];
        $this->actingAs($actor)->withSession($this->passedSession($actor));

        return $existing ? $this->put("/whatsapp/admin-numbers/{$existing->id}", $payload) : $this->post('/whatsapp/admin-numbers', $payload);
    }

    private function sentTexts(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages'))
            ->map(fn ($pair) => $pair[0]['text']['body'] ?? null)->filter()->values()->all();
    }

    public function test_a_number_with_write_tools_needs_a_linked_account_and_requires_its_second_factor(): void
    {
        $manager = User::factory()->withPermissions(['whatsapp.settings'], 'wa_manager')->create();
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'wa_manager')->create();

        $this->save($manager, ['user_id' => null])->assertSessionHasErrors('user_id');
        $this->assertSame(0, WaAdminNumber::count());

        // غير المدير لا يطفئ الشرط لرقم يعدّل
        $this->save($manager, ['user_id' => $owner->id, 'require_mfa' => false])->assertSessionHasNoErrors();
        $number = WaAdminNumber::sole();
        $this->assertTrue($number->require_mfa);
        $this->assertTrue(AuditLog::where('action', AuditAction::Create->value)->where('entity_type', 'wa_admin_number')->sole()->changes['require_mfa']);

        // المدير يطفئه بقرار، ويبقى أثره في صف التعديل
        $admin = User::factory()->admin()->create();
        $this->save($admin, ['user_id' => $owner->id, 'require_mfa' => false], $number)->assertSessionHasNoErrors();
        $this->assertFalse($number->fresh()->require_mfa);
        $this->assertFalse(AuditLog::where('action', AuditAction::Update->value)->where('entity_type', 'wa_admin_number')->sole()->changes['after']['require_mfa']);
    }

    public function test_read_only_numbers_and_the_flag_off_keep_todays_behaviour(): void
    {
        $manager = User::factory()->withPermissions(['whatsapp.settings'], 'wa_manager')->create();
        $this->save($manager, ['user_id' => null, 'permissions' => ['report.daily']])->assertSessionHasNoErrors();
        $this->assertFalse(WaAdminNumber::sole()->require_mfa);

        config(['features.env.admin_number_mfa' => false]);
        $this->save($manager, ['user_id' => null, 'phone' => '+962791112244'])->assertSessionHasNoErrors();
        $this->assertSame(2, WaAdminNumber::count());
    }

    public function test_the_assistant_stops_with_one_system_message_a_day_until_the_user_enrols(): void
    {
        $this->fakeGraph();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'wa_manager')->create();
        WaAdminNumber::factory()->withPermissions(['kb.view', 'kb.add'])->create(['phone' => $this->conv->phone, 'user_id' => $owner->id, 'require_mfa' => true]);

        $this->say('أضف معلومة');
        $first = BotEngine::reply($this->conv->fresh());
        $this->say('هل وصلت؟');
        $second = BotEngine::reply($this->conv->fresh());

        $this->assertSame([true, 'admin_mfa_blocked'], [$first['sent'], $first['reason']]);
        $this->assertFalse($second['sent']);
        $this->assertSame(['المساعد الإداري موقوف لهذا الرقم حتى يفعّل صاحبه التحقق بخطوتين في نُسك.'], $this->sentTexts());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertSame(1, AuditLog::where('action', AuditAction::WaAdminMfaBlocked->value)->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%المساعد الإداري موقوف%')->count());
        $this->assertFalse((bool) $this->conv->fresh()->needs_attention, 'الموظف ليس عميلاً ينتظر');

        // بعد تفعيل صاحبه التحقق بخطوتين يعود المساعد
        $this->enroll($owner);
        $this->fakeGemini('أهلاً، كيف أساعدك؟');
        $this->say('مرحبا');
        $this->assertTrue(BotEngine::reply($this->conv->fresh())['sent']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * عرض الفشل (E18، مع meta_error_v2): الخيط يحمل الرمز والفئة والإجراء، وfbtrace_id لصاحب whatsapp.settings وحده،
 * والصندوق يحمل سطر خطأ القناة للمدير وسطراً عاماً لغيره، وصفحة الإعدادات بطاقة آخر خطأ. والعلم مطفأ: لا شيء جديد.
 */
class MetaErrorDisplayTest extends TestCase
{
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.meta_error_v2' => true, 'features.env.inbox_v2' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => 'failed', 'meta_code' => 190,
            'error_code' => 'TOKEN_INVALID', 'error_category' => 'auth', 'fbtrace_id' => 'Ax1', 'error_message' => 'رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.']);
    }

    private function staff(): User
    {
        return User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
    }

    private function channelError(): void
    {
        WaBotSetting::whereKey(WaBotSetting::current()->id)->update(['wa_last_error' => json_encode([
            'meta_code' => 190, 'error_code' => 'TOKEN_INVALID', 'category' => 'auth', 'message' => 'رمز ميتا منتهٍ',
            'action' => 'ولّد رمزاً جديداً', 'at' => now()->subHour()->toIso8601String(), 'count_24h' => 1,
        ], JSON_UNESCAPED_UNICODE)]);
    }

    public function test_thread_carries_reason_category_and_action(): void
    {
        $m = $this->actingAs($this->staff())->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk()->json('messages.0');

        $this->assertSame(['TOKEN_INVALID', 'auth'], [$m['error_code'], $m['category']]);
        $this->assertStringContainsString('System User', $m['action']);
        $this->assertArrayNotHasKey('fbtrace_id', $m, 'للدعم: لصاحب whatsapp.settings وحده');
    }

    public function test_the_trace_is_for_settings_holders_only(): void
    {
        $m = $this->actingAs(User::factory()->admin()->create())->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->json('messages.0');

        $this->assertSame('Ax1', $m['fbtrace_id']);
    }

    public function test_shadow_mode_adds_nothing_to_the_thread(): void
    {
        config(['features.env.meta_error_v2' => false]);

        $m = $this->actingAs(User::factory()->admin()->create())->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->json('messages.0');

        $this->assertSame([], array_intersect(array_keys($m), ['error_code', 'category', 'action', 'fbtrace_id']));
        $this->assertSame('رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.', $m['error']);
    }

    public function test_inbox_bar_details_for_the_admin_and_a_generic_line_for_staff(): void
    {
        $this->channelError();

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/inbox')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('status.meta_error.code', 'TOKEN_INVALID')->where('status.meta_error.action', 'ولّد رمزاً جديداً'));
        $this->actingAs($this->staff())->get('/whatsapp/inbox')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('status.meta_error', ['generic' => true]));
    }

    public function test_an_old_or_customer_level_error_raises_no_bar(): void
    {
        WaBotSetting::whereKey(WaBotSetting::current()->id)->update(['wa_last_error' => json_encode([
            'meta_code' => 190, 'error_code' => 'TOKEN_INVALID', 'category' => 'auth', 'at' => now()->subDays(2)->toIso8601String(),
        ])]);

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/inbox')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('status.meta_error', null));
    }

    public function test_settings_page_carries_the_last_error_card(): void
    {
        $this->channelError();

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/settings')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('metaErrors.last.error_code', 'TOKEN_INVALID')
            ->has('metaErrors.today.auth')
            ->where('metaErrors.proof.enabled', false));
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** صحة الإرسال من رقمنا كل ساعة: BLOCKED حرج، LIMITED أو جودة حمراء تحذير، بلا معرّفات الكيانات. */
class MetaChannelHealthTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.meta_checks' => true]);
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create();
    }

    private function health(string $overall, array $entities = [], string $quality = 'GREEN'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => self::TEST_PHONE_NUMBER_ID,
            'verified_name' => 'نسك الذهبية',
            'quality_rating' => $quality,
            'health_status' => ['can_send_message' => $overall, 'entities' => $entities ?: [
                ['entity_type' => 'PHONE_NUMBER', 'id' => self::TEST_PHONE_NUMBER_ID, 'can_send_message' => $overall],
                ['entity_type' => 'WABA', 'id' => '102290129340398', 'can_send_message' => 'AVAILABLE'],
            ]],
        ])]);
    }

    private function stored(): array
    {
        return WaBotSetting::current()->fresh()->wa_channel_health;
    }

    public function test_an_available_channel_is_ok(): void
    {
        $this->health('AVAILABLE');

        $this->artisan('whatsapp:health-status')->assertSuccessful();

        $this->assertSame('ok', $this->stored()['state']);
        $this->assertSame('GREEN', $this->stored()['quality_rating']);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_blocked_channel_is_critical_and_keeps_metas_solution_without_ids(): void
    {
        $this->health('BLOCKED', [[
            'entity_type' => 'WABA', 'id' => '102290129340398', 'can_send_message' => 'BLOCKED',
            'errors' => [['error_code' => 141010, 'error_description' => 'The Business has not passed business verification.', 'possible_solution' => 'Visit business settings and start verification.']],
        ]]);

        $this->artisan('whatsapp:health-status');

        $status = $this->stored();
        $this->assertSame('critical', $status['state']);
        $this->assertSame('WABA', $status['entities'][0]['entity_type']);
        $this->assertSame('Visit business settings and start verification.', $status['entities'][0]['errors'][0]['possible_solution']);
        $this->assertStringNotContainsString('102290129340398', json_encode($status));
        $this->assertSame(['⛔ ميتا أوقفت الإرسال من رقمنا'], Notification::where('user_id', $this->admin->id)->pluck('title')->all());
        $this->assertFalse(WaBotSetting::current()->fresh()->wa_suspended);
    }

    public function test_a_limited_channel_warns_with_metas_additional_info(): void
    {
        $this->health('LIMITED', [[
            'entity_type' => 'PHONE_NUMBER', 'id' => self::TEST_PHONE_NUMBER_ID, 'can_send_message' => 'LIMITED',
            'additional_info' => ['Your phone number has reached its 250 messaging limit.'],
        ]]);

        $this->artisan('whatsapp:health-status');

        $this->assertSame('warn', $this->stored()['state']);
        $this->assertSame(['Your phone number has reached its 250 messaging limit.'], $this->stored()['entities'][0]['additional_info']);
    }

    public function test_a_red_quality_rating_warns(): void
    {
        $this->health('AVAILABLE', [], 'RED');

        $this->artisan('whatsapp:health-status');

        $this->assertSame(['quality_red'], $this->stored()['reasons']);
        $this->assertSame(['⚠️ جودة رقم الواتساب حمراء'], Notification::where('user_id', $this->admin->id)->pluck('title')->all());
    }
}

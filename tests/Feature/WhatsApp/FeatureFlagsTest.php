<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Support\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أعلام الميزات (E10): البيئة ثم المحفوظ ثم الافتراض (مطفأ دائماً)، والميزة غير المشحونة لا تعمل ولا تُبدَّل،
 * وكل تبديل بسبب ويُدقَّق.
 */
class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FeatureFlags::fake(null);
        parent::tearDown();
    }

    private function shipped(): void
    {
        FeatureFlags::fake(['demo_flag' => ['label' => 'ميزة تجريبية', 'capability' => 'CXXX', 'available' => true]]);
    }

    public function test_every_real_flag_defaults_off_and_has_an_environment_entry(): void
    {
        foreach (FeatureFlags::DEFINITIONS as $key => $definition) {
            $this->assertFalse(FeatureFlags::on($key), $key);
            $this->assertArrayHasKey($key, config('features.env'), "config/features.php بلا {$key}");
        }

        // كل FeatureFlags::on('…') في الكود معرّف
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (str_ends_with($file->getPathname(), '.php')) {
                preg_match_all("/FeatureFlags::on\\('([a-z_]+)'\\)/", file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $key) {
                    $this->assertArrayHasKey($key, FeatureFlags::DEFINITIONS, $file->getPathname());
                }
            }
        }
    }

    public function test_an_unknown_flag_throws_in_tests(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeatureFlags::on('no_such_flag');
    }

    public function test_precedence_is_environment_then_saved_then_default(): void
    {
        $this->shipped();
        $this->assertSame(['on' => false, 'source' => 'default'], FeatureFlags::state('demo_flag'));

        WaBotSetting::current()->update(['feature_flags' => ['demo_flag' => true]]);
        $this->assertTrue(FeatureFlags::on('demo_flag'));
        $this->assertSame('db', FeatureFlags::state('demo_flag')['source']);

        config(['features.env.demo_flag' => 'false']);
        $this->assertFalse(FeatureFlags::on('demo_flag'));
        $this->assertSame('env', FeatureFlags::state('demo_flag')['source']);
    }

    public function test_toggling_is_audited_and_refused_for_unshipped_or_environment_flags(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/flags/llm_circuit_breaker', ['state' => 'on', 'reason' => 'تجربة'])
            ->assertJsonValidationErrors('state');

        $this->shipped();
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/flags/demo_flag', ['state' => 'on', 'reason' => 'تجربة المرحلة الأولى'])->assertOk();
        $this->assertTrue(FeatureFlags::on('demo_flag'));
        $row = AuditLog::where('action', AuditAction::WaFlagToggle->value)->sole();
        $this->assertSame(['demo_flag', 'on'], [$row->changes['flag'], $row->changes['state']]);

        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/flags/demo_flag', ['state' => 'default', 'reason' => 'إرجاع الافتراض'])->assertOk();
        $this->assertSame('default', FeatureFlags::state('demo_flag')['source']);

        config(['features.env.demo_flag' => 'true']);
        $this->actingAs($owner)->patchJson('/api/whatsapp/settings/flags/demo_flag', ['state' => 'off', 'reason' => 'إطفاء'])->assertStatus(409);
    }
}

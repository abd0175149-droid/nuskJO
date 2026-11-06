<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\Settings\AppKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * أوامر المشغّل (E10): إعادة تشفير الأسرار بعد تدوير APP_KEY، وإقفال الترقية (تنظيف الأدوات المجهولة وإرجاع الموجّه
 * المجمّد). رمز التحقق من ميتا أُزيل مع ناقلها (2026-11-06). لا قيمة سرية ولا مفتاح في المخرجات أو التدقيق.
 */
class SettingsMaintenanceCommandsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private function useKeys(string $current, array $previous = []): void
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    public function test_reencrypt_moves_secrets_from_the_previous_key_to_the_current_one(): void
    {
        $old = 'base64:'.base64_encode(random_bytes(32));
        $new = 'base64:'.base64_encode(random_bytes(32));
        $this->useKeys($old);
        $this->configureChannel(['api_key' => 'AIzaRotatedKey00000000000000001', 'fallback_api_key' => 'sk-ant-rotated-fallback-000001']);
        $this->useKeys($new, [$old]);

        $this->assertFalse(AppKeys::decryptsWithCurrent(DB::table('wa_bot_settings')->value('api_key')));
        Artisan::call('whatsapp:reencrypt-secrets', ['--dry-run' => true]);
        $this->assertStringContainsString('بمفتاح سابق', Artisan::output());
        $this->assertFalse(AppKeys::decryptsWithCurrent(DB::table('wa_bot_settings')->value('api_key')), 'التجربة لا تكتب');

        $this->assertSame(0, Artisan::call('whatsapp:reencrypt-secrets'));
        $output = Artisan::output();

        $row = (array) DB::table('wa_bot_settings')->first();
        foreach (['api_key' => 'AIzaRotatedKey00000000000000001', 'fallback_api_key' => 'sk-ant-rotated-fallback-000001'] as $column => $value) {
            $this->assertTrue(AppKeys::decryptsWithCurrent($row[$column]), $column);
            $this->assertSame($value, AppKeys::current()->decryptString($row[$column]));
            $this->assertStringNotContainsString($value, $output);
        }
        $audit = AuditLog::where('action', AuditAction::AppKeyReencrypt->value)->sole();
        $this->assertSame([AppKeys::fingerprint($new), [AppKeys::fingerprint($old)]], [$audit->changes['current_fp'], $audit->changes['previous_fps']]);
        $this->assertStringNotContainsString(substr($new, 7, 20), json_encode($audit->toArray()));
        $this->assertSame(1, (int) WaBotSetting::current()->fresh()->settings_version, 'القيم نفسها: لا ترفع النسخة');
    }

    public function test_finalize_clears_unknown_tools_and_a_frozen_prompt(): void
    {
        $this->configureChannel(['tools_config' => ['save_note' => false, 'old_tool' => true], 'system_prompt' => BotEngine::DEFAULT_PROMPT]);

        Artisan::call('whatsapp:settings-finalize', ['--dry-run' => true, '--reset-prompt' => true]);
        $this->assertSame(['save_note' => false, 'old_tool' => true], WaBotSetting::current()->fresh()->tools_config, 'التجربة لا تكتب');

        $this->assertSame(0, Artisan::call('whatsapp:settings-finalize', ['--reset-prompt' => true]));

        $s = WaBotSetting::current()->fresh();
        $this->assertSame(['save_note' => false], $s->tools_config);
        $this->assertNull($s->system_prompt);

        $audit = AuditLog::where('action', AuditAction::WaSettingsFinalize->value)->sole();
        $this->assertSame(['unknown_tools_removed', 'prompt_reset'], $audit->changes['actions']);
        $this->assertSame(['system', 'artisan:whatsapp:settings-finalize'], [$audit->actor_type, $audit->actor_ref]);
        $this->assertStringNotContainsString('typed-by-hand', json_encode($audit->toArray()));
    }

    public function test_finalize_keeps_a_custom_prompt(): void
    {
        $this->configureChannel(['system_prompt' => 'موجّه مخصص للشركة']);

        Artisan::call('whatsapp:settings-finalize', ['--reset-prompt' => true]);

        $this->assertSame('موجّه مخصص للشركة', WaBotSetting::current()->fresh()->system_prompt);
    }
}

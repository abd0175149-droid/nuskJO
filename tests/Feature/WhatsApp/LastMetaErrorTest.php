<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** زر «تمّ الحل» لآخر خطأ من ميتا (E18): لصاحب whatsapp.settings وحده، يصفّر السطر ولا يرفع أي قفل، ويُدقَّق */
class LastMetaErrorTest extends TestCase
{
    use RefreshDatabase;

    private function seedLastError(): void
    {
        $s = WaBotSetting::current();
        WaBotSetting::whereKey($s->id)->update([
            'wa_suspended' => true,
            'wa_last_error' => json_encode(['meta_code' => 190, 'error_code' => 'TOKEN_INVALID', 'category' => 'auth', 'at' => now()->toIso8601String(), 'count_24h' => 2]),
        ]);
    }

    public function test_clearing_needs_the_settings_permission(): void
    {
        $this->seedLastError();
        $user = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'])->create();

        $this->actingAs($user)->postJson('/api/whatsapp/settings/last-error/clear')->assertForbidden();

        $this->assertNotNull(WaBotSetting::current()->wa_last_error);
    }

    public function test_clearing_resets_the_line_audits_and_keeps_the_suspension(): void
    {
        config(['audit.extended' => true]);
        $this->seedLastError();
        $versionBefore = WaBotSetting::current()->settings_version;

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/whatsapp/settings/last-error/clear')
            ->assertOk()->assertJsonPath('last_error', null);

        $s = WaBotSetting::current();
        $this->assertNull($s->wa_last_error);
        $this->assertTrue($s->wa_suspended, 'تمّ الحل لا يرفع الإيقاف');
        $this->assertSame($versionBefore, $s->settings_version, 'خارج SettingsWriter: لا رفع للنسخة');
        $log = AuditLog::where('action', 'wa.last_error_clear')->sole();
        $this->assertSame(['meta_code' => 190, 'error_code' => 'TOKEN_INVALID'], $log->changes);
    }
}

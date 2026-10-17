<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ReadReceipts;
use App\Services\WhatsApp\Settings\SettingsSchema;
use App\Support\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PatchesWhatsAppSettings;
use Tests\TestCase;

/**
 * الإعداد send_read_receipts (E35): ثلاث قيم في سجل الحقول، off افتراضياً، وحساس فيحتاج تأكيداً ويُدقَّق،
 * وقيمة مجهولة يرفضها الحفظ الجزئي. والعلم read_receipts متاح ومطفأ افتراضياً.
 */
class ReadReceiptsPolicyTest extends TestCase
{
    use PatchesWhatsAppSettings;
    use RefreshDatabase;

    public function test_the_field_is_a_sensitive_three_value_enum_defaulting_to_off(): void
    {
        $f = SettingsSchema::FIELDS['send_read_receipts'];

        $this->assertSame(['off', 'on_reply', 'on_open'], SettingsSchema::enumValues('send_read_receipts'));
        $this->assertSame('off', $f['default']);
        $this->assertTrue($f['patchable']);
        $this->assertTrue(SettingsSchema::isSensitive('send_read_receipts'));
        $this->assertSame('channel', $f['group']);
        $this->assertSame('إشعار القراءة ومؤشر الكتابة', $f['label']);
        $this->assertSame(ReadReceipts::MODES, SettingsSchema::enumValues('send_read_receipts'));
    }

    public function test_the_flag_is_available_and_off_by_default(): void
    {
        $this->assertTrue(FeatureFlags::available('read_receipts'));
        $this->assertSame(['on' => false, 'source' => 'default'], FeatureFlags::state('read_receipts'));
        $this->assertSame('off', ReadReceipts::mode());
    }

    public function test_patch_rejects_an_unknown_value_and_needs_confirmation(): void
    {
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        WaBotSetting::current();

        $this->patchSettings($owner, ['send_read_receipts' => 'always'], confirm: true)->assertStatus(422)->assertJsonValidationErrors('changes.send_read_receipts');
        $this->patchSettings($owner, ['send_read_receipts' => 'on_reply'])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame('off', WaBotSetting::current()->fresh()->send_read_receipts);

        $this->patchSettings($owner, ['send_read_receipts' => 'on_reply'], confirm: true)->assertOk()->assertJsonPath('settings.send_read_receipts', 'on_reply');
        $this->assertSame('on_reply', WaBotSetting::current()->fresh()->send_read_receipts);

        $audit = AuditLog::where('action', AuditAction::WaSettingsUpdate->value)->latest('id')->first();
        $this->assertSame(['from' => 'off', 'to' => 'on_reply'], $audit->changes['changed']['send_read_receipts']);

        // null يعيد الافتراضي off
        $this->patchSettings($owner, ['send_read_receipts' => null], confirm: true)->assertOk();
        $this->assertSame('off', WaBotSetting::current()->fresh()->send_read_receipts);
    }
}

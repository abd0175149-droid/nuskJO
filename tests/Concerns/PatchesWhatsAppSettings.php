<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Testing\TestResponse;

/** حفظ إعدادات البوت كما تفعل الصفحة (E10): PATCH جزئي بالنسخة الحالية، وإيقاف طارئ بسبب */
trait PatchesWhatsAppSettings
{
    protected function patchSettings(User $user, array $changes, bool $confirm = false, ?int $version = null): TestResponse
    {
        $version ??= (int) WaBotSetting::current()->fresh()->settings_version;

        return $this->actingAs($user)->patchJson('/whatsapp/settings', ['version' => $version, 'changes' => $changes] + ($confirm ? ['confirm' => true] : []));
    }

    protected function killSwitch(User $user, string $state, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/whatsapp/settings/kill-switch', $extra + [
            'state' => $state,
            'reason' => 'سبب مكتوب للاختبار',
        ] + ($state === 'off' ? ['confirm_text' => 'استئناف'] : []));
    }
}

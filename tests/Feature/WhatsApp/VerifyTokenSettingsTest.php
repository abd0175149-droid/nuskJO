<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** رمز التحقق: 16 حرفاً على الأقل عند تغييره، دون منع حفظ بقية الإعدادات برمز قديم قصير. */
class VerifyTokenSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        WaBotSetting::current()->update(['wa_verify_token' => 'short-old']);
    }

    public function test_a_new_short_token_is_refused_with_an_arabic_message(): void
    {
        $this->actingAs($this->owner)->put('/whatsapp/settings', ['wa_verify_token' => 'tooshort'])
            ->assertSessionHasErrors(['wa_verify_token' => 'رمز التحقق 16 حرفاً على الأقل']);

        $this->assertSame('short-old', WaBotSetting::current()->wa_verify_token);
    }

    public function test_an_unchanged_short_token_does_not_block_other_settings(): void
    {
        $this->actingAs($this->owner)->put('/whatsapp/settings', ['wa_verify_token' => 'short-old', 'wa_suspended' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }

    public function test_a_long_new_token_is_saved(): void
    {
        $this->actingAs($this->owner)->put('/whatsapp/settings', ['wa_verify_token' => str_repeat('a1', 16)])->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('a1', 16), WaBotSetting::current()->wa_verify_token);
    }
}

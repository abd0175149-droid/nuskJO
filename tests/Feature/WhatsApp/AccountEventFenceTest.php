<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الويبهوك واحد للتطبيق كله: حدث جودة لرقم آخر أو حدث حساب لحساب آخر لا يوقف إرسالنا.
 * رقمنا وحسابنا يُتعلّمان من حمولة messages لرقمنا، وقبل ذلك يبقى الإيقاف الاحتياطي.
 */
class AccountEventFenceTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const OUR_DISPLAY = '15550783881';

    private const OUR_ACCOUNT = '102290129340398';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
    }

    private function fixture(string $name, array $replace = []): string
    {
        return strtr(file_get_contents($this->fixturePath($name)), $replace);
    }

    private function learnOurNumber(): void
    {
        $this->postMetaWebhook('text.json')->assertOk();
    }

    public function test_a_message_for_our_number_teaches_our_account_and_display_number(): void
    {
        $this->learnOurNumber();

        $settings = WaBotSetting::current();
        $this->assertSame(self::OUR_ACCOUNT, $settings->wa_waba_id);
        $this->assertSame(self::OUR_DISPLAY, $settings->wa_display_phone);
    }

    public function test_a_message_for_another_number_teaches_nothing(): void
    {
        $this->postMetaWebhook('foreign_phone_number_id.json')->assertOk();

        $this->assertNull(WaBotSetting::current()->wa_display_phone);
    }

    public function test_quality_warning_for_another_number_is_ignored(): void
    {
        $this->learnOurNumber();
        Log::spy();

        $this->postMetaWebhook('quality_update_other_number.json')->assertOk();

        $this->assertFalse(WaBotSetting::current()->wa_suspended);
        Log::shouldHaveReceived('warning')->with('WA account event for another number ignored', \Mockery::any());
    }

    public function test_quality_warning_for_our_number_suspends_sending(): void
    {
        $this->learnOurNumber();

        $this->postRawWebhook($this->fixture('quality_update_other_number.json', ['16505553333' => self::OUR_DISPLAY]))->assertOk();

        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }

    public function test_before_our_number_is_known_any_quality_warning_suspends(): void
    {
        $this->postMetaWebhook('quality_update_other_number.json')->assertOk();

        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }

    public function test_account_restriction_counts_only_for_our_account(): void
    {
        $this->learnOurNumber();

        $this->postRawWebhook($this->fixture('account_update_ban.json', ['"id": "'.self::OUR_ACCOUNT.'"' => '"id": "999999999999999"']))->assertOk();
        $this->assertFalse(WaBotSetting::current()->wa_suspended);

        $this->postMetaWebhook('account_update_ban.json')->assertOk();
        $this->assertTrue(WaBotSetting::current()->wa_suspended);
    }

    public function test_messages_without_a_phone_number_id_are_not_treated_as_ours(): void
    {
        $raw = json_encode(tap(json_decode($this->fixture('text.json'), true), function (&$payload) {
            unset($payload['entry'][0]['changes'][0]['value']['metadata']);
        }));

        $this->postRawWebhook($raw)->assertOk();

        $this->assertSame(0, WaConversation::count());
        Queue::assertNothingPushed();
    }

    public function test_changing_the_phone_number_id_forgets_what_was_learned(): void
    {
        $this->learnOurNumber();
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->put('/whatsapp/settings', ['wa_phone_number_id' => '200000000000001'])->assertSessionHasNoErrors();

        $settings = WaBotSetting::current();
        $this->assertNull($settings->wa_waba_id);
        $this->assertNull($settings->wa_display_phone);
    }
}

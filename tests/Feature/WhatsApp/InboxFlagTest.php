<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * الصندوق الجديد (E16) وحده منذ OpenWA (2026-11-06): القديم قام على نافذة الـ24 ساعة فأُزيل مع علمه inbox_v2، فلا
 * ?v2=1 ولا إعداد قديم باقٍ في البيئة يغيّران الصفحة ولا بياناتها.
 */
class InboxFlagTest extends TestCase
{
    use RefreshDatabase;

    private function pageOf(User $user, string $url = '/whatsapp/inbox'): array
    {
        $out = [];
        $this->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$out) {
            $out = $page->toArray();
        });

        return [$out['component'], array_keys($out['props'])];
    }

    public function test_the_flag_no_longer_switches_the_inbox_and_the_props_stay_the_same(): void
    {
        $admin = User::factory()->admin()->create();

        [$off, $offProps] = $this->pageOf($admin);
        config(['features.env.inbox_v2' => true]);
        [$on, $onProps] = $this->pageOf($admin);

        $this->assertSame(['WhatsApp/Inbox', 'WhatsApp/Inbox'], [$off, $on]);
        $this->assertEqualsCanonicalizing($offProps, $onProps);
        $this->assertContains('listMeta', $onProps);
    }

    public function test_staff_and_administrators_see_the_same_inbox(): void
    {
        $this->assertSame('WhatsApp/Inbox', $this->pageOf(User::factory()->admin()->create(), '/whatsapp/inbox?v2=1')[0]);

        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'])->create();
        $this->assertSame('WhatsApp/Inbox', $this->pageOf($staff, '/whatsapp/inbox?v2=1')[0]);
    }
}

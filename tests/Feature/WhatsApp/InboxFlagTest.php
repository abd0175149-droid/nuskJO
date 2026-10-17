<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * الصندوق الجديد (E16) خلف العلم inbox_v2، والقديم للتراجع بلا نشر، والبيانات نفسها في الحالتين.
 * والمدير وحده يجرّب الجديد قبل التفعيل بـ ?v2=1.
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

    public function test_the_flag_switches_between_the_two_inboxes_with_the_same_props(): void
    {
        $admin = User::factory()->admin()->create();

        [$legacy, $legacyProps] = $this->pageOf($admin);
        config(['features.env.inbox_v2' => true]);
        [$v2, $v2Props] = $this->pageOf($admin);

        $this->assertSame('WhatsApp/InboxLegacy', $legacy);
        $this->assertSame('WhatsApp/Inbox', $v2);
        $this->assertEqualsCanonicalizing($legacyProps, $v2Props);
        $this->assertContains('listMeta', $v2Props);
    }

    public function test_only_an_administrator_can_preview_the_new_inbox_before_the_flag(): void
    {
        $this->assertSame('WhatsApp/Inbox', $this->pageOf(User::factory()->admin()->create(), '/whatsapp/inbox?v2=1')[0]);

        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'])->create();
        $this->assertSame('WhatsApp/InboxLegacy', $this->pageOf($staff, '/whatsapp/inbox?v2=1')[0]);
    }
}

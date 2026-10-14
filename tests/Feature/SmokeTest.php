<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_admin_can_open_whatsapp_inbox(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/whatsapp/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('WhatsApp/Inbox', false));
    }

    public function test_public_legal_pages_state_backup_retention(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('6 أشهر')->assertSee('at most 6 months');
        $this->get('/data-deletion')->assertOk()->assertSee('لا تُستعاد إلى أنظمتنا العاملة', false);
    }
}

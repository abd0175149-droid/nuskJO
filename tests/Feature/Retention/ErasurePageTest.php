<?php

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaErasureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** صفحة طلبات الحذف (E13.4): للمدير بصلاحية whatsapp.erase، بلا رقم كامل، وبلا أي مسار يحذف */
class ErasurePageTest extends TestCase
{
    use RefreshDatabase;

    private WaErasureRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $this->request = WaErasureRequest::create(['status' => 'received', 'channel' => 'whatsapp', 'conversation_id' => $conv->id,
            'subject_hmac' => str_repeat('a', 64), 'received_at' => now(), 'due_at' => now()->addDays(30)]);
    }

    public function test_it_needs_the_erase_permission(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.view_all', 'whatsapp.settings'], 'staff')->create();

        $this->actingAs($staff)->get('/whatsapp/erasure-requests')->assertForbidden();
        $this->actingAs($staff)->post("/whatsapp/erasure-requests/{$this->request->id}/verify", ['verification_method' => 'in_person'])->assertForbidden();
    }

    public function test_the_page_shows_the_last_four_digits_only(): void
    {
        $admin = User::factory()->admin()->create();

        $res = $this->actingAs($admin)->get('/whatsapp/erasure-requests')->assertOk()
            ->assertInertia(fn ($page) => $page->component('WhatsApp/ErasureRequests', false)->where('requests.0.phone_tail', '1234'));

        $this->assertStringNotContainsString('16505551234', $res->getContent());
    }

    public function test_verify_and_reject_are_audited_and_follow_the_status(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post("/whatsapp/erasure-requests/{$this->request->id}/verify", ['verification_method' => 'teleport'])->assertSessionHasErrors('verification_method');
        $this->actingAs($admin)->post("/whatsapp/erasure-requests/{$this->request->id}/verify", ['verification_method' => 'same_number_whatsapp'])->assertRedirect();
        $this->assertSame(['verified', $admin->id], [$this->request->fresh()->status, $this->request->fresh()->verified_by]);
        $this->actingAs($admin)->post("/whatsapp/erasure-requests/{$this->request->id}/verify", ['verification_method' => 'in_person'])->assertStatus(409);

        $this->actingAs($admin)->post("/whatsapp/erasure-requests/{$this->request->id}/reject", ['reason_code' => 'withdrawn'])->assertRedirect();
        $this->assertSame('rejected', $this->request->fresh()->status);
        $this->assertSame(['wa.erasure_verify', 'wa.erasure_reject'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_no_http_route_executes_an_erasure(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'erasure'));

        $this->assertSame(['GET', 'POST', 'POST'], $routes->map(fn ($r) => $r->methods()[0])->values()->all());
        $this->assertSame([], $routes->filter(fn ($r) => preg_match('/forget|execute|run|delete/', $r->uri()))->values()->all());
    }
}

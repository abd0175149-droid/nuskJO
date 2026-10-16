<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaContactCard;
use App\Models\WaPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * إدارة المواقع والبطاقات المعتمدة (E33): للمدير (whatsapp.settings) وحده، والتحقق من المدى وhttps، ومكتب افتراضي واحد،
 * وموافقة صاحب الرقم إلزامية، والحذف تعطيل، وكل تغيير في التدقيق بهاتف مقنّع.
 */
class SharedItemsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.extended' => true]);
        $this->admin = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.settings'], 'manager')->create();
    }

    private function place(array $override = []): array
    {
        return $override + ['name' => 'مكتب نسك', 'address' => 'عمّان', 'latitude' => '31.9539', 'longitude' => '35.9106', 'kind' => 'office',
            'is_default' => true, 'bot_allowed' => true, 'is_active' => true];
    }

    private function card(array $override = []): array
    {
        return $override + ['label' => 'المندوب في جدة', 'formatted_name' => 'مندوب نسك', 'phone_e164' => '+16505553333', 'wa_id' => '16505553333',
            'url' => 'https://www.nusuk.sa', 'consent_note' => 'وافق كتابياً عبر البريد', 'consented_at' => now()->subDay()->toDateString(),
            'consent_confirmed' => true, 'bot_allowed' => false, 'is_active' => true];
    }

    public function test_only_settings_holders_reach_the_page_and_the_writes(): void
    {
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply'])->create();
        $place = WaPlace::create($this->place());

        $this->actingAs($staff)->get('/whatsapp/shared-items')->assertForbidden();
        $this->actingAs($staff)->post('/whatsapp/shared-items/places', $this->place())->assertForbidden();
        $this->actingAs($staff)->put("/whatsapp/shared-items/places/{$place->id}", $this->place(['name' => 'x']))->assertForbidden();
        $this->actingAs($staff)->delete("/whatsapp/shared-items/places/{$place->id}")->assertForbidden();
        $this->actingAs($staff)->post('/whatsapp/shared-items/cards', $this->card())->assertForbidden();
        $this->assertSame(1, WaPlace::count());
        $this->assertSame(0, WaContactCard::count());

        $this->actingAs($this->admin)->get('/whatsapp/shared-items')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('WhatsApp/SharedItems', false)->has('places', 1)->has('cards', 0));
    }

    public function test_out_of_range_coordinates_and_non_https_urls_are_refused(): void
    {
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/places', $this->place(['latitude' => '91']))->assertSessionHasErrors('latitude');
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/places', $this->place(['longitude' => '-181']))->assertSessionHasErrors('longitude');
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card(['url' => 'http://www.nusuk.sa']))->assertSessionHasErrors('url');
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card(['phone_e164' => '0790000000']))->assertSessionHasErrors('phone_e164');

        $this->assertSame([0, 0], [WaPlace::count(), WaContactCard::count()]);
    }

    public function test_only_one_default_office_after_saving(): void
    {
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/places', $this->place(['name' => 'المكتب القديم']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/places', $this->place(['name' => 'المكتب الجديد']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/places', $this->place(['name' => 'فندق', 'kind' => 'hotel']))->assertSessionHasNoErrors();

        $this->assertSame(['المكتب الجديد'], WaPlace::where('is_default', true)->pluck('name')->all(), 'الفندق لا يكون افتراضياً');
        $this->assertSame('المكتب الجديد', WaPlace::defaultOffice()?->name);

        $old = WaPlace::where('name', 'المكتب القديم')->sole();
        $this->actingAs($this->admin)->put("/whatsapp/shared-items/places/{$old->id}", $this->place(['name' => 'المكتب القديم']))->assertSessionHasNoErrors();
        $this->assertSame(['المكتب القديم'], WaPlace::where('is_default', true)->pluck('name')->all());
    }

    public function test_a_card_needs_the_owner_consent(): void
    {
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card(['consent_confirmed' => false]))->assertSessionHasErrors('consent_confirmed');
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card(['consent_note' => '']))->assertSessionHasErrors('consent_note');
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card(['consented_at' => '']))->assertSessionHasErrors('consented_at');
        $this->assertSame(0, WaContactCard::count());

        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card())->assertSessionHasNoErrors();
        $this->assertSame('وافق كتابياً عبر البريد', WaContactCard::sole()->consent_note);
    }

    public function test_every_change_is_audited_with_a_masked_phone_and_delete_deactivates(): void
    {
        $this->actingAs($this->admin)->post('/whatsapp/shared-items/cards', $this->card())->assertSessionHasNoErrors();
        $card = WaContactCard::sole();
        $this->actingAs($this->admin)->put("/whatsapp/shared-items/cards/{$card->id}", $this->card(['phone_e164' => '+15550783881']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete("/whatsapp/shared-items/cards/{$card->id}")->assertSessionHasNoErrors();

        $rows = AuditLog::where('action', 'wa.card_change')->orderBy('id')->get();
        $this->assertSame(['create', 'update', 'deactivate'], $rows->map(fn ($r) => $r->changes['action'])->all());
        $this->assertSame(['+165…3333', '+155…3881'], [$rows[1]->changes['before']['phone_masked'], $rows[1]->changes['after']['phone_masked']]);
        $json = $rows->toJson();
        $this->assertStringNotContainsString('6505553333', $json);
        $this->assertStringNotContainsString('5550783881', $json);
        $this->assertSame([1, false], [WaContactCard::count(), WaContactCard::sole()->is_active], 'تعطيل لا حذف');

        $place = WaPlace::create($this->place());
        $this->actingAs($this->admin)->delete("/whatsapp/shared-items/places/{$place->id}")->assertSessionHasNoErrors();
        $this->assertSame([false, false], [$place->fresh()->is_active, $place->fresh()->is_default]);
        $this->assertSame(1, AuditLog::where('action', 'wa.place_change')->count());
    }
}

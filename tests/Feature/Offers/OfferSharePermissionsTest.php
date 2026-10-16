<?php

namespace Tests\Feature\Offers;

use App\Models\Offer;
use App\Models\User;
use App\Models\WaQrLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * صلاحيات المشاركة (E38.7): offers.view يعرض وينزّل، وoffers.update ينشئ ويحذف ويولّد ويطبع، والرمز مربوط بعرضه
 * (رمز عرض آخر 404)، والرموز العامة لـ whatsapp.settings وحدها، والعلم مطفأ 404. وSVG مرفق بـ nosniff من القرص الخاص.
 */
class OfferSharePermissionsTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private Offer $offer;

    private WaQrLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_qr_links' => true, 'features.env.offer_story_card' => true]);
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->offer = Offer::factory()->create();
        Storage::disk('public')->put('wa-qr/a.png', 'png-bytes');
        Storage::disk('local')->put('wa-qr/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->link = WaQrLink::create(['offer_id' => $this->offer->id, 'channel' => 'print', 'code' => 'P1', 'prefilled_message' => 'x',
            'status' => 'active', 'qr_png_path' => 'wa-qr/a.png', 'qr_svg_path' => 'wa-qr/a.svg', 'deep_link_url' => 'https://wa.me/message/P1']);
    }

    private function viewer(): User
    {
        return User::factory()->withPermissions(['offers.view'], 'offers-viewer')->create();
    }

    public function test_a_viewer_lists_and_downloads_but_cannot_change_anything(): void
    {
        Http::fake();
        $u = $this->viewer();
        $id = $this->offer->id;

        $this->actingAs($u)->getJson("/api/offers/{$id}/share")->assertOk()
            ->assertJsonPath('links.0.png_url', "/offers/{$id}/qr/{$this->link->id}/png")
            ->assertJsonPath('can.update', false);
        $this->actingAs($u)->get("/offers/{$id}/qr/{$this->link->id}/png")->assertOk();

        $this->actingAs($u)->postJson("/offers/{$id}/qr", ['channel' => 'print'])->assertForbidden();
        $this->actingAs($u)->deleteJson("/offers/{$id}/qr/{$this->link->id}")->assertForbidden();
        $this->actingAs($u)->postJson("/offers/{$id}/card-qr", ['link_id' => $this->link->id])->assertForbidden();
        $this->actingAs($u)->postJson("/offers/{$id}/story")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_code_is_reached_only_through_its_own_offer(): void
    {
        $admin = User::factory()->admin()->create();
        $other = Offer::factory()->create();

        $this->actingAs($admin)->deleteJson("/offers/{$other->id}/qr/{$this->link->id}")->assertNotFound();
        $this->actingAs($admin)->get("/offers/{$other->id}/qr/{$this->link->id}/png")->assertNotFound();
        $this->actingAs($admin)->postJson("/offers/{$other->id}/card-qr", ['link_id' => $this->link->id])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/whatsapp/qr-links/{$this->link->id}")->assertNotFound();
        $this->assertSame('active', $this->link->fresh()->status);
    }

    public function test_the_svg_is_an_attachment_with_nosniff(): void
    {
        $res = $this->actingAs($this->viewer())->get("/offers/{$this->offer->id}/qr/{$this->link->id}/svg")->assertOk();

        $this->assertStringStartsWith('attachment;', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/svg+xml', $res->headers->get('Content-Type'));
    }

    public function test_printing_a_code_on_the_card_touches_the_offer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->offer->forceFill(['card_path' => 'offer-cards/x.png', 'card_generated_at' => now()->addMinute()])->saveQuietly();
        $this->travel(5)->minutes();

        $this->actingAs($admin)->postJson("/offers/{$this->offer->id}/card-qr", ['link_id' => $this->link->id])->assertOk();

        $this->assertSame($this->link->id, $this->offer->fresh()->card_qr_link_id);
        $this->assertTrue($this->offer->fresh()->cardIsStale(), 'البطاقة تُعاد بالرمز');
        $this->actingAs($admin)->postJson("/offers/{$this->offer->id}/card-qr", ['link_id' => null])->assertOk();
        $this->assertNull($this->offer->fresh()->card_qr_link_id);
    }

    public function test_general_codes_need_whatsapp_settings_and_the_flag(): void
    {
        $general = WaQrLink::create(['channel' => 'office', 'code' => 'G1', 'prefilled_message' => 'x', 'status' => 'active']);
        $desk = User::factory()->withPermissions(['offers.view', 'offers.update', 'whatsapp.view'], 'desk')->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($desk)->getJson('/api/whatsapp/qr-links')->assertForbidden();
        $this->actingAs($desk)->postJson('/api/whatsapp/qr-links', ['channel' => 'office'])->assertForbidden();
        $this->actingAs($desk)->deleteJson("/api/whatsapp/qr-links/{$general->id}")->assertForbidden();

        $this->actingAs($admin)->getJson('/api/whatsapp/qr-links')->assertOk()->assertJsonCount(1, 'links');
        config(['features.env.wa_qr_links' => false]);
        $this->actingAs($admin)->getJson('/api/whatsapp/qr-links')->assertNotFound();
        $this->actingAs($admin)->postJson("/offers/{$this->offer->id}/qr", ['channel' => 'print'])->assertNotFound();
    }

    public function test_an_admin_creates_a_code_from_the_share_tab(): void
    {
        Http::fake([
            'graph.facebook.com/*' => fn (Request $r) => Http::response(['code' => 'NEW1', 'prefilled_message' => $r->data()['prefilled_message'],
                'deep_link_url' => 'https://wa.me/message/NEW1', 'qr_image_url' => 'https://scontent.xx.fbcdn.net/q.png']),
            'scontent.xx.fbcdn.net/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
        ]);

        $this->actingAs(User::factory()->admin()->create())->postJson("/offers/{$this->offer->id}/qr", ['channel' => 'status', 'label' => 'حالة رجب'])
            ->assertOk()->assertJsonPath('link.status', 'active')->assertJsonPath('link.token_ok', true)
            ->assertJsonPath('link.deep_link_url', 'https://wa.me/message/NEW1');
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaPlace;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\Outbound\RichTypes;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * دبوس الموقع (E33، العلم wa_share_cards، C006): الإحداثيات من wa_places وحدها، وOutboundPolicy ترفض أي موقع لا يطابق
 * صفاً فعّالاً حرفياً. أداة البوت send_office_location مطفأة افتراضياً وبلا وسائط، والموقع الوارد برابط خرائط من أرقام.
 */
class ShareLocationTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private WaConversation $conv;

    private WaPlace $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_share_cards' => true]);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'body' => 'وين مكتبكم؟']);
        $this->office = WaPlace::create(['name' => 'مكتب نسك الذهبية', 'address' => 'عمّان، الشميساني', 'latitude' => 31.9539123,
            'longitude' => 35.9106456, 'kind' => 'office', 'is_default' => true, 'bot_allowed' => true]);
    }

    private function requests(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/messages'))->values()->all();
    }

    public function test_send_location_builds_the_payload_from_the_approved_row(): void
    {
        $this->fakeGraph();

        $res = WhatsAppChannel::sendLocation($this->conv, $this->office, 'bot', null, ['send_key' => 'reply:loc:1']);

        $this->assertTrue($res['ok'], json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['latitude' => '31.9539123', 'longitude' => '35.9106456', 'name' => 'مكتب نسك الذهبية', 'address' => 'عمّان، الشميساني'],
            $this->requests()[0]['location']);
        $row = WaMessage::where('direction', 'out')->sole();
        $this->assertSame(['location', '📍 مكتب نسك الذهبية', $this->office->id], [$row->msg_type, $row->body, data_get($row->payload, '_meta.place_id')]);
        $this->assertArrayNotHasKey('_meta', $this->requests()[0]->data());
    }

    public function test_the_policy_refuses_a_location_that_does_not_match_an_active_place(): void
    {
        $this->fakeGraph();
        RichTypes::register();
        $forged = OutboundMessage::custom('location', ['location' => ['latitude' => '21.4225', 'longitude' => '39.8262', 'name' => 'مكتب نسك الذهبية']]);

        $withMeta = WhatsAppChannel::send($this->conv, $forged, 'bot', null, ['send_key' => 'reply:loc:2', 'meta' => ['place_id' => $this->office->id]]);
        $withoutMeta = WhatsAppChannel::send($this->conv, $forged, 'bot', null, ['send_key' => 'reply:loc:3']);
        $this->office->update(['is_active' => false]);
        $inactive = WhatsAppChannel::send($this->conv, RichTypes::location($this->office), 'bot', null, ['send_key' => 'reply:loc:4', 'meta' => ['place_id' => $this->office->id]]);

        foreach ([$withMeta, $withoutMeta, $inactive] as $res) {
            $this->assertSame([false, 'POLICY_PLACE_MISMATCH'], [$res['ok'], $res['code']]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::where('direction', 'out')->count());
    }

    public function test_the_bot_tool_without_a_default_office_returns_sent_false(): void
    {
        $this->fakeGraph();
        $this->office->update(['is_default' => false]);

        $out = BotTools::execute('send_office_location', [], $this->conv);

        $this->assertSame(['sent' => false, 'note' => 'لا يوجد دبوس معتمد؛ اذكر العنوان النصي من بيانات الشركة فقط'], $out);
        Http::assertNothingSent();
    }

    public function test_the_bot_tool_sends_the_default_office_with_a_key(): void
    {
        config(['features.env.send_ledger' => true]);
        $this->fakeGraph();

        $out = BotTools::execute('send_office_location', [], $this->conv);

        $this->assertTrue($out['sent']);
        $this->assertStringEndsWith(':place'.$this->office->id, (string) WaMessage::where('msg_type', 'location')->sole()->send_key);
        $this->assertSame('31.9539123', $this->requests()[0]['location']['latitude']);
    }

    public function test_the_tool_is_absent_until_enabled_and_its_flag_is_on(): void
    {
        $names = fn (array $cfg) => array_column(BotTools::declarations(new WaBotSetting(['tools_config' => $cfg]), $this->conv), 'name');

        $this->assertNotContains('send_office_location', $names([]), 'مطفأة افتراضياً');
        $this->assertContains('send_office_location', $names(['send_office_location' => true]));
        config(['features.env.wa_share_cards' => false]);
        $this->assertNotContains('send_office_location', $names(['send_office_location' => true]), 'العلم يغلب tools_config');
        $this->assertFalse(BotTools::isEnabled(new WaBotSetting(['tools_config' => ['send_office_location' => true]]), 'send_office_location'));
    }

    public function test_a_staff_takeover_during_the_run_stops_the_pin(): void
    {
        $this->fakeGraph();
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
        $run = ReplyRun::start($this->conv, 'tok');
        ReplyRun::bind($run);
        try {
            WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $this->conv->id, 'body' => 'معك الموظف']);

            $out = BotTools::execute('send_office_location', [], $this->conv);
        } finally {
            ReplyRun::clear();
        }

        $this->assertSame(['sent' => false, 'note' => 'لا ترسل شيئاً آخر.'], $out);
        $this->assertSame('stale_takeover', $run->abort);
        Http::assertNothingSent();
    }

    public function test_the_staff_route_sends_an_active_place_and_refuses_others(): void
    {
        $this->fakeGraph();
        $staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $post = fn (array $body) => $this->actingAs($staff)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/whatsapp/conversations/{$this->conv->id}/send-location", $body);
        $hotel = WaPlace::create(['name' => 'فندق', 'latitude' => 21.4, 'longitude' => 39.8, 'kind' => 'hotel', 'is_active' => false]);

        $post(['place_id' => $this->office->id])->assertOk()->assertJson(['ok' => true]);
        $post(['place_id' => $hotel->id])->assertStatus(422)->assertJson(['code' => 'PLACE_INACTIVE']);
        config(['features.env.wa_share_cards' => false]);
        $post(['place_id' => $this->office->id])->assertStatus(422)->assertJson(['code' => 'FEATURE_OFF']);

        $this->assertCount(1, $this->requests());
        $this->assertSame($staff->id, WaMessage::where('msg_type', 'location')->sole()->staff_id);
    }

    public function test_an_inbound_location_gets_a_maps_link_built_from_numbers(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1760425530, config('app.timezone')));
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'last_inbound_at' => now()->subHour()]);
        $staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);

        $this->postMetaWebhook('location.json')->assertOk();

        $m = collect($this->actingAs($staff)->getJson("/api/whatsapp/conversations/{$conv->id}/messages")->assertOk()->json('messages'))->firstWhere('type', 'location');
        $this->assertSame(['lat' => 31.9539, 'lng' => 35.9106, 'name' => 'Sample Place', 'address' => 'Sample Street 1',
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query=31.9539,35.9106'], $m['location']);
    }
}

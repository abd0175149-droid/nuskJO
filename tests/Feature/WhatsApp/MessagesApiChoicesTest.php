<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ChoiceBuilder;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * الخيط يعرض ما عُرض على العميل وما اختاره (E36): العناوين بلا معرّفات وهل انتهت، والاختيار بصلاحيته وسببه بالعربية، ورد
 * نموذج التسعير برابط الطلب لمن يملك quotes.view. يرث سياج E04: محادثة خارج النطاق 404.
 */
class MessagesApiChoicesTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $staff;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'quotes.view']);
        $this->conv = WaConversation::factory()->windowOpen()->create();
        config(['features.env.inbox_v2' => true]);
    }

    private function thread(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertOk()->json();
    }

    /** صف صادر كما يحفظه الإرسال: الحمولة الرسمية ورقم المجموعة في _meta */
    private function sentMenu(bool $expired = false): WaMessage
    {
        $built = ChoiceBuilder::offersMenu($this->conv);
        $set = $built['set'];
        if ($expired) {
            $set->expires_at = now()->subMinute();
        }
        $set->save();
        $m = InteractiveTypes::list('اختر العرض المناسب', 'العروض', $built['sections']);

        return WaMessage::create(['conversation_id' => $this->conv->id, 'direction' => 'out', 'source' => 'bot', 'msg_type' => 'interactive',
            'body' => $m->displayBody(), 'status' => 'sent', 'wamid' => 'wamid.MENU.'.$set->id,
            'payload' => $m->payload() + ['_meta' => ['choice_set_id' => $set->id]]]);
    }

    public function test_outbound_choices_show_titles_without_ids(): void
    {
        Offer::factory()->create(['title' => 'عمرة الربيع']);
        $this->sentMenu();
        $this->sentMenu(expired: true);

        $thread = $this->thread();
        [$fresh, $old] = $thread['messages'];

        $this->assertSame(['type' => 'list', 'items' => [['title' => 'عمرة الربيع', 'description' => 'يبدأ من 220 د.أ للفرد']], 'expired' => false], $fresh['choices']);
        $this->assertTrue($old['choices']['expired']);
        $this->assertSame('اختر العرض المناسب', $fresh['body'], 'سطر العناوين يُعرض حبوباً لا نصاً');
        config(['features.env.inbox_v2' => false]);
        $this->assertSame("اختر العرض المناسب\n[قائمة: عمرة الربيع]", $this->thread()['messages'][0]['body'], 'الصندوق القديم يبقى على النص');
        $json = json_encode($thread, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ch:', $json);
        $this->assertStringNotContainsString('choice_set_id', $json);
        $this->assertArrayNotHasKey('payload', $fresh);
    }

    public function test_inbound_choices_carry_validity_and_an_arabic_reason(): void
    {
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'interactive', 'body' => 'عمرة الربيع',
            'payload' => ['_button_id' => 'ch:AAAAAAAAAAAAAAAA:r1', '_choice' => ['set_id' => 1, 'kind' => 'offers_menu', 'key' => 'r1', 'valid' => true, 'reason' => null, 'ref' => ['offer_id' => 3], 'label' => 'عمرة الربيع']]]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'interactive', 'body' => 'عمرة قديمة',
            'payload' => ['_choice' => ['set_id' => 1, 'kind' => 'offers_menu', 'key' => 'r2', 'valid' => false, 'reason' => 'superseded', 'ref' => [], 'label' => 'عمرة قديمة']]]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'interactive', 'body' => 'عمرة مخفية',
            'payload' => ['_choice' => ['set_id' => 1, 'kind' => 'offers_menu', 'key' => 'r3', 'valid' => false, 'reason' => 'offer_gone', 'ref' => [], 'label' => 'عمرة مخفية']]]);

        $m = collect($this->thread()['messages'])->keyBy('body');

        $this->assertSame(['label' => 'عمرة الربيع', 'valid' => true, 'reason_label' => null], $m['عمرة الربيع']['chosen']);
        $this->assertSame(['label' => 'عمرة قديمة', 'valid' => false, 'reason_label' => 'قائمة قديمة'], $m['عمرة قديمة']['chosen']);
        $this->assertSame('العرض لم يعد متاحاً', $m['عمرة مخفية']['chosen']['reason_label']);
    }

    public function test_a_flow_reply_links_its_request_for_quote_viewers_only(): void
    {
        $qr = QuoteRequest::create(['request_number' => 'Q-0042', 'conversation_id' => $this->conv->id, 'phone' => '', 'type' => 'flight', 'status' => 'new', 'pax_adults' => 1]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'interactive', 'body' => '📝 نموذج طلب تسعير: طيران',
            'payload' => ['_flow' => ['set_id' => 1, 'valid' => true, 'quote_request_id' => $qr->id, 'request_number' => 'Q-0042']]]);

        $form = $this->thread()['messages'][0]['quote_form'];
        $this->assertSame(['valid' => true, 'duplicate' => false, 'request_number' => 'Q-0042', 'url' => '/quote-requests?status=all&search=Q-0042'], $form);

        $noQuotes = $this->staffWith('desk2', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->assertArrayNotHasKey('url', $this->thread($noQuotes)['messages'][0]['quote_form']);
    }

    public function test_a_thread_outside_the_fence_is_not_found(): void
    {
        $specialist = $this->staffWith('hajj-desk', self::INBOX_PERMISSIONS, ['hajj']);
        $this->conv = $this->conversationTagged(['umrah']);
        Offer::factory()->create();
        $this->sentMenu();

        $this->actingAs($specialist)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages")->assertNotFound();
    }
}

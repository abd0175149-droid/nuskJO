<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\ChoiceBuilder;
use App\Services\WhatsApp\ReplyRun;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CapturesLogs;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * التحقق من النقرة (E36): المعرّف بصيغة الخادم، والمجموعة من المحادثة نفسها، وcontext.id رسالتها، وأحدث مجموعة من
 * نوعها، ولم تنتهِ، والعرض ما زال ظاهراً للبوت. النموذج يرى وصفاً مؤكداً أو سبب الرفض، وconfirm_booking يقدّم الاختيار
 * المؤكد، و«كلّم موظفاً» يحوّل بالكود.
 */
class ChoiceResolverTest extends TestCase
{
    use CapturesLogs;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    private Offer $offer;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $this->offer = Offer::factory()->create(['title' => 'عمرة الربيع']);
    }

    /** يحفظ مجموعة كما يفعل الإرسال، ومعها صف الرسالة الصادرة بمعرّف ميتا */
    private function sent(array $built, ?WaConversation $conv = null): WaChoiceSet
    {
        $conv ??= $this->conv;
        $set = $built['set'];
        $set->save();
        $wamid = 'wamid.MENU.'.(++$this->n);
        $row = WaMessage::create(['conversation_id' => $conv->id, 'direction' => 'out', 'source' => 'bot', 'msg_type' => 'interactive',
            'body' => 'اختر', 'status' => 'sent', 'wamid' => $wamid, 'payload' => ['_meta' => ['choice_set_id' => $set->id]]]);
        $set->forceFill(['wamid' => $wamid, 'message_id' => $row->id])->save();

        return $set;
    }

    private function click(string $id, ?string $context, string $title = 'عمرة الربيع', string $kind = 'list_reply'): WaMessage
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/interactive_list_reply.json')), true);
        $msg = &$payload['entry'][0]['changes'][0]['value']['messages'][0];
        $msg['id'] = 'wamid.IN.'.(++$this->n);
        $msg['timestamp'] = (string) now()->getTimestamp();
        $msg['interactive'] = ['type' => $kind, $kind => ['id' => $id, 'title' => $title]];
        if ($context === null) {
            unset($msg['context']);
        } else {
            $msg['context']['id'] = $context;
        }
        WhatsAppInbox::handleWebhook($payload);

        return WaMessage::where('wamid', $msg['id'])->sole();
    }

    private function row(WaChoiceSet $set, string $key = 'r1'): string
    {
        return ChoiceBuilder::id($set, $key);
    }

    public function test_a_valid_click_is_saved_with_the_confirmed_offer(): void
    {
        $set = $this->sent(ChoiceBuilder::offersMenu($this->conv));

        $in = $this->click($this->row($set), $set->wamid);

        $choice = $in->payload['_choice'];
        $this->assertTrue($choice['valid']);
        $this->assertSame(['offers_menu', 'r1', ['offer_id' => $this->offer->id], 'عمرة الربيع'], [$choice['kind'], $choice['key'], $choice['ref'], $choice['label']]);
        $this->assertSame('عمرة الربيع', $in->body, 'النص كما وصل من ميتا');
        $this->assertNotNull($set->fresh()->consumed_at);
    }

    public function test_invalid_clicks_carry_their_reason(): void
    {
        $old = $this->sent(ChoiceBuilder::offersMenu($this->conv));
        $other = WaConversation::factory()->windowOpen()->create();
        $foreign = $this->sent(ChoiceBuilder::offersMenu($other), $other);
        $expiring = $this->sent(ChoiceBuilder::categories($this->conv));
        $expiring->forceFill(['expires_at' => now()->subMinute()])->save();
        $hidden = $this->sent(ChoiceBuilder::bookingActions($this->conv, $this->offer));
        $new = $this->sent(ChoiceBuilder::offersMenu($this->conv));

        $reason = fn (WaMessage $m) => $m->payload['_choice']['reason'];
        $this->assertSame('no_context', $reason($this->click($this->row($new), 'wamid.SOMETHING.ELSE')));
        $this->assertSame('no_context', $reason($this->click($this->row($new), null)));
        $this->assertSame('foreign', $reason($foreign = $this->click($this->row($foreign), $foreign->wamid)));
        $this->assertNull($foreign->payload['_choice']['set_id'], 'لا يُحفظ رقم مجموعة محادثة أخرى');
        $this->assertSame('superseded', $reason($this->click($this->row($old), $old->wamid)));
        $this->assertSame('expired', $reason($this->click($this->row($expiring), $expiring->wamid)));
        $this->assertSame('unknown', $reason($this->click('ch:ZZZZZZZZZZZZZZZZ:r1', $new->wamid)));
        $this->assertSame('bad_key', $reason($this->click($this->row($new, 'r9'), $new->wamid)));

        $this->offer->update(['is_bot_visible' => false]);
        $this->assertSame('offer_gone', $reason($this->click(ChoiceBuilder::id($hidden, 'book'), $hidden->wamid, 'احجز هذا', 'button_reply')));
        $this->assertSame(0, WaChoiceSet::whereNotNull('consumed_at')->count());
    }

    public function test_the_context_is_matched_through_the_message_row_before_the_wamid_is_linked(): void
    {
        $set = $this->sent(ChoiceBuilder::offersMenu($this->conv));
        $wamid = $set->wamid;
        $set->forceFill(['wamid' => null])->save();

        $this->assertTrue($this->click($this->row($set), $wamid)->payload['_choice']['valid']);
    }

    public function test_other_button_ids_keep_their_old_path(): void
    {
        $in = $this->click('optout', null, 'إيقاف', 'button_reply');

        $this->assertArrayNotHasKey('_choice', $in->payload);
        $this->assertSame('optout', $in->payload['_button_id']);
    }

    public function test_the_model_sees_a_confirmed_description_not_the_raw_id(): void
    {
        $set = $this->sent(ChoiceBuilder::offersMenu($this->conv));
        $stale = $this->sent(ChoiceBuilder::offerOptions($this->conv, $this->offer));
        $this->sent(ChoiceBuilder::offerOptions($this->conv, $this->offer));
        $this->click($this->row($stale), $stale->wamid, 'الخيار 1');
        $this->click($this->row($set), $set->wamid);
        $this->fakeGraph();
        $this->fakeGemini('عرض ممتاز.');

        BotEngine::reply($this->conv->fresh());

        $prompt = json_encode(collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('[اختار من القائمة: عمرة الربيع — مرجع مؤكد: عرض رقم '.$this->offer->id.']', $prompt);
        $this->assertStringContainsString('[ضغط على خيار قديم لم يعد صالحاً (قائمة قديمة) — أعد عرض الخيارات الحالية]', $prompt);
        $this->assertStringNotContainsString('ch:', $prompt);
        $this->assertStringNotContainsString('[اختيار:', $prompt);
    }

    public function test_confirm_booking_takes_the_confirmed_choice_over_the_model_arguments(): void
    {
        $other = Offer::factory()->create(['title' => 'عمرة أخرى']);
        $ref = $this->offer->sellOptions()->first()->ref;
        $rooms = $this->sent(ChoiceBuilder::roomTypes($this->conv, $this->offer, $ref));
        $this->click($this->row($rooms), $rooms->wamid, 'ثنائية');
        $this->captureLogs();

        ReplyRun::bind(ReplyRun::start($this->conv, 't'));
        try {
            $out = BotTools::execute('confirm_booking', ['offer_id' => $other->id, 'option_ref' => 'مخترع', 'room_type' => 'رباعية', 'details' => 'أريد الحجز'], $this->conv->fresh());
        } finally {
            ReplyRun::clear();
        }

        $this->assertSame('عمرة الربيع', $out['offer']);
        $qr = QuoteRequest::sole();
        $this->assertSame($this->offer->id, $qr->offer_id);
        $this->assertSame('double', $qr->offer_selection['room']);
        $this->assertNotEmpty($qr->offer_selection['sel']);
        $this->assertStringContainsString('الغرفة: ثنائية', $qr->details);
        $this->assertLogged('info', 'wa.choice_override');
    }

    public function test_erasing_a_conversation_removes_its_choice_sets(): void
    {
        $this->fakeGraph();
        $this->sent(ChoiceBuilder::offersMenu($this->conv));
        $other = WaConversation::factory()->windowOpen()->create();
        $this->sent(ChoiceBuilder::offersMenu($other), $other);
        $eraser = app(ConversationEraser::class);

        $plan = $eraser->plan($this->conv, 'forget');
        $this->assertSame(1, $plan->counts['choice_sets']);
        $this->assertSame(1, $eraser->execute($plan)['choice_sets']);

        $this->assertSame([$other->id], WaChoiceSet::pluck('conversation_id')->all());
    }

    public function test_the_talk_to_staff_button_hands_off_without_calling_the_model(): void
    {
        $set = $this->sent(ChoiceBuilder::bookingActions($this->conv, $this->offer));
        $this->click(ChoiceBuilder::id($set, 'human'), $set->wamid, 'كلّم موظفاً', 'button_reply');
        $this->fakeGraph();
        $this->fakeGemini('لن يُستدعى');

        BotEngine::reply($this->conv->fresh());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertFalse($this->conv->fresh()->bot_enabled);
        $this->assertTrue($this->conv->fresh()->needs_attention);
    }
}

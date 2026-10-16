<?php

namespace Tests\Unit\WhatsApp;

use App\Models\Offer;
use App\Models\WaConversation;
use App\Services\WhatsApp\ChoiceBuilder;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use Database\Factories\OfferFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الخيارات من بيانات الخادم وحدها (E36): العناوين والأسعار من العرض وخياراته، بحدود ميتا وبلا UTF-8 مكسور، والعرض الذي
 * لا يظهر للبوت لا يظهر في القائمة، ولا قيمة داخلية تصل الحمولة.
 */
class ChoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    private function rows(array $built): array
    {
        return $built['sections'][0]['rows'];
    }

    public function test_the_offers_menu_keeps_at_most_ten_rows_in_price_order_with_per_person_prices(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Offer::factory()->create(['title' => "عرض {$i}"]);
        }
        $cheap = Offer::factory()->create(['title' => 'الأرخص']);
        $cheap->options()->first()->update(['prices' => ['double' => 150]]);

        $built = ChoiceBuilder::offersMenu($this->conv);

        $this->assertSame('list', $built['type']);
        $this->assertCount(10, $this->rows($built));
        $this->assertSame('الأرخص', $this->rows($built)[0]['title']);
        $this->assertSame('يبدأ من 150 د.أ للفرد', $this->rows($built)[0]['description']);
        $this->assertSame('يبدأ من 220 د.أ للفرد', $this->rows($built)[1]['description']);
        foreach ($this->rows($built) as $i => $row) {
            $this->assertMatchesRegularExpression('/^ch:[A-Za-z0-9]{16}:r'.($i + 1).'$/', $row['id']);
            $this->assertStringContainsString('للفرد', $row['description']);
        }
        $set = $built['set'];
        $this->assertFalse($set->exists, 'لا تُحفظ قبل الإرسال');
        $this->assertSame('offers_menu', $set->kind);
        $this->assertSame(['offer_id' => $cheap->id], $set->options[0]['ref']);
        $this->assertTrue($set->expires_at->lte($this->conv->last_inbound_at->copy()->addDay()));
    }

    public function test_a_long_arabic_title_is_cut_to_24_characters_with_valid_utf8(): void
    {
        Offer::factory()->create(['title' => 'عمرة رمضان المبارك الاقتصادية مع الإقامة في مكة والمدينة المنورة']);

        $title = $this->rows(ChoiceBuilder::offersMenu($this->conv))[0]['title'];

        $this->assertSame(24, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
        $this->assertTrue(mb_check_encoding($title, 'UTF-8'));
        $this->assertStringStartsWith('عمرة رمضان المبارك', $title);
    }

    public function test_an_offer_hidden_from_the_bot_is_left_out_and_visa_and_unpriced_offers_read_correctly(): void
    {
        Offer::factory()->create(['title' => 'مخفي', 'is_bot_visible' => false]);
        Offer::factory()->bare()->create(['title' => 'تأشيرة عمرة', 'category' => 'visa', 'price_per_person' => 40]);
        Offer::factory()->bare()->create(['title' => 'بلا سعر', 'category' => 'tour']);

        $rows = collect($this->rows(ChoiceBuilder::offersMenu($this->conv)))->keyBy('title');

        $this->assertFalse($rows->has('مخفي'));
        $this->assertSame('40 د.أ للفرد', $rows['تأشيرة عمرة']['description']);
        $this->assertSame('اسأل عن السعر', $rows['بلا سعر']['description']);
        $this->assertNull(ChoiceBuilder::offersMenu($this->conv, 'hajj'), 'تصنيف بلا عروض: لا قائمة');
        $this->assertNull(ChoiceBuilder::offersMenu($this->conv, 'غير موجود'));
    }

    public function test_categories_list_only_those_with_a_visible_offer(): void
    {
        Offer::factory()->count(2)->create();
        Offer::factory()->bare()->create(['category' => 'visa', 'price_per_person' => 40]);
        Offer::factory()->create(['category' => 'hajj', 'is_active' => false]);

        $built = ChoiceBuilder::categories($this->conv);

        $this->assertSame(['عمرة', 'تأشيرات'], array_column($this->rows($built), 'title'));
        $this->assertSame(['2 عروض متاحة', 'عرض واحد متاح'], array_column($this->rows($built), 'description'));
        $this->assertSame(['category' => 'umrah'], $built['set']->options[0]['ref']);
    }

    public function test_offer_options_and_room_types_come_from_the_sell_options(): void
    {
        $offer = Offer::factory()->create();
        $ref = $offer->sellOptions()->first()->ref;

        $options = ChoiceBuilder::offerOptions($this->conv, $offer);
        $this->assertSame('offer_option', $options['kind']);
        $this->assertSame('من 220 د.أ للفرد', $this->rows($options)[0]['description']);
        $this->assertSame(['offer_id' => $offer->id, 'option_ref' => $ref], $options['set']->options[0]['ref']);
        $this->assertSame($offer->title, $options['header']);

        $rooms = ChoiceBuilder::roomTypes($this->conv, $offer, $ref);
        $this->assertSame(['ثنائية', 'ثلاثية'], array_column($this->rows($rooms), 'title'));
        $this->assertSame('250 د.أ للفرد — غير نهائي', $this->rows($rooms)[0]['description']);
        $this->assertSame(['offer_id' => $offer->id, 'option_ref' => $ref, 'room' => 'double'], $rooms['set']->options[0]['ref']);

        $this->assertNull(ChoiceBuilder::roomTypes($this->conv, $offer, 'لا-يوجد'));
        $this->assertNull(ChoiceBuilder::offerOptions($this->conv, Offer::factory()->bare()->create(['category' => 'visa', 'price_per_person' => 40])));
    }

    public function test_booking_actions_are_three_fixed_buttons_and_drop_a_stale_option(): void
    {
        $offer = Offer::factory()->create();
        $ref = $offer->sellOptions()->first()->ref;

        $built = ChoiceBuilder::bookingActions($this->conv, $offer, $ref, 'quad');

        $this->assertSame('button', $built['type']);
        $this->assertSame(['احجز هذا', 'سؤال آخر', 'كلّم موظفاً'], array_column($built['buttons'], 'title'));
        $this->assertSame(['book', 'ask', 'human'], array_column($built['set']->options, 'key'));
        $this->assertStringEndsWith(':book', $built['buttons'][0]['id']);
        // الرباعية غير متاحة في هذا الخيار: تُسقط
        $this->assertSame(['offer_id' => $offer->id, 'option_ref' => $ref], $built['set']->options[0]['ref']);
        $this->assertSame(['offer_id' => $offer->id], ChoiceBuilder::bookingActions($this->conv, $offer, 'قديم')['set']->options[0]['ref']);
    }

    public function test_no_internal_offer_field_reaches_the_choices(): void
    {
        $offer = Offer::factory()->withCanaryInternals()->create(['title' => 'عمرة الحارس']);
        $ref = $offer->sellOptions()->first()->ref;

        $all = [
            ChoiceBuilder::offersMenu($this->conv), ChoiceBuilder::categories($this->conv), ChoiceBuilder::offerOptions($this->conv, $offer),
            ChoiceBuilder::roomTypes($this->conv, $offer, $ref), ChoiceBuilder::bookingActions($this->conv, $offer, $ref, 'double'),
        ];
        $json = json_encode(array_map(fn ($b) => ['set' => $b['set']->options] + array_diff_key($b, ['set' => 1]), $all), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('عمرة الحارس', $json);
        foreach ([(string) OfferFactory::CANARY_ROOM_RATE, (string) OfferFactory::CANARY_UNIT_COST, (string) OfferFactory::CANARY_AGENT_ID,
            (string) OfferFactory::CANARY_TOTAL_SEATS, 'CANARY'] as $canary) {
            $this->assertStringNotContainsString($canary, $json);
        }
    }

    public function test_duplicate_button_titles_are_told_apart_within_twenty_characters(): void
    {
        $m = InteractiveTypes::buttons('اختر', [
            ['id' => 'a', 'title' => 'فندق الصفوة المطل على الحرم'],
            ['id' => 'b', 'title' => 'فندق الصفوة المطل على الحرم'],
            ['id' => 'c', 'title' => 'فندق الصفوة المطل على الحرم'],
        ]);
        $titles = array_column(array_column($m->payload()['interactive']['action']['buttons'], 'reply'), 'title');

        $this->assertCount(3, array_unique($titles));
        $this->assertStringEndsWith(' 2', $titles[1]);
        $this->assertStringEndsWith(' 3', $titles[2]);
        foreach ($titles as $t) {
            $this->assertLessThanOrEqual(20, mb_strlen($t));
            $this->assertTrue(mb_check_encoding($t, 'UTF-8'));
        }
        $this->assertStringContainsString('[أزرار: ', $m->displayBody());
        $this->assertStringNotContainsString('ch:', $m->displayBody());
    }

    public function test_the_list_payload_follows_meta_limits_and_shows_titles_only(): void
    {
        $sections = [];
        for ($s = 1; $s <= 3; $s++) {
            $rows = [];
            for ($r = 1; $r <= 5; $r++) {
                $rows[] = ['id' => "ch:AAAAAAAAAAAAAAAA:r{$s}{$r}", 'title' => "صف {$s}-{$r}", 'description' => str_repeat('و', 100)];
            }
            $sections[] = ['rows' => $rows];
        }

        $m = InteractiveTypes::list(str_repeat('ن', 5000), str_repeat('زر', 20), $sections);
        $i = $m->payload()['interactive'];

        $this->assertSame(10, array_sum(array_map(fn ($s) => count($s['rows']), $i['action']['sections'])));
        $this->assertCount(2, $i['action']['sections']);
        $this->assertSame('خيارات', $i['action']['sections'][0]['title'], 'تعدّد الأقسام يشترط عنواناً');
        $this->assertSame(4096, mb_strlen($i['body']['text']));
        $this->assertSame(20, mb_strlen($i['action']['button']));
        $this->assertSame(72, mb_strlen($i['action']['sections'][0]['rows'][0]['description']));
        $this->assertNull(InteractiveTypes::list('نص', 'زر', []));
        $this->assertNull(InteractiveTypes::buttons('', [['id' => 'a', 'title' => 'أ']]));
    }
}

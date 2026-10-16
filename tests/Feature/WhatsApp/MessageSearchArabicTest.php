<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * البحث بالعربية على الفهرس (E30.3): الهمزات والتاء المربوطة وال التعريف والواو الملتصقة والتشكيل والأرقام العربية،
 * وكلمات صيغة FTS5 نصاً عادياً، وحرفان داخل محادثة واحدة عبر LIKE، والترتيب بالصلة، والعدد بسقف 1000.
 */
class MessageSearchArabicTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $manager;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        if (!FtsSchema::available()) {
            $this->markTestSkipped('SQLite '.FtsSchema::sqliteVersion().' بلا FTS5/trigram: MessageSearchFallbackTest يغطي LIKE');
        }
        config(['features.env.message_search' => true]);
        $this->manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $this->conv = $this->conversationTagged();
    }

    private function say(string $body, ?WaConversation $c = null): int
    {
        return WaMessage::factory()->create(['conversation_id' => ($c ?? $this->conv)->id, 'body' => $body])->id;
    }

    private function ids(array $params): array
    {
        $response = $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query($params))->assertOk();
        $this->assertSame('fts', $response->json('engine'));

        return array_column($response->json('hits'), 'message_id');
    }

    public function test_hamza_ta_marbuta_article_and_diacritics_fold(): void
    {
        $withArticle = $this->say('نريد العُمرة في رمضان');
        $bare = $this->say('سعر عمرة الشتاء');
        $this->say('رحلة سياحية');

        $this->assertEqualsCanonicalizing([$withArticle, $bare], $this->ids(['q' => 'العمره']));
        $this->assertEqualsCanonicalizing([$withArticle, $bare], $this->ids(['q' => 'عُمرة']));
    }

    public function test_attached_waw_and_names_with_hamza(): void
    {
        $hotel = $this->say('والفندق قريب من الحرم');
        $name = $this->say('أنا أسامة من عمّان');

        $this->assertSame([$hotel], $this->ids(['q' => 'فندق']));
        $this->assertSame([$name], $this->ids(['q' => 'اسامه']));
    }

    public function test_arabic_indic_digits_match_latin_ones(): void
    {
        $price = $this->say('السعر 150 ديناراً');
        $this->assertSame([$price], $this->ids(['q' => '١٥٠']));
    }

    public function test_fts_syntax_words_are_plain_text(): void
    {
        $this->say('عادي');
        $odd = $this->say('NOT or NEAR "x" * (all)');

        foreach (['NOT', 'NEAR', '"x"', '(all)', 'NOT NEAR', 'near*'] as $q) {
            $params = ['q' => $q];
            if (mb_strlen($q) < 3 || str_contains($q, '*')) {
                $params['conversation_id'] = $this->conv->id;
            }
            $response = $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query($params));
            $response->assertOk();
            if ($q !== 'near*') {
                $this->assertSame([$odd], array_column($response->json('hits'), 'message_id'), $q);
            }
        }
    }

    public function test_two_letters_work_inside_one_conversation_through_like(): void
    {
        $hit = $this->say('رح نسافر');
        $this->say('رح نسافر', $this->conversationTagged());

        $response = $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query(['q' => 'رح', 'conversation_id' => $this->conv->id]))->assertOk();
        $this->assertSame([$hit], array_column($response->json('hits'), 'message_id'));
        $this->assertSame('like', $response->json('engine'));

        $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=رح')->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_highlighted_segments_and_relevance_order(): void
    {
        $once = $this->say('سؤال عن التأشيرة');
        $twice = $this->say('تأشيرة وتأشيرة ثانية');

        $response = $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query(['q' => 'تاشيره', 'sort' => 'relevance']))->assertOk();

        $this->assertSame('relevance', $response->json('sort'));
        $this->assertSame([$twice, $once], array_column($response->json('hits'), 'message_id'));
        $this->assertSame([['t' => 'سؤال عن ال', 'hl' => false], ['t' => 'تأشيرة', 'hl' => true]], $response->json('hits.1.segments'));
        $this->assertIsInt($response->json('took_ms'));
    }

    public function test_filters_narrow_the_results(): void
    {
        $in = $this->say('موعد الطيران');
        $out = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'body' => 'موعد الطيران مؤكد'])->id;
        DB::table('wa_messages')->where('id', $in)->update(['created_at' => '2026-09-30 21:30:00']);   // 1 أكتوبر 00:30 بتوقيت عمّان

        $this->assertSame([$out], $this->ids(['q' => 'الطيران', 'direction' => 'out']));
        $this->assertSame([$in], $this->ids(['q' => 'الطيران', 'source' => 'customer']));
        $this->assertSame([$in], $this->ids(['q' => 'الطيران', 'date_from' => '2026-10-01', 'date_to' => '2026-10-01']));
        $this->assertSame([], $this->ids(['q' => 'الطيران', 'msg_type' => 'image,document']));
        $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=الطيران&msg_type=hack')->assertStatus(422);
    }

    public function test_the_total_is_capped_at_one_thousand(): void
    {
        $rows = [];
        foreach (range(1, 1001) as $i) {
            $rows[] = ['conversation_id' => $this->conv->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text',
                'body' => "حجز رقم {$i}", 'body_norm' => "حجز رقم {$i}", 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('wa_messages')->insert($chunk);
        }

        $response = $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=حجز&limit=50')->assertOk();
        $this->assertSame(1000, $response->json('total'));
        $this->assertTrue($response->json('total_capped'));
        $this->assertCount(50, $response->json('hits'));
        $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=حجز&offset=501')->assertStatus(422);
        $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=حجز&limit=51')->assertStatus(422);
    }
}

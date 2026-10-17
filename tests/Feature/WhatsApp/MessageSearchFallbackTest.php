<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Search\FtsSchema;
use App\Services\WhatsApp\Search\SearchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * بلا فهرس (SQLite بلا FTS5، أو triggers ناقصة، أو فهرس يرمي): البحث يعمل بـ LIKE على body_norm بالتطبيع نفسه
 * ويعيد engine=like. اختبار إلزامي على كل نسخة من SQLite.
 */
class MessageSearchFallbackTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use CapturesLogs;
    use RefreshDatabase;

    private User $manager;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.message_search' => true]);
        $this->manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        $this->conv = $this->conversationTagged();
    }

    protected function tearDown(): void
    {
        FtsSchema::fake(null);
        parent::tearDown();
    }

    private function search(array $params)
    {
        return $this->actingAs($this->manager)->getJson('/api/whatsapp/search?'.http_build_query($params))->assertOk();
    }

    public function test_like_finds_the_same_arabic_variants_without_the_index(): void
    {
        FtsSchema::fake(false);
        $a = WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'body' => 'نريد العُمرة'])->id;
        $b = WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'body' => 'والفندق قريب، والسعر ١٥٠'])->id;
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'body' => 'رحلة أخرى 100%']);

        $response = $this->search(['q' => 'العمره']);
        $this->assertSame('like', $response->json('engine'));
        $this->assertSame([$a], array_column($response->json('hits'), 'message_id'));
        $this->assertSame([$b], array_column($this->search(['q' => 'فندق 150'])->json('hits'), 'message_id'));
        $this->assertSame([], $this->search(['q' => '_%_'])->json('hits'), 'محارف LIKE تُهرَّب');
        $this->assertSame('recent', $this->search(['q' => 'فندق', 'sort' => 'relevance'])->json('sort'), 'الصلة مع الفهرس وحده');
    }

    public function test_a_failing_query_never_carries_the_search_text(): void
    {
        FtsSchema::fake(false);
        DB::statement('ALTER TABLE wa_messages RENAME COLUMN body_norm TO body_norm_gone');
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->manager)->getJson('/api/whatsapp/search?q=N7654321');
            $this->fail('البحث نجح على عمود غير موجود');
        } catch (SearchFailed $e) {
            $this->assertStringNotContainsString('N7654321', $e->getMessage());
            $this->assertStringNotContainsString('7654321', (string) $e);
        }
    }

    public function test_a_broken_index_falls_back_to_like_for_that_search(): void
    {
        if (!FtsSchema::available()) {
            $this->markTestSkipped('بلا فهرس أصلاً');
        }
        $hit = WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'body' => 'زيارة المدينة'])->id;
        FtsSchema::fake(true);
        FtsSchema::drop();
        $this->captureLogs();

        $response = $this->search(['q' => 'المدينه']);

        $this->assertSame('like', $response->json('engine'));
        $this->assertSame([$hit], array_column($response->json('hits'), 'message_id'));
        $this->assertLogged('warning', 'wa.search.fts_failed', ['reason' => 'QueryException']);
        $this->assertSame(1, DB::table('wa_messages')->count());
    }
}

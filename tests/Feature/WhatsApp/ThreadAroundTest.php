<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * القفز إلى رسالة والفلاتر في الخيط (E30.5، بقية C140): around يعيد 50 قبلها والرسالة و49 بعدها، والنزول بـ after
 * حتى has_more_after=false، والفلاتر على كل الأوضاع، ولا تصفير لغير المقروء، والرسالة من محادثة أخرى 404.
 */
class ThreadAroundTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $agent;

    private WaConversation $conversation;

    /** @var list<int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->staffWith('desk', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all']);
        $this->conversation = $this->conversationTagged([], ['unread_count' => 4]);

        $start = now()->subDays(3);
        $rows = [];
        for ($i = 1; $i <= 350; $i++) {
            $at = $start->copy()->addMinutes($i);
            $out = $i % 3 === 0;
            $rows[] = [
                'conversation_id' => $this->conversation->id, 'direction' => $out ? 'out' : 'in',
                'source' => $out ? ($i % 2 ? 'bot' : 'staff') : 'customer', 'msg_type' => $i % 10 === 0 ? 'image' : 'text',
                'body' => "m{$i}", 'status' => $out ? 'sent' : 'received', 'created_at' => $at, 'updated_at' => $at,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('wa_messages')->insert($chunk);
        }
        $this->ids = DB::table('wa_messages')->where('conversation_id', $this->conversation->id)->orderBy('id')->pluck('id')->all();
    }

    private function thread(array $query = [], ?WaConversation $c = null)
    {
        return $this->actingAs($this->agent)
            ->getJson('/api/whatsapp/conversations/'.($c ?? $this->conversation)->id.'/messages?'.http_build_query($query));
    }

    public function test_around_returns_a_window_centred_on_the_message(): void
    {
        $anchor = $this->ids[174];
        $response = $this->thread(['around' => $anchor])->assertOk();

        $this->assertSame(array_slice($this->ids, 124, 100), array_column($response->json('messages'), 'id'));
        $this->assertSame($anchor, $response->json('messages.50.id'));
        $response->assertJsonPath('has_more_before', true)->assertJsonPath('has_more_after', true)
            ->assertJsonPath('has_more', true)->assertJsonPath('detached', true)->assertJsonPath('anchor_id', $anchor)
            ->assertJsonPath('latest_id', end($this->ids))->assertJsonPath('filtered', false);
        $this->assertIsArray($response->json('notes'));
    }

    public function test_near_the_edges_the_window_is_not_detached(): void
    {
        $this->thread(['around' => $this->ids[340]])->assertOk()
            ->assertJsonPath('has_more_after', false)->assertJsonPath('detached', false)->assertJsonCount(60, 'messages');
        $this->thread(['around' => $this->ids[3]])->assertOk()
            ->assertJsonPath('has_more_before', false)->assertJsonPath('has_more_after', true)->assertJsonCount(53, 'messages');
    }

    public function test_paging_down_from_the_window_reaches_the_latest_without_gaps(): void
    {
        $window = $this->thread(['around' => $this->ids[100]])->json('messages');
        $seen = array_column($window, 'id');
        $last = end($seen);
        do {
            $page = $this->thread(['after' => $last, 'limit' => 100])->assertOk();
            $ids = array_column($page->json('messages'), 'id');
            $seen = [...$seen, ...$ids];
            $last = end($ids);
        } while ($page->json('has_more_after'));

        $this->assertSame(array_slice($this->ids, 50), $seen);
    }

    public function test_a_message_of_another_conversation_or_mixed_cursors_are_refused(): void
    {
        $other = $this->conversationTagged();
        $foreign = DB::table('wa_messages')->insertGetId(['conversation_id' => $other->id, 'direction' => 'in', 'source' => 'customer',
            'msg_type' => 'text', 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->thread(['around' => $foreign])->assertNotFound();
        $this->thread(['around' => 99999999])->assertNotFound();
        $this->thread(['around' => $this->ids[10], 'before' => $this->ids[20]])->assertStatus(422);
        $this->thread(['around' => $this->ids[10], 'after' => $this->ids[5]])->assertStatus(422);
        $this->thread(['around' => 'abc'])->assertStatus(422);
    }

    public function test_filters_apply_to_every_mode_and_mark_the_response(): void
    {
        $latest = $this->thread(['source' => 'bot'])->assertOk()->assertJsonPath('filtered', true);
        $this->assertSame(['bot'], array_values(array_unique(array_column($latest->json('messages'), 'source'))));

        $older = $this->thread(['source' => 'customer', 'msg_type' => 'image', 'before' => $this->ids[200]])->assertOk();
        $this->assertSame(['image'], array_values(array_unique(array_column($older->json('messages'), 'type'))));
        $this->assertTrue(collect($older->json('messages'))->every(fn ($m) => $m['id'] < $this->ids[200] && $m['direction'] === 'in'));

        $anchor = $this->ids[149];   // m150: صادرة من موظف، صورة
        $around = $this->thread(['around' => $anchor, 'direction' => 'in'])->assertOk();
        $others = collect($around->json('messages'))->reject(fn ($m) => $m['id'] === $anchor);
        $this->assertTrue($others->every(fn ($m) => $m['direction'] === 'in'));
        $this->assertSame($anchor, $around->json('anchor_id'));
        $this->assertContains($anchor, array_column($around->json('messages'), 'id'), 'الرسالة المقصودة تظهر ولو لم تطابق الفلتر');

        $this->thread(['source' => 'robot'])->assertStatus(422);
        $this->thread(['msg_type' => 'text,bogus'])->assertStatus(422);
    }

    public function test_date_filters_use_jordan_days(): void
    {
        DB::table('wa_messages')->where('id', $this->ids[0])->update(['created_at' => '2026-09-30 21:30:00']);   // 00:30 في 1 أكتوبر بعمّان
        DB::table('wa_messages')->where('id', $this->ids[1])->update(['created_at' => '2026-09-30 20:30:00']);   // 23:30 في 30 سبتمبر

        $response = $this->thread(['date_from' => '2026-10-01', 'date_to' => '2026-10-01'])->assertOk();
        $this->assertSame([$this->ids[0]], array_column($response->json('messages'), 'id'));
        $this->thread(['date_from' => '2026-10-02', 'date_to' => '2026-10-01'])->assertStatus(422);
    }

    public function test_after_honours_a_smaller_page_and_the_poll_default(): void
    {
        $page = $this->thread(['after' => $this->ids[300], 'limit' => 20])->assertOk();
        $this->assertCount(20, $page->json('messages'));
        $page->assertJsonPath('has_more_after', true)->assertJsonPath('gap', true);

        $tail = $this->thread(['after' => $this->ids[300]])->assertOk();
        $this->assertCount(49, $tail->json('messages'));
        $tail->assertJsonPath('has_more_after', false)->assertJsonPath('gap', false);
    }

    public function test_jumping_never_clears_unread(): void
    {
        $this->thread(['around' => $this->ids[100]])->assertOk();
        $this->thread(['around' => $this->ids[100], 'source' => 'customer'])->assertOk();
        $this->thread(['after' => $this->ids[150], 'limit' => 50])->assertOk();

        $this->assertSame(4, $this->conversation->fresh()->unread_count);
    }
}

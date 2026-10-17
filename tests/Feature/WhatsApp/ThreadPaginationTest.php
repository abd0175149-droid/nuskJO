<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * خيط الرسائل بمؤشر المعرّف: أحدث 100، ثم before للأقدم، و after مع since للجديد وتحديثات الحالة.
 * القراءة لا تصفّر غير المقروء؛ التصفير بطلب read صريح.
 */
class ThreadPaginationTest extends TestCase
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
        $this->conversation = $this->conversationTagged([], ['unread_count' => 6]);

        $start = now()->subHours(10);
        $rows = [];
        for ($i = 1; $i <= 350; $i++) {
            $at = $start->copy()->addSeconds($i);
            $out = $i % 3 === 0;
            $rows[] = [
                'conversation_id' => $this->conversation->id, 'direction' => $out ? 'out' : 'in',
                'source' => $out ? 'bot' : 'customer', 'msg_type' => 'text', 'body' => "m{$i}",
                'status' => $out ? 'sent' : 'received', 'created_at' => $at, 'updated_at' => $at,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('wa_messages')->insert($chunk);
        }
        $this->ids = DB::table('wa_messages')->where('conversation_id', $this->conversation->id)->orderBy('id')->pluck('id')->all();
    }

    private function thread(array $query = [])
    {
        return $this->actingAs($this->agent)
            ->getJson("/api/whatsapp/conversations/{$this->conversation->id}/messages?".http_build_query($query));
    }

    public function test_first_page_is_the_latest_hundred_in_order(): void
    {
        $response = $this->thread()->assertOk()->assertJsonPath('has_more', true);

        $this->assertSame(array_slice($this->ids, 250), array_column($response->json('messages'), 'id'));
        $this->assertSame($this->conversation->id, $response->json('conversation.id'));
        $this->assertSame(6, $response->json('conversation.unread'));
        $this->assertNotNull($response->json('synced_at'));
        $this->assertIsArray($response->json('notes'));
    }

    public function test_before_walks_back_until_the_start(): void
    {
        $page = $this->thread(['before' => $this->ids[250]])->assertOk()->assertJsonPath('has_more', true);
        $this->assertSame(array_slice($this->ids, 150, 100), array_column($page->json('messages'), 'id'));
        $this->assertArrayNotHasKey('notes', $page->json());

        $last = $this->thread(['before' => $this->ids[100]])->assertOk()->assertJsonPath('has_more', false);
        $this->assertSame(array_slice($this->ids, 0, 100), array_column($last->json('messages'), 'id'));
    }

    public function test_after_returns_new_messages_and_status_changes_since_the_last_sync(): void
    {
        $since = now()->subMinute()->format('Y-m-d H:i:s');
        $new = DB::table('wa_messages')->insertGetId([
            'conversation_id' => $this->conversation->id, 'direction' => 'in', 'source' => 'customer', 'msg_type' => 'text',
            'body' => 'جديدة', 'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldOut = $this->ids[2];   // m3: صادرة
        DB::table('wa_messages')->where('id', $oldOut)->update(['status' => 'failed', 'error_message' => 'rejected', 'updated_at' => now()]);

        $response = $this->thread(['after' => end($this->ids), 'since' => $since])->assertOk()->assertJsonPath('gap', false);

        $this->assertSame([$new], array_column($response->json('messages'), 'id'));
        $this->assertSame([['id' => $oldOut, 'status' => 'failed', 'error' => 'rejected']], $response->json('updates'));
    }

    public function test_after_reports_a_gap_when_more_arrived_than_one_poll_returns(): void
    {
        $response = $this->thread(['after' => $this->ids[100]])->assertOk()->assertJsonPath('gap', true);

        $this->assertCount(200, $response->json('messages'));
        $this->assertSame($this->ids[101], $response->json('messages.0.id'));
    }

    public function test_limit_is_capped_and_cursors_are_validated(): void
    {
        $this->assertCount(100, $this->thread(['limit' => 1000])->assertOk()->json('messages'));
        $this->assertCount(20, $this->thread(['limit' => 20])->assertOk()->json('messages'));

        $this->thread(['before' => 10, 'after' => 5])->assertStatus(422);
        $this->thread(['before' => 'x'])->assertStatus(422);
        $this->thread(['since' => 'not-a-date', 'after' => 1])->assertStatus(422);
    }

    public function test_reading_the_thread_never_clears_unread(): void
    {
        $this->thread()->assertOk();
        $this->thread(['before' => $this->ids[250]])->assertOk();
        $this->thread(['after' => end($this->ids)])->assertOk();

        $this->assertSame(6, $this->conversation->fresh()->unread_count);
    }

    public function test_mark_read_is_explicit_and_needs_the_reply_permission(): void
    {
        $viewer = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);

        $this->actingAs($viewer)->postJson("/api/whatsapp/conversations/{$this->conversation->id}/read")->assertForbidden();
        $this->assertSame(6, $this->conversation->fresh()->unread_count);

        $this->actingAs($this->agent)->postJson("/api/whatsapp/conversations/{$this->conversation->id}/read")->assertOk();
        $this->assertSame(0, $this->conversation->fresh()->unread_count);
    }
}

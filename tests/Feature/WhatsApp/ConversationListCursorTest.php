<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * «تحميل المزيد» في قائمة المحادثات (E16.6): مؤشر keyset على (last_message_at, id) تنازلياً، بصفحات من 50.
 * لا تكرار ولا فقد بين الصفحات حتى مع أوقات متساوية أو فارغة، والنطاق يُطبَّق قبل الترقيم.
 */
class ConversationListCursorTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    /** يجمع الصفحات كلها بتتبّع next_cursor؛ يعيد [المعرّفات، عدد الصفحات] */
    private function walk(User $user, array $params = []): array
    {
        $ids = [];
        $pages = 0;
        $cursor = null;
        do {
            $query = http_build_query($params + ($cursor ? ['cursor' => $cursor] : []));
            $page = $this->actingAs($user)->getJson('/api/whatsapp/conversations'.($query ? "?{$query}" : ''))->assertOk();
            $pages++;
            $rows = $page->json('conversations');
            $this->assertLessThanOrEqual(50, count($rows));
            array_push($ids, ...array_column($rows, 'id'));
            $cursor = $page->json('next_cursor');
            $this->assertSame($cursor !== null, $page->json('has_more'));
        } while ($cursor && $pages < 10);

        return [$ids, $pages];
    }

    /** ترتيب القائمة المتوقع: الأحدث أولاً، والتعادل بالمعرّف الأكبر، والفارغ آخراً */
    private function expectedOrder(): array
    {
        return WaConversation::query()->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')->pluck('id')->all();
    }

    public function test_pages_cover_every_conversation_once_with_ties_and_empty_times(): void
    {
        $base = now()->startOfMinute();
        // كل ثلاث محادثات بالوقت نفسه بالثانية، و6 بلا رسالة بعد (last_message_at فارغ)
        for ($i = 0; $i < 114; $i++) {
            WaConversation::factory()->create(['last_message_at' => $base->copy()->subMinutes(intdiv($i, 3))]);
        }
        WaConversation::factory()->count(6)->create(['last_message_at' => null]);

        [$ids, $pages] = $this->walk(User::factory()->admin()->create());

        $this->assertSame(3, $pages);
        $this->assertCount(120, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame($this->expectedOrder(), $ids);
    }

    public function test_a_tie_spanning_the_page_edge_is_not_lost(): void
    {
        // 60 محادثة بالثانية نفسها: الحدّ بين الصفحتين يقع داخل التعادل
        WaConversation::factory()->count(60)->create(['last_message_at' => now()->startOfMinute()]);

        $admin = User::factory()->admin()->create();
        $first = $this->actingAs($admin)->getJson('/api/whatsapp/conversations')->assertOk();
        $this->assertTrue($first->json('has_more'));
        $last = collect($first->json('conversations'))->last();
        $this->assertSame($last['sort_ts'].':'.$last['id'], $first->json('next_cursor'));

        [$ids] = $this->walk($admin);
        $this->assertSame($this->expectedOrder(), $ids);
    }

    public function test_paging_never_crosses_the_staff_scope(): void
    {
        $specialist = $this->staffWith('umrah-desk', self::INBOX_PERMISSIONS, ['umrah']);
        $visible = [];
        for ($i = 0; $i < 70; $i++) {
            // متداخلة بالوقت: المرئية وغير المرئية متناوبة
            $visible[] = $this->conversationTagged(['umrah'], ['last_message_at' => now()->subMinutes(2 * $i)])->id;
            $this->conversationTagged(['visa'], ['last_message_at' => now()->subMinutes(2 * $i + 1)]);
        }

        [$ids, $pages] = $this->walk($specialist);

        $this->assertSame(2, $pages);
        $this->assertEqualsCanonicalizing($visible, $ids);
        $this->assertCount(70, array_unique($ids));
    }

    public function test_cursor_respects_filters(): void
    {
        WaConversation::factory()->count(55)->create(['unread_count' => 2, 'last_message_at' => now()->subMinute()]);
        WaConversation::factory()->count(30)->create(['unread_count' => 0, 'last_message_at' => now()]);

        [$ids, $pages] = $this->walk(User::factory()->admin()->create(), ['filter' => 'unread']);

        $this->assertSame(2, $pages);
        $this->assertCount(55, array_unique($ids));
        $this->assertSame(55, WaConversation::whereIn('id', $ids)->where('unread_count', '>', 0)->count());
    }

    public function test_invalid_cursor_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['abc', '1:2:3', '12345678901:1', '-5:3', '1700000000:'] as $bad) {
            $this->actingAs($admin)->getJson('/api/whatsapp/conversations?cursor='.urlencode($bad))
                ->assertStatus(422)->assertJsonValidationErrors('cursor');
        }
    }

    public function test_first_page_keeps_its_shape_and_adds_paging_fields(): void
    {
        $conversation = WaConversation::factory()->windowOpen()->create(['last_message_at' => now()]);
        WaMessage::factory()->create(['conversation_id' => $conversation->id, 'msg_type' => 'text']);
        WaMessage::factory()->create(['conversation_id' => $conversation->id, 'msg_type' => 'image', 'body' => null]);
        $silent = WaConversation::factory()->create(['last_message_at' => now()->subHour()]);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/whatsapp/conversations')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null)
            ->assertJsonStructure(['conversations' => [['id', 'phone', 'name', 'preview', 'last_at', 'unread', 'window_open', 'topics', 'sort_ts', 'last_type']], 'unread_total', 'llm']);

        $rows = collect($response->json('conversations'))->keyBy('id');
        // نوع آخر رسالة (لأيقونة المعاينة)، ولا شيء لمحادثة بلا رسائل
        $this->assertSame('image', $rows[$conversation->id]['last_type']);
        $this->assertNull($rows[$silent->id]['last_type']);
        $this->assertSame($conversation->fresh()->last_message_at->timestamp, $rows[$conversation->id]['sort_ts']);
    }

    public function test_inbox_page_carries_list_meta(): void
    {
        WaConversation::factory()->count(51)->create(['last_message_at' => now()]);

        $this->actingAs(User::factory()->admin()->create())->get('/whatsapp/inbox')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 50)
                ->where('listMeta.has_more', true)
                ->whereType('listMeta.next_cursor', 'string'));
    }
}

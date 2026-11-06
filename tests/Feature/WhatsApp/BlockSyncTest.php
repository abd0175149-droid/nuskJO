<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Services\WhatsApp\BlockSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * المزامنة القرائية (E31.6؛ على البوابة منذ 2026-11-06): قائمة /contacts/blocked لكل رقم جاهز تُطابق بالحظر عندنا،
 * فيظهر ما لم تجده البوابة وما عندها بلا سجل، ولا يُرسل POST ولا DELETE أثناءها. وكل إصلاح بزر من مدير.
 */
class BlockSyncTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        config(['features.env.wa_block' => true, 'audit.extended' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    /** البوابة: قائمة المحظورين على الرقم الأول، وحظر/فك يقبل */
    private function fakeList(array $phones, int $status = 200): void
    {
        $this->fakeGateway([
            'nuskjo-openwa:2785/api/sessions/*/contacts/blocked' => Http::response($status === 200 ? ['data' => array_map(fn ($p) => ['id' => $p.'@c.us'], $phones)] : ['message' => 'x'], $status),
            'nuskjo-openwa:2785/api/sessions/*/contacts/*/block' => Http::response(['success' => true]),
        ]);
    }

    private function localBlock(string $phone, array $attrs = []): WaBlock
    {
        return WaBlock::create($attrs + ['phone' => $phone, 'reason_code' => 'spam', 'reason' => 'إزعاج متكرر طوال اليوم', 'blocked_at' => now()->subDay(), 'meta_state' => 'blocked']);
    }

    public function test_the_list_is_compared_without_changing_anything_at_the_gateway(): void
    {
        $this->fakeList(['16505551234', '16505553333']);
        $matched = $this->localBlock('16505551234');
        $gone = $this->localBlock('16505550000');
        $mismatch = $this->localBlock('16505553333', ['meta_state' => 'mismatch']);

        $r = BlockSync::run();

        $this->assertTrue($r['ok']);
        $this->assertSame([2, 3, false], [$r['meta'], $r['local'], $r['truncated']]);
        $this->assertSame([$gone->id], array_column($r['not_found'], 'id'));
        $this->assertSame([], $r['untracked']);
        $this->assertSame([[$mismatch->id, 'blocked']], array_map(fn ($m) => [$m['id'], $m['now']], $r['mismatch']));
        $this->assertSame(['blocked', 'not_found', 'blocked'], [$matched->fresh()->meta_state, $gone->fresh()->meta_state, $mismatch->fresh()->meta_state]);
        $this->assertNotNull($gone->fresh()->meta_checked_at);

        Http::assertNotSent(fn (Request $req) => $req->method() !== 'GET');
        $this->assertSame(1, BlockSync::lastSummary()['not_found']);
        $this->assertSame(['meta' => 2, 'local' => 3, 'not_found' => 1, 'untracked' => 0],
            array_intersect_key(AuditLog::where('action', 'wa.block_sync')->sole()->changes, array_flip(['meta', 'local', 'not_found', 'untracked'])));
    }

    public function test_a_number_blocked_at_the_gateway_without_a_record_is_shown_by_last_four_and_a_reference(): void
    {
        $this->fakeList(['16505553333']);
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => '16505553333', 'wa_phone' => '16505553333', 'display_name' => 'عميل بلا سجل',
            'channel_id' => $this->testChannel()->id]);

        $r = $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/sync')->assertOk()->json();

        $this->assertCount(1, $r['untracked']);
        $row = $r['untracked'][0];
        $this->assertSame(['3333', 'phone', 'عميل بلا سجل', $conv->id], [$row['last4'], $row['kind'], $row['name'], $row['conversation_id']]);
        $this->assertStringNotContainsString('16505553333', json_encode($r));

        // «تسجيل سبب»: لا نداء حظر، والمحادثة تصير محظورة عندنا
        $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/untracked/register', ['ref' => $row['ref'], 'reason_code' => 'fraud', 'reason' => 'محتال معروف حظرناه من قبل'])->assertOk();
        $block = WaBlock::sole();
        $this->assertSame(['meta_sync', 'blocked', $conv->id, '16505553333'], [$block->blocked_via, $block->meta_state, $block->conversation_id, $block->phone]);
        $this->assertNotNull($conv->fresh()->blocked_at);
        Http::assertNotSent(fn (Request $req) => $req->method() !== 'GET');
        // المرجع استُهلك
        $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/untracked/register', ['ref' => $row['ref'], 'reason_code' => 'fraud', 'reason' => 'محتال معروف حظرناه من قبل'])
            ->assertStatus(422)->assertJsonPath('code', 'SYNC_EXPIRED');
    }

    public function test_an_untracked_block_with_a_conversation_can_be_lifted_with_a_reason(): void
    {
        $this->fakeList(['16505553333']);
        WaConversation::factory()->create(['phone' => '16505553333', 'wa_phone' => '16505553333', 'channel_id' => $this->testChannel()->id]);
        $ref = $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/sync')->json('untracked.0.ref');

        $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/untracked/unblock', ['ref' => $ref, 'reason' => 'قرار المالك'])->assertOk();

        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && str_contains($req->url(), rawurlencode('16505553333@c.us').'/block'));
        $this->assertSame(1, AuditLog::where('action', 'wa.unblock')->count());
        $this->assertSame(0, WaBlock::count());
    }

    public function test_an_untracked_block_without_a_conversation_is_lifted_from_the_phone(): void
    {
        $this->fakeList(['16505553333']);
        $ref = $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/sync')->json('untracked.0.ref');

        $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/untracked/unblock', ['ref' => $ref, 'reason' => 'قرار المالك'])
            ->assertStatus(422)->assertJsonPath('code', 'NO_RECIPIENT');
        Http::assertNotSent(fn (Request $req) => $req->method() === 'DELETE');
    }

    public function test_a_disconnected_number_is_not_read_and_no_block_is_declared_missing_from_it(): void
    {
        $this->fakeList([]);
        $this->testChannel()->forceFill(['status' => 'disconnected'])->save();
        $local = $this->localBlock('16505550000');

        $r = BlockSync::run();

        $this->assertTrue($r['ok']);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), '/contacts/blocked'));
        $this->assertSame([0, true, []], [$r['meta'], $r['truncated'], $r['not_found']]);
        $this->assertSame('blocked', $local->fresh()->meta_state);
    }

    public function test_a_gateway_failure_changes_nothing(): void
    {
        $this->fakeList([], 401);
        $local = $this->localBlock('16505550000');

        $r = BlockSync::run();

        $this->assertFalse($r['ok']);
        $this->assertNull($local->fresh()->meta_checked_at);
        $this->assertNull(BlockSync::lastSummary());
    }

    public function test_the_daily_check_runs_behind_the_flag_and_alerts_when_differences_grow(): void
    {
        $this->fakeList([]);
        $this->localBlock('16505550000');

        config(['features.env.wa_block' => false]);
        $this->artisan('whatsapp:blocks-check')->assertSuccessful();
        Http::assertNothingSent();

        config(['features.env.wa_block' => true]);
        $this->artisan('whatsapp:blocks-check')->assertSuccessful();
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->count());
        $this->assertStringNotContainsString('16505550000', Notification::sole()->body);

        // لا جديد: لا إشعار ثانٍ
        $this->artisan('whatsapp:blocks-check')->assertSuccessful();
        $this->assertSame(1, Notification::count());
    }

    public function test_sync_is_limited_to_three_runs_in_ten_minutes(): void
    {
        $this->fakeList([]);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/sync')->assertOk();
        }
        $this->actingAs($this->admin)->postJson('/api/whatsapp/blocks/sync')->assertStatus(429);
    }
}

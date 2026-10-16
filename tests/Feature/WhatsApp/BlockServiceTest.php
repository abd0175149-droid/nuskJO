<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaBlock;
use App\Models\WaConversation;
use App\Models\WaOptout;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\BlockService;
use App\Services\WhatsApp\BlockSync;
use App\Support\Retention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * BlockService (E31.3): الحظر ذري بعد قبول ميتا وحده، بتدقيق صارم بخطورة warn، والمحادثة بلا بوت ولا «تدخّل».
 * رفض مسبق بلا نداء لمحادثات الإدارة والموظفين والمدموجة، وحظر واحد لنقرتين، وفك الحظر لا يعيد البوت،
 * ولا opt-out. و«حذف بياناتي» يجرّد الحظر ولا يحذفه (E31.10).
 */
class BlockServiceTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    private const REASON = 'رسائل مزعجة متكررة طوال اليوم';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->admin = User::factory()->admin()->create(['name' => 'مدير الحظر']);
    }

    private function fakeMeta(int $status = 200, ?array $body = null): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($body ?? json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_ok.json')), true), $status)]);
    }

    private function customer(array $attrs = []): WaConversation
    {
        return WaConversation::factory()->windowOpen()->create($attrs + [
            'phone' => '16505551234', 'wa_phone' => '16505551234', 'display_name' => 'عميل مزعج',
            'needs_attention' => true, 'bot_paused_until' => now()->addHour(),
        ]);
    }

    public function test_a_block_is_written_atomically_after_meta_accepts_it(): void
    {
        $this->fakeMeta();
        $conv = $this->customer();

        $r = BlockService::block($conv, $this->admin, 'spam', self::REASON);

        $this->assertTrue($r['ok']);
        $block = WaBlock::sole();
        $this->assertSame([$conv->id, '16505551234', 'spam', self::REASON, $this->admin->id, 'dashboard', 'blocked'],
            [$block->conversation_id, $block->phone, $block->reason_code, $block->reason, $block->blocked_by, $block->blocked_via, $block->meta_state]);

        $conv->refresh();
        $this->assertNotNull($conv->blocked_at);
        $this->assertFalse($conv->bot_enabled);
        $this->assertFalse($conv->needs_attention);
        $this->assertNull($conv->bot_paused_until);

        // تدقيق صارم بخطورة warn: رمز السبب وطوله، لا نصه
        $audit = AuditLog::where('action', 'wa.block')->sole();
        $this->assertSame(['warn', $conv->id, 'spam', mb_strlen(self::REASON), 'dashboard'],
            [$audit->severity, $audit->entity_id, $audit->changes['reason_code'], $audit->changes['reason_len'], $audit->changes['via']]);
        $this->assertStringNotContainsString(self::REASON, json_encode($audit->changes, JSON_UNESCAPED_UNICODE));
    }

    public function test_admins_are_notified_without_the_number_or_the_customer_name(): void
    {
        $this->fakeMeta();
        $conv = $this->customer();

        BlockService::block($conv, $this->admin, 'fraud', self::REASON);

        $note = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString("محادثة #{$conv->id}", $note->body);
        $this->assertStringContainsString('احتيال أو انتحال', $note->body);
        foreach (['16505551234', '5551234', 'عميل مزعج', self::REASON] as $secret) {
            $this->assertStringNotContainsString($secret, $note->title.$note->body);
        }

        // قرار المالك: يُطفأ
        config(['whatsapp.block.notify_admins' => false]);
        BlockService::block($this->customer(['phone' => '16505553333', 'wa_phone' => '16505553333']), $this->admin, 'spam', self::REASON);
        $this->assertSame(1, Notification::count());
    }

    public function test_a_meta_refusal_writes_no_local_block(): void
    {
        $this->fakeMeta(400, ['error' => ['code' => 131021, 'message' => 'Recipient cannot be sender']]);
        $conv = $this->customer();

        $r = BlockService::block($conv, $this->admin, 'spam', self::REASON);

        $this->assertSame([false, 'لا يمكن حظر رقمنا.'], [$r['ok'], $r['error']]);
        $this->assertSame(0, WaBlock::count());
        $this->assertNull($conv->fresh()->blocked_at);
        $this->assertTrue($conv->fresh()->bot_enabled);
        $this->assertSame(0, AuditLog::where('action', 'wa.block')->count());
    }

    public function test_admin_staff_merged_and_blocked_conversations_are_refused_without_a_request(): void
    {
        Http::fake();
        WaAdminNumber::factory()->create(['phone' => '16505553333', 'is_active' => true]);
        $cases = [
            'BLOCK_ADMIN' => $this->customer(['phone' => '16505553333', 'wa_phone' => '16505553333']),
            'BLOCK_STAFF' => $this->customer(['phone' => '16505551111', 'wa_phone' => '16505551111', 'user_id' => $this->admin->id]),
            'BLOCK_MERGED' => $this->customer(['phone' => '16505552222', 'wa_phone' => '16505552222', 'status' => 'merged']),
        ];
        $flagged = $this->customer(['phone' => '16505554444', 'wa_phone' => '16505554444']);
        $flagged->forceFill(['is_admin_chat' => true])->save();
        $cases['BLOCK_ADMIN_FLAG'] = $flagged;

        foreach ($cases as $expected => $conv) {
            $this->assertSame(str_replace('_FLAG', '', $expected), BlockService::block($conv, $this->admin, 'spam', self::REASON)['code']);
        }
        $already = $this->customer(['phone' => '16505556666', 'wa_phone' => '16505556666']);
        $already->forceFill(['blocked_at' => now()])->save();
        $this->assertSame('ALREADY_BLOCKED', BlockService::block($already, $this->admin, 'spam', self::REASON)['code']);

        Http::assertNothingSent();
        $this->assertSame(0, WaBlock::count());
    }

    public function test_the_reason_is_required_and_bounded(): void
    {
        Http::fake();
        $conv = $this->customer();

        $this->assertSame('BLOCK_REASON', BlockService::block($conv, $this->admin, 'spam', 'قصير')['code']);
        $this->assertSame('BLOCK_REASON', BlockService::block($conv, $this->admin, 'spam', str_repeat('ط', 301))['code']);
        $this->assertSame('BLOCK_REASON', BlockService::block($conv, $this->admin, 'nonsense', self::REASON)['code']);
        // محارف التحكم والاتجاه لا تُحسب ولا تُحفظ
        $this->assertSame('BLOCK_REASON', BlockService::block($conv, $this->admin, 'spam', "\u{202E}\u{202E}\u{202E}\u{202E}\u{202E}نص\u{0007}")['code']);
        Http::assertNothingSent();
    }

    public function test_two_simultaneous_clicks_block_once(): void
    {
        $this->fakeMeta();
        $conv = $this->customer();

        // الأولى تمسك القفل: الثانية لا تنادي ميتا
        $held = Cache::lock("wa:block:{$conv->id}", 30);
        $this->assertTrue($held->get());
        $this->assertSame('BLOCK_BUSY', BlockService::block($conv, $this->admin, 'spam', self::REASON)['code']);
        Http::assertNothingSent();
        $held->release();

        $this->assertTrue(BlockService::block($conv, $this->admin, 'spam', self::REASON)['ok']);
        $this->assertSame('ALREADY_BLOCKED', BlockService::block($conv, $this->admin, 'spam', self::REASON)['code']);
        Http::assertSentCount(1);
        $this->assertSame(1, WaBlock::count());
    }

    public function test_unblocking_keeps_the_bot_off_and_adds_no_optout(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_ok.json')), true))
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_removed.json')), true))]);
        $conv = $this->customer();
        BlockService::block($conv, $this->admin, 'abuse', self::REASON);

        $r = BlockService::unblock(WaBlock::sole(), $this->admin, 'اعتذر العميل');

        $this->assertTrue($r['ok']);
        $conv->refresh();
        $this->assertNull($conv->blocked_at);
        $this->assertFalse($conv->bot_enabled, 'الموظف يقرر تشغيل البوت');
        $block = WaBlock::sole();
        $this->assertSame(['unblocked', $this->admin->id, 'اعتذر العميل'], [$block->meta_state, $block->unblocked_by, $block->unblock_reason]);
        $this->assertSame('warn', AuditLog::where('action', 'wa.unblock')->sole()->severity);
        $this->assertSame(0, WaOptout::count());
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE');

        $this->assertSame('NOT_BLOCKED', BlockService::unblock($block, $this->admin, 'مرة ثانية')['code']);
    }

    public function test_a_block_meta_no_longer_has_is_released_locally_without_a_request(): void
    {
        Http::fake();
        $conv = $this->customer();
        $conv->forceFill(['blocked_at' => now()])->save();
        $block = WaBlock::create(['conversation_id' => $conv->id, 'phone' => '16505551234', 'reason_code' => 'spam', 'reason' => self::REASON,
            'blocked_at' => now(), 'meta_state' => 'not_found']);

        $this->assertTrue(BlockService::unblock($block, $this->admin, 'لم يعد محظوراً عند ميتا')['ok']);

        Http::assertNothingSent();
        $this->assertNull($conv->fresh()->blocked_at);
        $this->assertTrue(AuditLog::where('action', 'wa.unblock')->sole()->changes['local_only']);
    }

    public function test_the_confirmation_is_the_last_four_digits_or_the_word_for_a_phoneless_chat(): void
    {
        $conv = $this->customer();
        $this->assertTrue(BlockService::confirmMatches($conv, '1234'));
        $this->assertTrue(BlockService::confirmMatches($conv, ' ١٢٣٤ '));
        $this->assertFalse(BlockService::confirmMatches($conv, '1243'));
        $this->assertFalse(BlockService::confirmMatches($conv, 'حظر'));

        $phoneless = $this->customer(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'US.13491208655302741918']);
        $this->assertTrue(BlockService::confirmMatches($phoneless, 'حظر'));
        $this->assertFalse(BlockService::confirmMatches($phoneless, ''));
    }

    public function test_forget_keeps_the_block_stripped_and_the_sync_still_matches_it(): void
    {
        config(['retention.erasure_ledger' => storage_path('framework/testing/erasure-ledger-e31.jsonl')]);
        // الحظر ثم قائمة ميتا في المزامنة: ميتا تعيد الرقم نفسه
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/block_users_ok.json')), true))
            ->push(['data' => [['messaging_product' => 'whatsapp', 'wa_id' => '16505551234']], 'paging' => ['cursors' => ['after' => 'x']]])]);
        $conv = $this->customer();
        BlockService::block($conv, $this->admin, 'fraud', self::REASON);

        $eraser = app(ConversationEraser::class);
        $plan = $eraser->plan($conv->fresh(), 'forget');
        $this->assertSame(1, $plan->counts['blocks_stripped']);
        $done = $eraser->execute($plan);
        $this->assertSame(1, $done['blocks_stripped']);

        $block = WaBlock::sole();
        $this->assertSame([null, null, null, BlockService::ERASED_REASON], [$block->phone, $block->wa_user_id, $block->conversation_id, $block->reason]);
        $this->assertSame(Retention::subjectHmac('16505551234'), $block->subject_hash);
        $this->assertNotNull($block->erased_at);
        $this->assertNull($block->unblocked_at, 'الحظر قائم');

        // ميتا تعيد الرقم في قائمتها: يطابق بالبصمة فلا يظهر «بلا سجل» ولا «غير موجود»
        $sync = BlockSync::run();
        $this->assertTrue($sync['ok']);
        $this->assertSame([[], []], [$sync['untracked'], $sync['not_found']]);
        @unlink(storage_path('framework/testing/erasure-ledger-e31.jsonl'));
    }

    public function test_the_migration_runs_twice_without_duplicates(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_000031_wa_blocks_and_business_profile.php');
        DB::table('roles')->insertOrIgnore(['slug' => 'admin', 'name' => 'مدير', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('permissions')->where('slug', 'whatsapp.block')->count());
        $this->assertSame(1, DB::table('settings')->where('key', 'wa_profile_about')->count());
        $admin = DB::table('roles')->where('slug', 'admin')->value('id');
        $perm = DB::table('permissions')->where('slug', 'whatsapp.block')->value('id');
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $admin)->where('permission_id', $perm)->count());
    }
}

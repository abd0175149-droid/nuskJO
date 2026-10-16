<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\StuckReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الردود العالقة (E17.5): التقرير بمعرّفات فقط، وزر الإعادة لا يحرّر قفلاً أحدث من مهلة الوظيفة،
 * وله حد وتدقيق وشروط واضحة.
 */
class StuckRepliesTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        // الأقفال في جدول cache_locks كما على الخادم
        config(['cache.default' => 'database', 'features.env.reprocess_button' => true, 'audit.extended' => true]);
        $this->admin = $this->staffWith('ops', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all', 'whatsapp.settings']);
    }

    private function stuck(array $attrs = [], int $minutesAgo = 5): WaConversation
    {
        $c = WaConversation::factory()->windowOpen()->create($attrs);
        WaMessage::factory()->inbound()->create(['conversation_id' => $c->id, 'body' => 'سؤال سري 0791234567', 'created_at' => now()->subMinutes($minutesAgo)]);

        return $c;
    }

    private function lockRow(int $convId, int $ageSeconds): void
    {
        DB::table('cache_locks')->insert([
            'key' => config('cache.prefix').StuckReplies::lockName($convId),
            'owner' => 'dead-worker',
            'expiration' => now()->getTimestamp() - $ageSeconds + ProcessWhatsAppReply::LOCK_SECONDS,
        ]);
    }

    private function reprocess(WaConversation $c)
    {
        return $this->actingAs($this->admin)->postJson("/api/whatsapp/conversations/{$c->id}/reprocess");
    }

    public function test_the_report_lists_ids_only_and_skips_what_is_not_stuck(): void
    {
        $stuck = $this->stuck();
        $this->stuck(['needs_attention' => true]);
        $this->stuck(['last_inbound_at' => now()->subDays(2)]);
        $this->stuck([], 1);
        WaAdminNumber::create(['phone' => '16505553333', 'name' => 'مدير', 'is_active' => true, 'permissions' => []]);
        $this->stuck(['phone' => '16505553333', 'wa_phone' => '16505553333']);

        Artisan::call('whatsapp:stuck-replies', ['--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame([$stuck->id], array_column($out['conversations'], 'conv_id'));
        $raw = json_encode($out, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString((string) $stuck->phone, $raw);
        $this->assertStringNotContainsString('سؤال', $raw);
        $this->assertSame([$stuck->id], StuckReplies::cachedIds());
    }

    public function test_notify_alerts_admins_once_an_hour_when_stuck_15_minutes(): void
    {
        User::factory()->admin()->create();
        $this->stuck([], 20);

        $this->artisan('whatsapp:stuck-replies --notify')->assertSuccessful();
        $this->artisan('whatsapp:stuck-replies --notify')->assertSuccessful();

        $this->assertSame(1, Notification::where('title', '🧩 ردود عالقة')->count());
    }

    public function test_reprocess_requires_settings_permission(): void
    {
        $staff = $this->staffWith('desk', [...self::INBOX_PERMISSIONS, 'whatsapp.view_all']);
        $this->actingAs($staff)->postJson('/api/whatsapp/conversations/'.$this->stuck()->id.'/reprocess')->assertForbidden();
    }

    public function test_a_fresh_lock_is_never_released(): void
    {
        $c = $this->stuck();
        $this->lockRow($c->id, 60);

        $this->reprocess($c)->assertStatus(409);
        $this->assertSame(1, DB::table('cache_locks')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_running_marker_blocks_reprocessing(): void
    {
        $c = $this->stuck();
        Cache::put(ProcessWhatsAppReply::runningKey($c->id), time() - 30, 130);

        $this->reprocess($c)->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_a_lock_older_than_the_job_timeout_is_released_and_the_reply_rescheduled(): void
    {
        $c = $this->stuck();
        $this->lockRow($c->id, 200);
        Cache::put("wa:recon:{$c->id}:".$c->messages()->value('id'), 2, 3600);

        $this->reprocess($c)->assertOk()->assertJsonPath('lock_released', true);

        $this->assertSame(0, DB::table('cache_locks')->count());
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
        $this->assertNull(Cache::get("wa:recon:{$c->id}:".$c->messages()->value('id')));
        $log = DB::table('audit_logs')->where('action', 'wa.reprocess')->first();
        $this->assertSame('warn', $log->severity);
        $this->assertStringContainsString('lock_released', $log->changes);
    }

    public function test_reprocess_explains_why_it_cannot_help(): void
    {
        $this->reprocess($this->stuck(['last_inbound_at' => now()->subDays(2)]))->assertStatus(422);
        $this->reprocess($this->stuck(['bot_enabled' => false]))->assertStatus(422)->assertJsonPath('error', 'البوت موقوف لهذه المحادثة — فعّله أو رد يدوياً');
        $this->reprocess(WaConversation::factory()->windowOpen()->create())->assertStatus(422)->assertJsonPath('error', 'لا رسالة معلّقة');
        $this->reprocess($this->stuck([], 60))->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'رد يدوياً'));
        Queue::assertNothingPushed();
    }

    public function test_at_most_three_attempts_in_ten_minutes(): void
    {
        $c = $this->stuck();
        foreach (range(1, 3) as $i) {
            $this->reprocess($c)->assertOk();
        }
        $this->reprocess($c)->assertStatus(429);
    }

    public function test_the_button_is_off_until_its_flag_is_on(): void
    {
        config(['features.env.reprocess_button' => false]);
        $this->reprocess($this->stuck())->assertForbidden();
        Queue::assertNothingPushed();
    }
}

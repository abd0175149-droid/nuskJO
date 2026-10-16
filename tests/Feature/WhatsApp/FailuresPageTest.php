<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\Ops\OpsMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * صفحة «الإخفاقات والطابور» (E24): خلف العلم والصلاحية، والصفوف ضمن نطاق المستخدم وحده، برقم مقنَّع ومقتطف
 * 60 حرفاً، واستثناء المهام منقّحاً، والويبهوك بلا جسم، والطابور من آخر لقطة.
 */
class FailuresPageTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.failures_center' => true]);
        $this->admin = User::factory()->admin()->create();
    }

    private function failed(WaConversation $c, array $attrs = []): WaMessage
    {
        return WaMessage::factory()->outbound()->create(['conversation_id' => $c->id, 'status' => 'failed', 'meta_code' => 131000,
            'error_code' => 'META_TRANSIENT', 'error_category' => 'transient', 'source' => 'bot'] + $attrs);
    }

    public function test_the_flag_off_hides_the_page_and_its_api(): void
    {
        config(['features.env.failures_center' => false]);

        $this->actingAs($this->admin)->get('/whatsapp/failures')->assertNotFound();
        $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->assertNotFound();
    }

    public function test_staff_without_settings_permission_get_403(): void
    {
        $staff = $this->staffWith('sales', self::INBOX_PERMISSIONS);

        $this->actingAs($staff)->get('/whatsapp/failures')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/whatsapp/failures')->assertForbidden();
    }

    public function test_the_page_renders_with_its_four_sections(): void
    {
        $this->actingAs($this->admin)->get('/whatsapp/failures')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('WhatsApp/Failures', false)
                ->has('initial.messages')->has('initial.jobs')->has('initial.webhooks.rows')->has('initial.queue'));
    }

    public function test_message_rows_are_scoped_masked_and_trimmed(): void
    {
        $mine = $this->conversationTagged(['umrah'], ['display_name' => null, 'wa_profile_name' => null, 'phone' => '962791234567', 'wa_phone' => '962791234567']);
        $other = $this->conversationTagged(['visa'], ['display_name' => 'عميل آخر']);
        $long = str_repeat('أ', 80);
        $this->failed($mine, ['body' => $long]);
        $this->failed($mine, ['msg_type' => 'image', 'body' => 'تعليق صورة']);
        $this->failed($other);
        WaMessage::factory()->outbound()->create(['conversation_id' => $mine->id, 'status' => 'failed', 'created_at' => now()->subDays(8)]);
        WaMessage::factory()->outbound()->create(['conversation_id' => $mine->id, 'status' => 'sent']);
        $supervisor = $this->staffWith('supervisor', ['whatsapp.view', 'whatsapp.settings'], ['umrah']);

        $rows = $this->actingAs($supervisor)->getJson('/api/whatsapp/failures')->assertOk()->json('messages');

        $this->assertCount(2, $rows);
        $this->assertSame([$mine->id], array_values(array_unique(array_column($rows, 'conversation_id'))));
        $this->assertSame('9627•••4567', $rows[0]['conversation']);
        $this->assertNull($rows[0]['snippet'], 'غير النصي بنوعه فقط');
        $this->assertSame(60, mb_strlen($rows[1]['snippet']));
        $this->assertSame('transient', $rows[1]['category']);
        $this->assertNotEmpty($rows[1]['staff_message']);
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('962791234567', $json);
        $this->assertStringNotContainsString('تعليق صورة', $json);

        $all = $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('messages');
        $this->assertCount(3, $all, 'المدير يرى كل النطاق');
    }

    public function test_unknown_rows_are_marked_maybe_delivered_without_a_redrive(): void
    {
        config(['features.env.failures_redrive' => true]);
        $c = WaConversation::factory()->windowOpen()->create();
        WaMessage::factory()->outbound()->create(['conversation_id' => $c->id, 'status' => 'unknown', 'send_key' => 'reply:1:2:text']);

        $row = $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('messages.0');

        $this->assertTrue($row['maybe_delivered']);
        $this->assertFalse($row['can_redrive']);
        $this->assertStringContainsString('قد تكون وصلت', $row['staff_message']);
    }

    public function test_failed_jobs_are_shown_redacted_without_payload(): void
    {
        $command = serialize(new ProcessWhatsAppReply(42, 'tok'));
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'wa-replies',
            'payload' => json_encode(['displayName' => ProcessWhatsAppReply::class, 'data' => ['command' => $command], 'secret_body' => 'نص العميل']),
            'exception' => "RuntimeException: upstream failed key=SECRET123 for +962790000000 in /var/www/app.php:12\n#0 stack line with 962790000000",
            'failed_at' => now()->subHour(),
        ]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'old', 'failed_at' => now()->subDays(2)]);

        $jobs = $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('jobs');

        $this->assertCount(1, $jobs);
        $this->assertSame('ProcessWhatsAppReply', $jobs[0]['job']);
        $this->assertSame('wa-replies', $jobs[0]['queue']);
        $this->assertTrue($jobs[0]['reply_job']);
        $this->assertFalse($jobs[0]['retryable']);
        $json = json_encode($jobs, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET123', $json);
        $this->assertStringNotContainsString('962790000000', $json);
        $this->assertStringNotContainsString('stack line', $json);
        $this->assertStringNotContainsString('نص العميل', $json);
        $this->assertLessThanOrEqual(200, mb_strlen($jobs[0]['error']));
    }

    public function test_webhook_events_show_metadata_only_and_rejected_as_counts(): void
    {
        $sha = hash('sha256', 'body');
        foreach ([['failed', 1], ['dead', 3], ['malformed', 1], ['processed', 1]] as $i => [$status, $attempts]) {
            WaWebhookEvent::create(['received_at' => now()->subHours($i + 1), 'body_sha256' => hash('sha256', "b{$i}"), 'raw_body' => '{"secret":"16505551234"}',
                'status' => $status, 'attempts' => $attempts, 'field_summary' => 'messages:1', 'error' => 'QueryException']);
        }
        WaWebhookEvent::create(['received_at' => now(), 'body_sha256' => $sha, 'status' => 'rejected', 'reject_reason' => 'waba_mismatch']);

        $hooks = $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('webhooks');

        $this->assertSame(['failed', 'dead', 'malformed'], array_reverse(array_column($hooks['rows'], 'status')));
        $this->assertSame(['waba_mismatch' => 1], $hooks['rejected']);
        $dead = collect($hooks['rows'])->firstWhere('status', 'dead');
        $this->assertTrue($dead['needs_force']);
        $this->assertTrue($dead['can_replay']);
        $this->assertFalse(collect($hooks['rows'])->firstWhere('status', 'malformed')['can_replay']);
        $this->assertSame(12, strlen($dead['sha']));
        $this->assertStringNotContainsString('16505551234', json_encode($hooks));
    }

    public function test_the_queue_tab_reads_the_last_snapshot(): void
    {
        $this->assertNull($this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('queue.snapshot'));

        OpsMetrics::remember((new OpsMetrics)->snapshot());

        $queue = $this->actingAs($this->admin)->getJson('/api/whatsapp/failures')->json('queue');
        $this->assertArrayHasKey('default', $queue['snapshot']['queue']);
        $this->assertSame(0, $queue['age_minutes']);
    }
}

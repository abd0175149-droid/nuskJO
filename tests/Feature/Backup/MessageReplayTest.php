<?php

namespace Tests\Feature\Backup;

use App\Models\Client;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Backup\SnapshotDiff;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/** backup:replay-messages وbackup:diff-report على لقطة ما قبل الاستعادة. */
class MessageReplayTest extends TestCase
{
    use RefreshDatabase;

    private const SINCE = '2026-10-14T02:30:00+00:00';

    private string $dir;

    private string $snapshot;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nj-replay-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        $this->snapshot = $this->dir.'/pre-restore.sqlite';

        $this->pdo = new PDO('sqlite:'.$this->snapshot);
        $this->pdo->exec('CREATE TABLE wa_conversations (id INTEGER PRIMARY KEY, phone TEXT, wa_phone TEXT, display_name TEXT, created_at TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE wa_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, wamid TEXT, direction TEXT, source TEXT, msg_type TEXT,
            body TEXT, payload TEXT, status TEXT, error_message TEXT, staff_id INTEGER, created_at TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE wa_conversation_topics (id INTEGER PRIMARY KEY, conversation_id INTEGER, topic TEXT, source TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, client_id INTEGER, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, created_at TEXT)');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function snapshotMessage(int $conv, ?string $wamid, string $direction, string $source, string $body, string $at, ?string $status = null): void
    {
        $st = $this->pdo->prepare('INSERT INTO wa_messages (conversation_id, wamid, direction, source, msg_type, body, payload, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$conv, $wamid, $direction, $source, 'text', $body, '{"k":1}', $status, $at, $at]);
    }

    /** المحادثة نفسها برقمها، لكن بمعرّف مختلف في اللقطة، و3 رسائل بعد وقت النسخة. */
    private function seedSameConversation(): WaConversation
    {
        WaConversation::factory()->create();   // يجعل معرّف المحادثة الحية مختلفاً عن اللقطة
        $live = WhatsAppInbox::conversationFor('16505551234', 'عميل تجريبي');
        WaMessage::factory()->inbound()->create(['conversation_id' => $live->id, 'wamid' => 'wamid.EXISTING', 'created_at' => '2026-10-14 03:00:00']);
        $live->update(['unread_count' => 0, 'needs_attention' => false]);

        $this->pdo->exec("INSERT INTO wa_conversations (id, phone, wa_phone, display_name) VALUES (7, '16505551234', '16505551234', 'عميل تجريبي')");
        $this->pdo->exec("INSERT INTO wa_conversation_topics (conversation_id, topic, source) VALUES (7, 'umrah', 'bot')");
        $this->snapshotMessage(7, 'wamid.OLD', 'in', 'customer', 'قبل النسخة', '2026-10-14 01:00:00');
        $this->snapshotMessage(7, 'wamid.EXISTING', 'in', 'customer', 'موجودة', '2026-10-14 03:00:00');
        $this->snapshotMessage(7, 'wamid.NEW1', 'in', 'customer', 'رسالة فائتة', '2026-10-14 04:00:00');
        $this->snapshotMessage(7, null, 'out', 'system', 'رسالة نظام', '2026-10-14 04:05:00', 'failed');

        return $live;
    }

    public function test_replays_only_missing_messages_into_the_matching_conversation_without_replying(): void
    {
        $live = $this->seedSameConversation();

        $this->artisan('backup:replay-messages', ['snapshot' => $this->snapshot, '--since' => self::SINCE])->assertSuccessful();

        $this->assertSame(3, WaMessage::where('conversation_id', $live->id)->count());
        $this->assertDatabaseMissing('wa_messages', ['wamid' => 'wamid.OLD']);

        $replayed = WaMessage::where('wamid', 'wamid.NEW1')->firstOrFail();
        $this->assertSame($live->id, $replayed->conversation_id);
        $this->assertSame('2026-10-14 04:00:00', $replayed->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('pre-restore.sqlite', $replayed->payload['_replayed_from']);
        $this->assertSame(1, WaMessage::whereNull('wamid')->where('source', 'system')->count());

        $live->refresh();
        $this->assertSame(1, $live->unread_count);
        $this->assertTrue($live->needs_attention);
        $this->assertSame('2026-10-14 04:00:00', $live->last_inbound_at->format('Y-m-d H:i:s'));
        $this->assertSame('رسالة فائتة', $live->last_message_preview, 'الصادرة الفاشلة لا تصبح آخر رسالة');
        $this->assertDatabaseHas('wa_conversation_topics', ['conversation_id' => $live->id, 'topic' => 'umrah']);

        $this->assertSame(0, DB::table('jobs')->count(), 'لا وظيفة ردّ');
        Http::assertNothingSent();

        // التشغيل الثاني لا يدرج شيئاً
        Artisan::call('backup:replay-messages', ['snapshot' => $this->snapshot, '--since' => self::SINCE]);
        $this->assertSame(3, WaMessage::where('conversation_id', $live->id)->count());
        $this->assertSame(1, $live->fresh()->unread_count);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $live = $this->seedSameConversation();
        $this->pdo->exec("INSERT INTO wa_conversations (id, phone, wa_phone, display_name) VALUES (8, '16505553333', '16505553333', 'عميل آخر')");
        $this->snapshotMessage(8, 'wamid.NEWCONV', 'in', 'customer', 'محادثة جديدة', '2026-10-14 05:00:00');
        $convs = WaConversation::count();

        $this->artisan('backup:replay-messages', ['snapshot' => $this->snapshot, '--since' => self::SINCE, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, WaMessage::where('conversation_id', $live->id)->count());
        $this->assertSame($convs, WaConversation::count());
        $this->assertDatabaseMissing('wa_messages', ['wamid' => 'wamid.NEWCONV']);
    }

    public function test_conversation_missing_from_live_is_created(): void
    {
        $this->pdo->exec("INSERT INTO wa_conversations (id, phone, wa_phone, display_name) VALUES (8, '16505553333', '16505553333', 'عميل آخر')");
        $this->snapshotMessage(8, 'wamid.NEWCONV', 'in', 'customer', 'محادثة جديدة', '2026-10-14 05:00:00');

        $this->artisan('backup:replay-messages', ['snapshot' => $this->snapshot, '--since' => self::SINCE])->assertSuccessful();

        $conv = WaMessage::where('wamid', 'wamid.NEWCONV')->firstOrFail()->conversation;
        $this->assertSame('16505553333', $conv->wa_phone);
        $this->assertSame(1, $conv->unread_count);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_since_is_required_without_a_pending_review(): void
    {
        $this->artisan('backup:replay-messages', ['snapshot' => $this->snapshot])->assertFailed();
    }

    public function test_diff_report_lists_document_numbers_only(): void
    {
        $user = User::factory()->admin()->create();
        $client = Client::factory()->create();
        foreach (['INV-OLD', 'INV-NEW-2'] as $n) {
            DB::table('invoices')->insert([
                'invoice_number' => $n, 'client_id' => $client->id, 'exchange_rate_snapshot' => 1,
                'invoice_date' => '2026-10-14', 'created_by' => $user->id, 'created_at' => '2026-10-14 04:00:00',
            ]);
        }

        $this->pdo->exec("INSERT INTO invoices (invoice_number, client_id, created_at) VALUES
            ('INV-OLD', 1, '2026-10-13 10:00:00'), ('INV-NEW-1', 1, '2026-10-14 04:00:00'), ('INV-NEW-2', 1, '2026-10-14 04:10:00')");
        $this->pdo->exec("INSERT INTO clients (id, name, phone, created_at) VALUES (900, 'عميل سري', '16505559999', '2026-10-14 04:20:00')");

        $report = (new SnapshotDiff)->report($this->snapshot, self::SINCE);
        $this->assertSame(['INV-NEW-1'], $report['invoices']['identifiers']);
        $this->assertSame(['#900'], $report['clients']['identifiers']);

        Artisan::call('backup:diff-report', ['snapshot' => $this->snapshot, '--since' => self::SINCE]);
        $output = Artisan::output();
        $this->assertStringContainsString('INV-NEW-1', $output);
        $this->assertStringNotContainsString('INV-NEW-2', $output);
        $this->assertStringNotContainsString('عميل سري', $output);
        $this->assertStringNotContainsString('16505559999', $output);
    }
}

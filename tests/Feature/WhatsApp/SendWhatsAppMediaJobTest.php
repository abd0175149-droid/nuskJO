<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMediaJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\OutboundFileStore;
use App\Services\WhatsApp\Outbound\OutboundMediaPolicy;
use App\Services\WhatsApp\Outbound\OutboundMessage;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الإرسال المؤجل للملف (E32.7): صف محجوز بالمفتاح والنسخة الخاصة، ثم الوظيفة: preflight من جديد، فالرفع، فالإرسال على
 * الصف نفسه بالمفتاح نفسه. أي فشل قبل الإرسال يحسم الصف failed والمفتاح محرَّر، ولا إعادة آلية، ولا ملف يتيم عند ميتا.
 */
class SendWhatsAppMediaJobTest extends TestCase
{
    use BuildsMediaSamples;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const KEY = 'staff:3f1c2b9e-5d4a-4c8b-9e7f-1a2b3c4d5e6f';

    private WaConversation $conv;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create(['needs_attention' => true, 'unread_count' => 2]);
        $this->staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();
    }

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    private function fakeMeta(int $messagesStatus = 200): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '4490709327384033']),
            'graph.facebook.com/v25.0/106540352242922/messages' => $messagesStatus === 200
                ? Http::response(['messages' => [['id' => 'wamid.OUT.1']]])
                : Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], $messagesStatus),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    private function reserve(string $key = self::KEY): array
    {
        $path = $this->sampleFile($this->pdfBytes(), 'pdf');
        $info = OutboundMediaPolicy::inspect($path, 'تذكرة الطيران.pdf');
        $stored = OutboundFileStore::put($path, $info['ext']);

        return WhatsAppChannel::reserve($this->conv, OutboundMessage::document(null, $info['name'], 'تذكرتك للرحلة'), 'staff', $this->staff->id, [
            'send_key' => $key,
            'media' => ['mime' => $info['mime'], 'sha256' => $stored['sha256'], 'size' => $stored['size'], 'filename' => $info['name'], 'path' => $stored['path']],
            'payload' => ['_doc_kind' => 'ticket'],
        ]);
    }

    private function run_(int $rowId): void
    {
        (new SendWhatsAppMediaJob($rowId))->handle();
    }

    public function test_the_job_uploads_then_sends_on_the_same_row_with_the_same_key(): void
    {
        $this->fakeMeta();
        $res = $this->reserve();
        $this->assertSame([true, 'queued'], [$res['ok'], $res['status']]);
        $row = WaMessage::find($res['row_id']);
        $this->assertSame(['queued', 0, self::KEY, 'stored', '📄 ملف: تذكرة الطيران.pdf: تذكرتك للرحلة'],
            [$row->status, $row->attempts, $row->send_key, $row->media_status, $row->body]);

        $this->run_($row->id);

        $row->refresh();
        $this->assertSame(['sent', 'wamid.OUT.1', '4490709327384033', self::KEY, 1], [$row->status, $row->wamid, $row->media_id, $row->send_key, $row->attempts]);
        $this->assertSame(1, WaMessage::count());
        $this->assertSame('ticket', $row->payload['_doc_kind']);
        $this->assertSame(['id' => '4490709327384033', 'filename' => 'تذكرة الطيران.pdf', 'caption' => 'تذكرتك للرحلة'], $row->payload['document']);

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertSame(['https://graph.facebook.com/v25.0/106540352242922/media', 'https://graph.facebook.com/v25.0/106540352242922/messages'], $urls);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && str_starts_with((string) $r['biz_opaque_callback_data'], 'nj:m:'));

        // رد الموظف بملف: البوت موقوف، وغير المقروء والتدخّل صُفّرا
        $this->conv->refresh();
        $this->assertSame([0, false], [$this->conv->unread_count, $this->conv->needs_attention]);

        // سجل الملف: النوع والحجم والبصمة ونوع الوثيقة، بلا اسم الملف، والفاعل الموظف
        $audit = AuditLog::where('action', 'wa.file_sent')->sole();
        $this->assertSame([$this->staff->id, 'wa_conversation', $this->conv->id], [$audit->user_id, $audit->entity_type, $audit->entity_id]);
        $this->assertSame(['message_id', 'type', 'mime', 'size', 'sha256', 'via', 'doc_kind'], array_keys($audit->changes));
        $this->assertSame(['document', 'inbox', 'ticket'], [$audit->changes['type'], $audit->changes['via'], $audit->changes['doc_kind']]);
        $this->assertSame(16, strlen($audit->changes['sha256']));
        $this->assertStringNotContainsString('تذكرة', json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_window_closed_before_the_job_runs_fails_without_any_upload(): void
    {
        $this->fakeMeta();
        $row = WaMessage::find($this->reserve()['row_id']);
        $this->conv->update(['last_inbound_at' => now()->subHours(25)]);

        $this->run_($row->id);

        $row->refresh();
        $this->assertSame(['failed', 'WINDOW_EXPIRED', null], [$row->status, $row->error_code, $row->send_key]);
        $this->assertSame(self::KEY, $row->payload['_released_key']);
        $this->assertSame(1, (new SendWhatsAppMediaJob($row->id))->tries);
        Http::assertNothingSent();
    }

    public function test_a_row_that_waited_too_long_is_not_sent(): void
    {
        $this->fakeMeta();
        $row = WaMessage::find($this->reserve()['row_id']);
        WaMessage::whereKey($row->id)->update(['created_at' => now()->subMinutes(11)]);

        $this->run_($row->id);

        $this->assertSame(['failed', 'MEDIA_QUEUE_STALE'], [$row->fresh()->status, $row->fresh()->error_code]);
        Http::assertNothingSent();
    }

    public function test_a_missing_private_copy_fails_the_row(): void
    {
        $this->fakeMeta();
        $row = WaMessage::find($this->reserve()['row_id']);
        Storage::disk('wa_media')->delete($row->media_path);

        $this->run_($row->id);

        $this->assertSame('MEDIA_FILE_MISSING', $row->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_an_upload_rejection_fails_the_row_and_never_calls_messages(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131053, 'message' => 'Media upload error', 'fbtrace_id' => 'TraceUp']], 400)]);
        $row = WaMessage::find($this->reserve()['row_id']);

        $this->run_($row->id);

        $row->refresh();
        $this->assertSame(['failed', 131053, 'TraceUp', null], [$row->status, $row->meta_code, $row->fbtrace_id, $row->send_key]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/messages'));
    }

    public function test_a_rejection_after_upload_deletes_the_orphan_at_meta(): void
    {
        $this->fakeMeta(400);
        $row = WaMessage::find($this->reserve()['row_id']);

        $this->run_($row->id);

        $this->assertSame('failed', $row->fresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/4490709327384033'));
        $this->assertSame(0, AuditLog::where('action', 'wa.file_sent')->count());
    }

    public function test_a_handled_row_is_left_alone(): void
    {
        $this->fakeMeta();
        $row = WaMessage::find($this->reserve()['row_id']);

        $this->run_($row->id);
        $this->run_($row->id);

        Http::assertSentCount(2);
        $this->assertSame('sent', $row->fresh()->status);
    }

    public function test_a_crashed_job_marks_its_untouched_row_failed(): void
    {
        $row = WaMessage::find($this->reserve()['row_id']);

        (new SendWhatsAppMediaJob($row->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(['failed', 'MEDIA_SEND_FAILED'], [$row->fresh()->status, $row->fresh()->error_code]);
    }

    public function test_the_same_key_reserves_once(): void
    {
        $first = $this->reserve();
        $again = $this->reserve();

        $this->assertSame([false, 'SEND_IN_PROGRESS', $first['row_id']], [$again['ok'], $again['code'], $again['row_id']]);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_reserving_runs_the_locks_first(): void
    {
        $this->conv->update(['last_inbound_at' => now()->subHours(25)]);

        $res = $this->reserve();

        $this->assertSame([false, 'WINDOW_EXPIRED'], [$res['ok'], $res['code']]);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_the_reaper_reports_a_never_attempted_file_as_not_sent(): void
    {
        config(['features.env.send_ledger' => true]);
        Http::fake();
        $file = WaMessage::find($this->reserve()['row_id']);
        $text = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'status' => 'queued', 'wamid' => null, 'send_key' => 'reply:9:9:text']);
        WaMessage::whereIn('id', [$file->id, $text->id])->update(['created_at' => now()->subMinutes(20)]);

        Artisan::call('whatsapp:reap-pending');

        $file->refresh();
        $this->assertSame(['failed', 'MEDIA_QUEUE_STALE', null], [$file->status, $file->error_code, $file->send_key]);
        $this->assertSame('unknown', $text->fresh()->status);
        $this->assertStringContainsString('ملفات لم تُرسل: 1', Artisan::output());
        Http::assertNothingSent();
    }
}

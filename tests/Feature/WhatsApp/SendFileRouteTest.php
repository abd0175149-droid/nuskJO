<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMediaJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * مسار الملف من الصندوق (E32.7): الصلاحية والسياج، وتأكيد المستلم في الخادم، وIdempotency-Key يمنع التكرار، والحد،
 * والرفع إلى ميتا مؤجل دائماً في الطابور، والنسخة الخاصة بلا الاسم الأصلي، ونوع الوثيقة وتأكيد التأشيرة، والتدقيق بلا اسم.
 */
class SendFileRouteTest extends TestCase
{
    use BuildsMediaSamples;
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private const KEY = '7d9e2c41-0b6a-4f3e-9a8d-2c5b7e1f4a60';

    private User $staff;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        config(['features.env.staff_file_send' => true]);
        $this->staff = $this->staffWith('visa-desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);
        $this->conv = $this->conversationTagged(['visa'], ['unread_count' => 2]);
    }

    protected function tearDown(): void
    {
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    private function pdf(string $name = 'تذكرة الطيران.pdf', string $bytes = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes !== '' ? $bytes : $this->pdfBytes());
    }

    private function postFile(?UploadedFile $file = null, array $data = [], ?string $key = self::KEY, ?User $as = null, ?WaConversation $conv = null)
    {
        $conv ??= $this->conv;

        return $this->actingAs($as ?? $this->staff)->post("/api/whatsapp/conversations/{$conv->id}/send-file",
            // المُمرَّر أولاً: + يُبقي القيمة اليسرى للمفتاح المكرر
            $data + ['file' => $file ?? $this->pdf(), 'confirm_conversation_id' => $conv->id, 'doc_kind' => 'ticket'],
            ['Accept' => 'application/json'] + ($key !== null ? ['Idempotency-Key' => $key] : []));
    }

    public function test_a_file_is_queued_with_a_private_copy_and_nothing_goes_to_meta_in_the_request(): void
    {
        Queue::fake();
        Http::fake();

        $res = $this->postFile(data: ['caption' => 'تذكرتك للرحلة'])->assertStatus(202)
            ->assertJson(['ok' => true, 'status' => 'queued', 'replayed' => false]);

        $row = WaMessage::sole();
        $this->assertSame([$row->id, 'queued', 'document', 'staff', $this->staff->id, 'staff:'.self::KEY],
            [$res->json('row_id'), $row->status, $row->msg_type, $row->source, $row->staff_id, $row->send_key]);
        $this->assertMatchesRegularExpression('#^outbound/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#', $row->media_path);
        Storage::disk('wa_media')->assertExists($row->media_path);
        $this->assertSame(['application/pdf', 'تذكرة الطيران.pdf', 'stored', 'ticket'], [$row->media_mime, $row->media_filename, $row->media_status, $row->payload['_doc_kind']]);
        Queue::assertPushedOn('wa-media-out', SendWhatsAppMediaJob::class, fn ($job) => $job->messageId === $row->id);
        Http::assertNothingSent();
        $this->assertTrue($this->conv->fresh()->bot_paused_until->isFuture());
    }

    public function test_a_large_document_is_queued_like_any_other(): void
    {
        Queue::fake();

        $this->postFile($this->pdf('big.pdf', $this->pdfBytes('', str_repeat('A', 6 * 1024 * 1024))))->assertStatus(202);

        Queue::assertPushed(SendWhatsAppMediaJob::class, 1);
    }

    public function test_reply_permission_scope_and_flag_are_enforced(): void
    {
        Queue::fake();
        $viewer = $this->staffWith('viewer', ['whatsapp.view'], ['visa']);
        $outsider = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);

        $this->postFile(as: $viewer)->assertForbidden();
        $this->postFile(as: $outsider)->assertNotFound();
        config(['features.env.staff_file_send' => false]);
        $this->postFile()->assertNotFound();

        $this->assertSame(0, WaMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_the_server_checks_the_confirmed_recipient(): void
    {
        Queue::fake();
        $other = $this->conversationTagged(['visa']);

        $this->postFile(data: ['confirm_conversation_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_MISMATCH');

        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_the_idempotency_key_is_required_and_the_same_key_sends_once(): void
    {
        Queue::fake();

        $this->postFile(key: null)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $first = $this->postFile()->assertStatus(202);
        $again = $this->postFile()->assertStatus(202)->assertJson(['ok' => true, 'status' => 'queued', 'replayed' => true]);

        $this->assertSame($first->json('row_id'), $again->json('row_id'));
        $this->assertSame(1, WaMessage::count());
        Queue::assertPushed(SendWhatsAppMediaJob::class, 1);
        // نسخة الطلب الثاني بلا صف حُذفت
        $this->assertCount(1, Storage::disk('wa_media')->allFiles());
    }

    public function test_the_same_key_after_sending_replays_without_a_second_upload(): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '4490709327384033']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.1']]]),
        ]);

        $this->postFile()->assertStatus(202);
        $this->assertSame('sent', WaMessage::sole()->status);
        $this->postFile()->assertOk()->assertJson(['ok' => true, 'replayed' => true]);

        Http::assertSentCount(2);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_the_whole_path_sends_and_audits_without_the_file_name(): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '4490709327384033']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT.1']]]),
        ]);

        $this->postFile($this->pdf('تأشيرة سعيد.pdf'), ['doc_kind' => 'visa', 'confirm_identity' => true])->assertStatus(202);

        $row = WaMessage::sole();
        $this->assertSame(['sent', 'wamid.OUT.1'], [$row->status, $row->wamid]);
        $audit = AuditLog::where('action', 'wa.file_sent')->sole();
        $this->assertSame(['warn', 'visa'], [$audit->severity, $audit->changes['doc_kind']]);
        $this->assertStringNotContainsString('سعيد', json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->conv->fresh()->unread_count);
    }

    public function test_document_kind_and_the_visa_confirmation_are_required(): void
    {
        Queue::fake();

        $this->postFile(data: ['doc_kind' => null])->assertStatus(422)->assertJsonPath('code', 'DOC_KIND_REQUIRED');
        $this->postFile(data: ['doc_kind' => 'visa'])->assertStatus(422)->assertJsonPath('code', 'IDENTITY_CONFIRM_REQUIRED');
        $this->postFile(data: ['doc_kind' => 'passport'])->assertStatus(422);
        // الصورة بلا نوع وثيقة تمر
        $this->postFile(UploadedFile::fake()->createWithContent('photo.jpg', $this->jpegBytes()), ['doc_kind' => null])->assertStatus(202);

        $this->assertSame(['image'], WaMessage::pluck('msg_type')->all());
    }

    public function test_a_disguised_or_active_file_is_refused_before_anything_is_stored(): void
    {
        Queue::fake();

        $this->postFile($this->pdf('invoice.pdf', "MZ\x90\x00".str_repeat("\0", 60)))->assertStatus(422)->assertJsonPath('code', 'MEDIA_TYPE');
        $this->postFile($this->pdf('form.pdf', $this->pdfBytes('/OpenAction << /S /JavaScript /JS (x) >>')))->assertStatus(422)->assertJsonPath('code', 'PDF_ACTIVE');
        $this->postFile(UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4Bytes('hvc1')))->assertStatus(422)->assertJsonPath('code', 'VIDEO_HEVC');

        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_a_closed_window_refuses_before_storing(): void
    {
        Queue::fake();
        $this->conv->update(['last_inbound_at' => now()->subHours(25)]);

        $this->postFile()->assertStatus(422)->assertJsonPath('code', 'WINDOW_EXPIRED');

        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_sensitive_numbers_in_the_caption_need_an_explicit_confirmation(): void
    {
        Queue::fake();
        config(['audit.extended' => true]);

        $this->postFile(data: ['caption' => 'جواز رقم N1234567'])->assertStatus(409)->assertJsonPath('code', 'POLICY_WARN');
        $this->assertSame(0, WaMessage::count());

        $this->postFile(data: ['caption' => 'جواز رقم N1234567', 'ack_warnings' => true])->assertStatus(202);
        $this->assertSame(1, AuditLog::where('action', 'wa.policy_override')->count());
    }

    public function test_a_quote_is_kept_only_from_the_same_conversation(): void
    {
        Queue::fake();
        $mine = WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'wamid' => 'wamid.MINE']);
        $theirs = WaMessage::factory()->create(['conversation_id' => WaConversation::factory()->create()->id, 'wamid' => 'wamid.THEIRS']);

        $this->postFile(data: ['reply_to_message_id' => $theirs->id])->assertStatus(202);
        $this->postFile(data: ['reply_to_message_id' => $mine->id], key: '0e8a1f52-3b7c-4d9e-8f6a-5c4b3a2d1e0f')->assertStatus(202);

        $this->assertSame([null, 'wamid.MINE'], WaMessage::where('direction', 'out')->orderBy('id')->pluck('reply_to_wamid')->all());
    }

    public function test_ten_a_minute_then_an_arabic_429(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postFile(key: null)->assertStatus(422);
        }

        $this->postFile(key: null)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_the_nginx_block_fits_the_document_limit_and_php(): void
    {
        $conf = (string) file_get_contents(base_path('docker/nginx.conf'));
        $this->assertSame(1, preg_match('#location ~ \^/api/whatsapp/conversations/\[0-9\]\+/send-file\$ \{([^}]*)\}#', $conf, $block));
        preg_match('/client_max_body_size\s+(\d+)m;/', $block[1], $size);
        $ini = (string) file_get_contents(base_path('docker/php.ini'));
        preg_match('/^upload_max_filesize\s*=\s*(\d+)M/m', $ini, $php);

        // أقصى حد ممكن للمستند (19) وتعليقه داخل الكتلة، والكتلة داخل حد PHP
        $this->assertGreaterThan(19, (int) $size[1]);
        $this->assertGreaterThan((int) config('whatsapp.outbound.max_document_mb'), (int) $size[1]);
        $this->assertLessThanOrEqual((int) $php[1], (int) $size[1]);
    }
}

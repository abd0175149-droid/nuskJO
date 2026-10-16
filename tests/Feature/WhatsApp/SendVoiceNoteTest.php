<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendWhatsAppMediaJob;
use App\Jobs\TranscribeStaffVoice;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MediaConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMediaSamples;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesVoiceConverter;
use Tests\TestCase;

/**
 * رسالة الموظف الصوتية عبر send-file بحقل voice=1 (E34.5): الأقفال قبل ffmpeg، والتحويل ثم شرط ميتا، والحجز بالمفتاح
 * والنسخة الخاصة، والرفع المؤجل ثم {type: audio, audio: {id, voice: true}}، والتدقيق والتفريغ بعده. المحوّل مزيّف.
 */
class SendVoiceNoteTest extends TestCase
{
    use BuildsMediaSamples;
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use FakesVoiceConverter;
    use RefreshDatabase;

    private const KEY = '5b0e7c3a-91d2-4f6e-8a4b-3c2d1e0f9a87';

    private const MEDIA_URL = 'https://graph.facebook.com/v25.0/106540352242922/media';

    private const MESSAGES_URL = 'https://graph.facebook.com/v25.0/106540352242922/messages';

    private User $staff;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        config(['features.env.staff_voice_notes' => true, 'whatsapp.voice.lock_wait' => 0]);
        $this->staff = $this->staffWith('visa-desk', ['whatsapp.view', 'whatsapp.reply'], ['visa']);
        $this->conv = $this->conversationTagged(['visa'], ['unread_count' => 1]);
        $this->fakeConverter(inputSeconds: 'N/A', outputSeconds: 42.4);
    }

    protected function tearDown(): void
    {
        $this->tearDownVoiceConverter();
        $this->tearDownMediaSamples();
        parent::tearDown();
    }

    /** تسجيل Firefox الخام (ogg/opus ستيريو): المحوّل يجعله أحادياً */
    private function recording(string $bytes = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('voice.ogg', $bytes !== '' ? $bytes : $this->oggOpusBytes(2));
    }

    private function postVoice(?UploadedFile $file = null, array $data = [], ?string $key = self::KEY, ?User $as = null, ?WaConversation $conv = null)
    {
        $conv ??= $this->conv;

        return $this->actingAs($as ?? $this->staff)->post("/api/whatsapp/conversations/{$conv->id}/send-file",
            $data + ['file' => $file ?? $this->recording(), 'voice' => '1', 'confirm_conversation_id' => $conv->id],
            ['Accept' => 'application/json'] + ($key !== null ? ['Idempotency-Key' => $key] : []));
    }

    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '7731450912836654']),
            'graph.facebook.com/v25.0/106540352242922/messages' => Http::response(['messages' => [['id' => 'wamid.VOICE.1']]]),
        ]);
    }

    public function test_a_voice_note_is_converted_queued_then_uploaded_and_sent_as_a_native_voice_message(): void
    {
        Queue::fake();
        $this->fakeMeta();
        config(['features.env.staff_voice_transcript' => true]);

        $res = $this->postVoice()->assertStatus(202)->assertJson(['ok' => true, 'status' => 'queued', 'replayed' => false]);
        Http::assertNothingSent();

        $row = WaMessage::sole();
        $this->assertSame([$row->id, 'queued', 'audio', 'staff', $this->staff->id, 'staff:'.self::KEY],
            [$res->json('row_id'), $row->status, $row->msg_type, $row->source, $row->staff_id, $row->send_key]);
        $this->assertSame(['audio/ogg', 'stored', '🎤 رسالة صوتية من الموظف (0:42)', 42.4],
            [$row->media_mime, $row->media_status, $row->body, $row->payload['_voice']['seconds']]);
        $this->assertMatchesRegularExpression('#^outbound/\d{4}/\d{2}/[0-9a-f-]{36}\.ogg$#', $row->media_path);
        $stored = Storage::disk('wa_media')->get($row->media_path);
        $this->assertSame([hash('sha256', $stored), strlen($stored)], [$row->media_sha256, (int) $row->media_size]);
        $this->assertSame(1, ord($stored[27 + ord($stored[26]) + 9]), 'الناتج أحادي');
        Queue::assertPushedOn('wa-media-out', SendWhatsAppMediaJob::class, fn ($job) => $job->messageId === $row->id);
        // لا ملف مؤقت يبقى بعد الطلب
        $this->assertSame([], glob(MediaConverter::tmpDir().'/*') ?: []);

        (new SendWhatsAppMediaJob($row->id))->handle();

        $this->assertSame([self::MEDIA_URL, self::MESSAGES_URL], Http::recorded()->map(fn ($pair) => $pair[0]->url())->all());
        Http::assertSent(function (Request $r) {
            $parts = collect($r->data())->keyBy('name');

            return $r->url() === self::MEDIA_URL && $r->isMultipart() && $parts['type']['contents'] === 'audio/ogg'
                && $parts['messaging_product']['contents'] === 'whatsapp' && $r->hasFile('file', null, 'voice-note.ogg');
        });
        Http::assertSent(fn (Request $r) => $r->url() === self::MESSAGES_URL && $r['type'] === 'audio'
            && $r['audio'] === ['id' => '7731450912836654', 'voice' => true] && !isset($r['audio']['link']));

        $row->refresh();
        $this->assertSame(['sent', 'wamid.VOICE.1', '7731450912836654', '🎤 رسالة صوتية من الموظف (0:42)'], [$row->status, $row->wamid, $row->media_id, $row->body]);
        $this->assertSame(42.4, $row->payload['_voice']['seconds']);
        Queue::assertPushedOn('wa-media', TranscribeStaffVoice::class, fn ($job) => $job->messageId === $row->id);

        $audit = AuditLog::where('action', 'wa.file_sent')->sole();
        $this->assertSame($this->staff->id, $audit->user_id);
        $this->assertSame(['audio', 'audio/ogg', 'voice', 42, 16], [$audit->changes['type'], $audit->changes['mime'], $audit->changes['kind'],
            $audit->changes['seconds'], strlen($audit->changes['sha256'])]);
    }

    public function test_the_transcription_waits_for_its_own_flag(): void
    {
        Queue::fake();
        $this->fakeMeta();

        $this->postVoice()->assertStatus(202);
        (new SendWhatsAppMediaJob(WaMessage::sole()->id))->handle();

        $this->assertSame('sent', WaMessage::sole()->status);
        Queue::assertNotPushed(TranscribeStaffVoice::class);
    }

    public function test_a_closed_window_answers_422_without_ffmpeg_or_any_upload(): void
    {
        Queue::fake();
        Http::fake();
        $closed = $this->conversationTagged(['visa']);
        $closed->update(['last_inbound_at' => now()->subHours(30)]);

        $this->postVoice(conv: $closed)->assertStatus(422)->assertJsonPath('code', 'WINDOW_EXPIRED');

        $this->assertSame([], $this->converterCalls);
        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
    }

    public function test_flag_permission_and_scope_are_enforced_before_anything_runs(): void
    {
        Queue::fake();
        $viewer = $this->staffWith('viewer', ['whatsapp.view'], ['visa']);
        $outsider = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.reply'], ['umrah']);

        $this->postVoice(as: $viewer)->assertForbidden();
        $this->postVoice(as: $outsider)->assertNotFound();
        config(['features.env.staff_voice_notes' => false, 'features.env.staff_file_send' => true]);
        $this->postVoice()->assertNotFound();

        $this->assertSame([], $this->converterCalls);
        $this->assertSame(0, WaMessage::count());
        Queue::assertNothingPushed();
    }

    public function test_voice_notes_do_not_need_the_file_flag(): void
    {
        Queue::fake();
        config(['features.env.staff_file_send' => false]);

        $this->postVoice()->assertStatus(202);
    }

    public function test_the_same_key_converts_and_sends_once(): void
    {
        Queue::fake();

        $this->postVoice(key: null)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $first = $this->postVoice()->assertStatus(202);
        $again = $this->postVoice()->assertStatus(202)->assertJson(['ok' => true, 'status' => 'queued', 'replayed' => true]);

        $this->assertSame($first->json('row_id'), $again->json('row_id'));
        $this->assertSame(1, WaMessage::count());
        $this->assertCount(1, $this->encodeCalls(), 'النقرة الثانية لا تشغّل ffmpeg');
        Queue::assertPushed(SendWhatsAppMediaJob::class, 1);

        // بعد الإرسال: المفتاح نفسه يعيد 200 replayed، ولمحادثة أخرى يُرفض
        WaMessage::sole()->update(['status' => 'sent', 'wamid' => 'wamid.VOICE.1']);
        $this->postVoice()->assertOk()->assertJson(['ok' => true, 'replayed' => true, 'message_id' => 'wamid.VOICE.1']);
        $other = $this->conversationTagged(['visa']);
        $this->postVoice(conv: $other)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
        $this->assertCount(1, $this->encodeCalls());
    }

    public function test_the_server_checks_the_confirmed_recipient(): void
    {
        Queue::fake();
        $other = $this->conversationTagged(['visa']);

        $this->postVoice(data: ['confirm_conversation_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_MISMATCH');

        $this->assertSame([], $this->converterCalls);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_busy_converter_answers_503_in_arabic_and_keeps_nothing(): void
    {
        Queue::fake();
        $held = Cache::lock(MediaConverter::LOCK, 75);
        $held->get();
        try {
            $res = $this->postVoice()->assertStatus(503)->assertJsonPath('code', 'CONVERTER_BUSY');
        } finally {
            $held->release();
        }

        $res->assertHeader('Retry-After', '5');
        $this->assertSame('المحوّل مشغول، أعد المحاولة بعد ثوانٍ.', $res->json('error'));
        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], glob(MediaConverter::tmpDir().'/*') ?: []);
        // المحاولة التالية بالمفتاح نفسه تمر (لم يُحجز شيء)
        $this->postVoice()->assertStatus(202);
    }

    public function test_raw_limits_answer_413_and_422_before_converting(): void
    {
        Queue::fake();

        $this->postVoice($this->recording($this->oggOpusBytes(2, 3_000_100)))->assertStatus(413)->assertJsonPath('code', 'VOICE_UPLOAD_TOO_LARGE');
        $this->postVoice(UploadedFile::fake()->createWithContent('voice.ogg', $this->pdfBytes()))->assertStatus(422)->assertJsonPath('code', 'VOICE_INPUT_TYPE');
        $mp3 = 'ID3'."\x04\x00\x00\x00\x00\x00\x00"."\xFF\xFB\x90\x64".str_repeat("\0", 400);
        $this->postVoice(UploadedFile::fake()->createWithContent('voice.webm', $mp3))->assertStatus(422)->assertJsonPath('code', 'VOICE_INPUT_TYPE');

        $this->assertSame([], $this->converterCalls);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_too_long_or_an_invalid_result_is_refused_with_arabic_text_and_no_stderr(): void
    {
        Queue::fake();

        $this->fakeConverter(inputSeconds: 'N/A', outputSeconds: 130.0);
        $this->postVoice()->assertStatus(422)->assertJsonPath('code', 'VOICE_TOO_LONG');

        $this->fakeConverter(channels: 2);
        $this->postVoice()->assertStatus(422)->assertJson(['code' => 'VOICE_INVALID', 'error' => 'الرسالة الصوتية يجب أن تكون بقناة واحدة (mono).']);

        $this->fakeConverter(encodeFailure: ['exit' => 1, 'err' => 'Invalid data found when processing input']);
        $res = $this->postVoice()->assertStatus(422)->assertJsonPath('code', 'VOICE_CONVERT_FAILED');
        $this->assertStringNotContainsString('Invalid data', $res->getContent());

        $this->assertSame(0, WaMessage::count());
        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        $this->assertSame([], glob(MediaConverter::tmpDir().'/*') ?: []);
    }

    public function test_without_ffmpeg_the_route_answers_503_and_the_inbox_hides_the_button(): void
    {
        Queue::fake();
        MediaConverter::fake(null);
        config(['whatsapp.voice.ffmpeg_path' => '/nonexistent/ffmpeg']);

        $this->postVoice()->assertStatus(503)->assertJsonPath('code', 'VOICE_UNAVAILABLE');
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_failed_send_keeps_the_private_copy_as_evidence(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v25.0/106540352242922/media' => Http::response(['id' => '7731450912836654']),
            'graph.facebook.com/v25.0/106540352242922/messages' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
        $this->postVoice()->assertStatus(202);
        $row = WaMessage::sole();

        (new SendWhatsAppMediaJob($row->id))->handle();

        $row->refresh();
        $this->assertSame('failed', $row->status);
        Storage::disk('wa_media')->assertExists($row->media_path);
        Queue::assertNotPushed(TranscribeStaffVoice::class);
    }
}

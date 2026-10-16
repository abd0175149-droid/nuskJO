<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\TranscribeStaffVoice;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaBotUsage;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotAnalytics;
use App\Services\WhatsApp\LlmCircuitBreaker;
use App\Services\WhatsApp\MessageTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesVoiceConverter;
use Tests\TestCase;

/**
 * تفريغ صوت الموظف للزملاء (E34.6): Gemini عبر GeminiProvider::transcribe والقاطع، بعد تأكيد الطبقة المدفوعة. النص في
 * payload._transcript وbody، واستهلاك voice_out خارج إحصاءات الرد الحي. anthropic أو أي تعذّر: _transcript_failed بلا نداء.
 */
class TranscribeStaffVoiceTest extends TestCase
{
    use ConfiguresWhatsApp;
    use FakesVoiceConverter;
    use RefreshDatabase;

    private const GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent';

    private WaConversation $conv;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        Http::preventStrayRequests();
        config(['features.env.staff_voice_transcript' => true]);
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
        $this->staff = User::factory()->create();
    }

    private function confirmTerms(): void
    {
        $s = WaBotSetting::current();
        WaBotSetting::whereKey($s->id)->update(['llm_terms_confirmed_at' => now(), 'llm_terms_fp' => MessageTranslator::termsFingerprint($s)]);
    }

    private function voiceRow(): WaMessage
    {
        $path = 'outbound/2026/10/01a14551-8a13-7000-b503-4fa1e6048f92.ogg';
        Storage::disk('wa_media')->put($path, $this->oggOpusBytes(1));

        return WaMessage::create([
            'conversation_id' => $this->conv->id, 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'audio', 'staff_id' => $this->staff->id,
            'body' => '🎤 رسالة صوتية من الموظف (0:12)', 'status' => 'sent', 'wamid' => 'wamid.VOICE.1',
            'payload' => ['_voice' => ['seconds' => 12.0], 'audio' => ['id' => '7731450912836654', 'voice' => true]],
            'media_mime' => 'audio/ogg', 'media_path' => $path, 'media_status' => 'stored', 'media_size' => 300,
        ]);
    }

    private function gemini(string $text, int $status = 200): void
    {
        Http::fake([self::GEMINI => $status === 200
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30]])
            : Http::response(['error' => ['code' => $status, 'message' => 'backend error']], $status)]);
    }

    public function test_with_gemini_the_transcript_is_written_for_colleagues_and_counted_apart(): void
    {
        $this->confirmTerms();
        $this->gemini('أهلاً، التأشيرة جاهزة ونرسلها لك اليوم.');
        $row = $this->voiceRow();

        (new TranscribeStaffVoice($row->id))->handle();

        $row->refresh();
        $this->assertSame('أهلاً، التأشيرة جاهزة ونرسلها لك اليوم.', $row->payload['_transcript']);
        $this->assertSame('🎤 (صوت الموظف) أهلاً، التأشيرة جاهزة ونرسلها لك اليوم.', $row->body);
        $this->assertArrayNotHasKey('_transcript_failed', $row->payload);
        // الحالة والملف كما هما، والبحث يرى النص (body_norm عبر Eloquent)
        $this->assertSame(['sent', 'wamid.VOICE.1'], [$row->status, $row->wamid]);
        $this->assertStringContainsString('التاشيره', (string) $row->getAttributes()['body_norm']);

        Http::assertSent(fn (Request $r) => $r->url() === self::GEMINI
            && data_get($r->data(), 'contents.0.parts.0.inlineData.mimeType') === 'audio/ogg');
        Http::assertSentCount(1);

        $usage = WaBotUsage::sole();
        $this->assertSame(['voice_out', 120, 30, $this->conv->id], [$usage->source, (int) $usage->prompt_tokens, (int) $usage->output_tokens, $usage->conversation_id]);
        // خارج إحصاءات الرد الحي
        $this->assertSame(0, BotAnalytics::usage()['tiles']['replies']);
        $this->assertSame('closed', LlmCircuitBreaker::status('google')['state']);

        // متساوية الأثر: تشغيل ثانٍ لا يستدعي النموذج
        (new TranscribeStaffVoice($row->id))->handle();
        Http::assertSentCount(1);
    }

    public function test_anthropic_marks_the_transcript_failed_without_any_call(): void
    {
        $this->configureChannel(['provider' => 'anthropic', 'model' => 'claude-test']);
        Http::fake();
        $row = $this->voiceRow();

        (new TranscribeStaffVoice($row->id))->handle();

        $row->refresh();
        $this->assertSame('provider', $row->payload['_transcript_failed']);
        $this->assertSame('🎤 رسالة صوتية من الموظف (0:12)', $row->body);
        Http::assertNothingSent();
        $this->assertSame(0, WaBotUsage::count());
    }

    public function test_without_the_paid_tier_confirmation_nothing_leaves_the_server(): void
    {
        Http::fake();
        $row = $this->voiceRow();

        (new TranscribeStaffVoice($row->id))->handle();

        $this->assertSame('terms', $row->fresh()->payload['_transcript_failed']);
        Http::assertNothingSent();
    }

    public function test_the_flag_off_does_nothing(): void
    {
        config(['features.env.staff_voice_transcript' => false]);
        $this->confirmTerms();
        Http::fake();
        $row = $this->voiceRow();

        (new TranscribeStaffVoice($row->id))->handle();
        TranscribeStaffVoice::schedule($row->id);

        $this->assertArrayNotHasKey('_transcript_failed', $row->fresh()->payload);
        Http::assertNothingSent();
    }

    public function test_an_unintelligible_result_keeps_the_body(): void
    {
        $this->confirmTerms();
        $this->gemini('[غير مفهومة]');
        $row = $this->voiceRow();

        (new TranscribeStaffVoice($row->id))->handle();

        $row->refresh();
        $this->assertSame(['empty', '🎤 رسالة صوتية من الموظف (0:12)'], [$row->payload['_transcript_failed'], $row->body]);
    }

    public function test_a_provider_failure_is_counted_in_the_breaker_and_retried_once(): void
    {
        $this->confirmTerms();
        $this->gemini('', 503);
        $row = $this->voiceRow();

        $job = (new TranscribeStaffVoice($row->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(30);
        $this->assertArrayNotHasKey('_transcript_failed', $row->fresh()->payload);
        $this->assertSame(1, LlmCircuitBreaker::status('google')['failures']);
    }

    public function test_an_open_breaker_makes_no_call(): void
    {
        $this->confirmTerms();
        WaBotSetting::whereKey(WaBotSetting::current()->id)->update(['breaker_mode' => 'on']);
        LlmCircuitBreaker::forceOpen('google', 30, null);
        Http::fake();
        $row = $this->voiceRow();

        $job = (new TranscribeStaffVoice($row->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(120);
        Http::assertNothingSent();
    }

    public function test_only_staff_voice_rows_are_transcribed(): void
    {
        $this->confirmTerms();
        Http::fake();
        $inbound = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'audio', 'body' => '🎤 رسالة صوتية']);

        (new TranscribeStaffVoice($inbound->id))->handle();

        $this->assertSame('🎤 رسالة صوتية', $inbound->fresh()->body);
        Http::assertNothingSent();
    }
}

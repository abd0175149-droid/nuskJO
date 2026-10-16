<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\MediaConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\FakesVoiceConverter;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الرسالة الصوتية في الخيط (E34.7/E34.8): المدة والتفريغ للزملاء بلا حمولة خام، والملف من مسار E14 لمن يملك
 * whatsapp.media، والتفريغ المتأخر يصل باستطلاع الحالات، وحالة played (حمولة status_played الرسمية) لا تتراجع.
 */
class VoiceThreadTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use ConfiguresWhatsApp;
    use FakesVoiceConverter;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PLAYED_WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBIyRUZFQkIyODRBNkExMTZGRDcA';

    private WaConversation $conv;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        Queue::fake();
        $this->configureChannel();
        config(['features.env.staff_voice_notes' => true]);
        $this->staff = $this->staffWith('voice-desk', ['whatsapp.view', 'whatsapp.reply', 'whatsapp.media'], ['visa']);
        $this->conv = $this->conversationTagged(['visa'], ['phone' => '16505551234', 'wa_phone' => '16505551234']);
    }

    protected function tearDown(): void
    {
        $this->tearDownVoiceConverter();
        parent::tearDown();
    }

    private function voiceRow(array $payload = [], array $attrs = []): WaMessage
    {
        $path = 'outbound/2026/10/01a14551-8a13-7000-b503-4fa1e6048f92.ogg';
        Storage::disk('wa_media')->put($path, $this->oggOpusBytes(1));

        return WaMessage::create($attrs + [
            'conversation_id' => $this->conv->id, 'direction' => 'out', 'source' => 'staff', 'msg_type' => 'audio', 'staff_id' => $this->staff->id,
            'body' => '🎤 رسالة صوتية من الموظف (0:42)', 'status' => 'sent', 'wamid' => self::PLAYED_WAMID,
            'payload' => $payload + ['_voice' => ['seconds' => 42.4], 'audio' => ['id' => '7731450912836654', 'voice' => true]],
            'media_mime' => 'audio/ogg', 'media_path' => $path, 'media_status' => 'stored', 'media_size' => 300, 'media_filename' => 'voice-note.ogg',
        ]);
    }

    private function thread(?User $as = null, string $query = '')
    {
        return $this->actingAs($as ?? $this->staff)->getJson("/api/whatsapp/conversations/{$this->conv->id}/messages{$query}")->assertOk();
    }

    public function test_the_thread_carries_duration_transcript_and_a_playable_media_field_without_raw_payload(): void
    {
        $row = $this->voiceRow(['_transcript' => 'التأشيرة جاهزة اليوم.'], ['body' => '🎤 (صوت الموظف) التأشيرة جاهزة اليوم.']);

        $m = $this->thread()->json('messages.0');

        $this->assertSame(['seconds' => 42, 'transcript' => 'التأشيرة جاهزة اليوم.', 'transcript_failed' => null], $m['voice']);
        $this->assertSame(['audio', 'audio', 'stored', true], [$m['type'], $m['media']['kind'], $m['media']['status'], $m['media']['viewable']]);
        $this->assertArrayNotHasKey('payload', $m);
        $this->assertStringNotContainsString('outbound/', json_encode($m));
        $this->assertStringNotContainsString('7731450912836654', json_encode($m));

        // الملف نفسه من مسار E14 بنوعه (يُشغَّل في <audio>) وبتدقيقه
        $res = $this->actingAs($this->staff)->get("/api/whatsapp/messages/{$row->id}/media")->assertOk();
        $this->assertSame('audio/ogg', $res->headers->get('Content-Type'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'wa.media_view', 'entity_id' => $row->id]);
    }

    public function test_without_the_flag_the_row_stays_a_plain_audio_attachment(): void
    {
        config(['features.env.staff_voice_notes' => false]);
        $this->voiceRow();

        $m = $this->thread()->json('messages.0');

        $this->assertArrayNotHasKey('voice', $m);
        $this->assertSame('audio', $m['media']['kind']);
    }

    public function test_a_colleague_without_the_media_permission_reads_the_transcript_but_cannot_play(): void
    {
        $this->voiceRow(['_transcript_failed' => 'terms']);
        $reader = $this->staffWith('reader', ['whatsapp.view'], ['visa']);

        $m = $this->thread($reader)->json('messages.0');

        $this->assertSame('terms', $m['voice']['transcript_failed']);
        $this->assertFalse($m['media']['viewable']);
    }

    public function test_a_late_transcript_reaches_an_open_thread_through_the_status_poll(): void
    {
        $row = $this->voiceRow();
        $since = now()->subSeconds(5)->format('Y-m-d H:i:s');
        $this->travel(2)->seconds();
        $row->update(['body' => '🎤 (صوت الموظف) نرسل التذاكر غداً.', 'payload' => $row->payload + ['_transcript' => 'نرسل التذاكر غداً.']]);

        $updates = $this->thread(query: "?after={$row->id}&since=".urlencode($since))->json('updates');

        $this->assertSame([$row->id, '🎤 (صوت الموظف) نرسل التذاكر غداً.', 'نرسل التذاكر غداً.'],
            [$updates[0]['id'], $updates[0]['body'], $updates[0]['voice']['transcript']]);
    }

    public function test_the_inbox_offers_the_recorder_only_with_the_flag_reply_and_ffmpeg(): void
    {
        config(['features.env.inbox_v2' => true]);
        $this->fakeConverter();
        $page = fn (User $u) => $this->actingAs($u)->get('/whatsapp/inbox')->assertOk()->viewData('page')['props'];

        $this->assertSame(['enabled' => true, 'max_seconds' => 120, 'max_bytes' => 3000000], $page($this->staff)['voicePolicy']);
        $this->assertTrue($page($this->staff)['flags']['staff_voice_notes']);
        $this->assertNull($page($this->staffWith('viewer', ['whatsapp.view'], ['visa']))['voicePolicy']);

        MediaConverter::fake(null);
        config(['whatsapp.voice.ffmpeg_path' => '/nonexistent/ffmpeg']);
        $this->assertFalse($page($this->staff)['voicePolicy']['enabled']);

        config(['features.env.staff_voice_notes' => false]);
        $this->assertNull($page($this->staff)['voicePolicy']);
    }

    public static function statusPaths(): array
    {
        return ['legacy' => [false], 'delivery_status_v2' => [true]];
    }

    #[DataProvider('statusPaths')]
    public function test_played_from_the_official_payload_sticks_and_a_late_delivered_does_not_regress_it(bool $v2): void
    {
        config(['features.env.delivery_status_v2' => $v2]);
        $row = $this->voiceRow(attrs: ['status' => 'read']);

        $this->postMetaWebhook('status_played.json')->assertOk();
        $this->assertSame('played', $row->fresh()->status);

        $late = str_replace(['"played"', '"1760425920"'], ['"delivered"', '"1760425800"'], (string) file_get_contents($this->fixturePath('status_played.json')));
        $this->postRawWebhook($late)->assertOk();

        $this->assertSame('played', $row->fresh()->status);
    }
}

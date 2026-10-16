<?php

namespace Tests\Unit\Arch;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\VoiceNote;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * البوت لا يرسل صوتاً أبداً (E34): الرسالة الصوتية الأصلية من الموظف وحده. ثلاثة حراس: لا يذكرها كود البوت ولا مساعد
 * الإدارة ولا القواعد، وsendVoice بلا معامل مصدر، وdispatch يرفض حمولة audio بعلم voice لأي مصدر غير staff.
 */
class NoBotAudioTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    /** الملفات الوحيدة التي تبني رسالة صوتية أو ترسلها */
    private const ALLOWED = [
        'app/Services/WhatsApp/WhatsAppChannel.php',
        'app/Services/WhatsApp/Outbound/VoiceNote.php',
        'app/Jobs/SendWhatsAppMediaJob.php',
        'app/Http/Controllers/Concerns/SendsVoiceNotes.php',
    ];

    private const BOT_FILES = [
        'app/Services/WhatsApp/BotTools.php',
        'app/Services/WhatsApp/AdminTools.php',
        'app/Services/WhatsApp/BotEngine.php',
        'app/Services/WhatsApp/AutoRuleEngine.php',
        'app/Jobs/ProcessWhatsAppReply.php',
    ];

    private const PATTERN = '/sendVoice\s*\(|VoiceNote::message\s*\(|[\'"]voice[\'"]\s*=>\s*true/';

    public function test_the_bot_admin_assistant_and_rules_never_mention_a_voice_send(): void
    {
        foreach (self::BOT_FILES as $file) {
            $this->assertFileExists(base_path($file));
            $this->assertDoesNotMatchRegularExpression(self::PATTERN, (string) file_get_contents(base_path($file)), $file);
        }
        foreach (glob(app_path('Services/WhatsApp/Tools/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression(self::PATTERN, (string) file_get_contents($file), $file);
        }
    }

    public function test_only_the_staff_path_builds_a_voice_message(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen(base_path()) + 1);
            if ($file->getExtension() === 'php' && !in_array($relative, self::ALLOWED, true)
                && preg_match(self::PATTERN, (string) file_get_contents($file->getPathname()))) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found);
    }

    public function test_send_voice_takes_no_source(): void
    {
        $params = array_map(fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod(WhatsAppChannel::class, 'sendVoice'))->getParameters());

        $this->assertSame(['conv', 'mediaId', 'staffId', 'opts'], $params);
        $this->assertSame('int', (string) (new \ReflectionMethod(WhatsAppChannel::class, 'sendVoice'))->getParameters()[2]->getType());
    }

    public function test_dispatch_refuses_a_voice_payload_from_the_bot_or_the_system(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->configureChannel();
        $conv = WaConversation::factory()->windowOpen()->create();

        foreach (['bot', 'system', 'rule'] as $source) {
            $res = WhatsAppChannel::send($conv, VoiceNote::message('7731450912836654', 5), $source, null, ['send_key' => 'reply:test:'.$source]);
            $this->assertSame([false, WhatsAppChannel::ERR_UNSUPPORTED, 'الرسائل الصوتية من الموظف وحده.'], [$res['ok'], $res['code'], $res['error']], $source);

            $reserved = WhatsAppChannel::reserve($conv, VoiceNote::message(null, 5), $source, null, ['send_key' => 'reply:r:'.$source]);
            $this->assertFalse($reserved['ok'], $source);
        }

        Http::assertNothingSent();
        $this->assertSame(0, WaMessage::count());
    }

    public function test_the_staff_voice_payload_is_the_official_shape(): void
    {
        $payload = VoiceNote::message('7731450912836654', 42.4)->payload();

        $this->assertSame(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'type' => 'audio',
            'audio' => ['id' => '7731450912836654', 'voice' => true]], $payload);
        $this->assertSame('🎤 رسالة صوتية من الموظف (0:42)', VoiceNote::message('1', 42.4)->displayBody());
        $this->assertTrue(VoiceNote::message('1', 3)->isMedia());
        $this->assertNotNull(VoiceNote::message(null, 3)->validate(), 'بلا معرّف لا يُرسل');
        $this->assertNull(VoiceNote::message(null, 3)->validate(deferred: true), 'ويُحجز مؤجلاً');
    }

    public function test_a_staff_voice_note_is_sent_with_the_staff_source(): void
    {
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.VOICE.9']]])]);
        $this->configureChannel();
        $conv = WaConversation::factory()->windowOpen()->create();
        $staff = User::factory()->withPermissions(['whatsapp.view', 'whatsapp.reply', 'whatsapp.view_all'])->create();

        $res = WhatsAppChannel::sendVoice($conv, '7731450912836654', $staff->id, ['seconds' => 7, 'send_key' => 'staff:5b0e7c3a-91d2-4f6e-8a4b-3c2d1e0f9a87']);

        $this->assertTrue($res['ok']);
        $row = WaMessage::sole();
        $this->assertSame(['staff', 'audio', '🎤 رسالة صوتية من الموظف (0:07)', '7731450912836654'], [$row->source, $row->msg_type, $row->body, $row->media_id]);
    }
}

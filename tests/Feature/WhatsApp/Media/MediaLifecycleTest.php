<?php

namespace Tests\Feature\WhatsApp\Media;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Offer;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\Media\MediaConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * دورة حياة المرفقات (E14.9–E14.12، A8): التقليم والكنس والسقف، وزرّا الموافقة والرفض، وبطاقة العرض الصادرة،
 * وforget والتصدير، والصفحتان القانونيتان، وبطاقة الإعدادات، وقاعدة البوت.
 */
class MediaLifecycleTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    private const PHONE = '16505551234';

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wa_media');
        $this->configureChannel();
        User::factory()->admin()->create();
        $this->conv = WaConversation::factory()->windowOpen()->create(['phone' => self::PHONE, 'wa_phone' => self::PHONE]);
    }

    private function stored(array $attrs = [], string $path = '2026/10/a.jpg'): WaMessage
    {
        Storage::disk('wa_media')->put($path, 'BYTES');

        return WaMessage::factory()->inbound()->create($attrs + ['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'media_id' => 'm.'.uniqid(),
            'media_status' => 'stored', 'media_path' => $path, 'media_mime' => 'image/jpeg', 'media_size' => 5, 'media_archived_at' => now()]);
    }

    private function freshBackup(): void
    {
        BackupRun::create(['reason' => 'scheduled', 'status' => 'ok', 'finished_at' => now()->subHours(2)]);
    }

    private function press(string $buttonId): void
    {
        $payload = json_decode(file_get_contents($this->fixturePath('interactive_button_reply.json')), true);
        data_set($payload, 'entry.0.changes.0.value.messages.0.interactive.button_reply', ['id' => $buttonId, 'title' => 'زر']);
        data_set($payload, 'entry.0.changes.0.value.messages.0.id', 'wamid.BTN'.uniqid());
        data_set($payload, 'entry.0.changes.0.value.messages.0.timestamp', (string) now()->timestamp);
        $this->postRawWebhook(json_encode($payload))->assertOk();
    }

    // ==================== التقليم والكنس والسقف ====================

    public function test_prune_purges_old_files_only_after_a_fresh_backup(): void
    {
        $old = $this->stored(['media_archived_at' => now()->subDays(121)], '2026/06/old.jpg');
        $new = $this->stored([], '2026/10/new.jpg');

        Artisan::call('whatsapp:media-prune');
        $this->assertSame('stored', $old->fresh()->media_status, 'بلا نسخة حديثة لا شيء');

        Artisan::call('whatsapp:media-prune', ['--dry-run' => true]);
        $this->assertStringContainsString('سيُحذف 1', Artisan::output());

        $this->freshBackup();
        Artisan::call('whatsapp:media-prune');

        $this->assertSame(['purged', null, 'image/jpeg', 5], [$old->fresh()->media_status, $old->fresh()->media_path, $old->fresh()->media_mime, $old->fresh()->media_size]);
        $this->assertFalse(Storage::disk('wa_media')->exists('2026/06/old.jpg'));
        $this->assertSame('stored', $new->fresh()->media_status);
        $this->assertSame(['mode' => 'retention', 'days' => 120, 'files' => 1, 'bytes' => 5], AuditLog::where('action', 'wa.media_purge')->sole()->changes);
    }

    public function test_sweep_removes_old_temp_and_orphans_marks_missing_and_sets_the_quota_flag(): void
    {
        $tmpOld = MediaArchiver::tmpDir().'/old-test.part';
        $tmpNew = MediaArchiver::tmpDir().'/new-test.part';
        File::put($tmpOld, 'x');
        touch($tmpOld, time() - 7200);
        File::put($tmpNew, 'x');
        $disk = Storage::disk('wa_media');
        $disk->put('2026/10/orphan-old.jpg', 'x');
        touch($disk->path('2026/10/orphan-old.jpg'), time() - 7200);
        $disk->put('2026/10/orphan-new.jpg', 'x');
        $kept = $this->stored([], '2026/10/kept.jpg');
        touch($disk->path('2026/10/kept.jpg'), time() - 7200);
        $gone = WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'media_status' => 'stored', 'media_path' => '2026/10/gone.jpg', 'media_size' => 6 * 1048576]);
        config(['whatsapp.media.quota_mb' => 5]);
        $kept->update(['media_size' => 6 * 1048576]);

        Artisan::call('whatsapp:media-sweep');

        $this->assertFileDoesNotExist($tmpOld);
        $this->assertFileExists($tmpNew);
        $this->assertFalse($disk->exists('2026/10/orphan-old.jpg'));
        $this->assertTrue($disk->exists('2026/10/orphan-new.jpg'), 'الأحدث من ساعة قد يكون قيد النقل');
        $this->assertTrue($disk->exists('2026/10/kept.jpg'));
        $this->assertSame('missing', $gone->fresh()->media_status);
        $this->assertTrue(Cache::get('wa:media:quota_exceeded'));

        config(['whatsapp.media.quota_mb' => 100]);
        Artisan::call('whatsapp:media-sweep');
        $this->assertNull(Cache::get('wa:media:quota_exceeded'), 'العلامة تُرفع وحدها تحت السقف');
        File::delete($tmpNew);
    }

    // ==================== الإشعار والموافقة ====================

    public function test_the_notice_follows_the_first_stored_file_once_in_thirty_days(): void
    {
        config(['features.env.media_consent_prompt' => true]);
        $this->fakeGraph();

        $this->assertTrue(MediaConsent::maybeNotify($this->conv));
        $this->assertFalse(MediaConsent::maybeNotify($this->conv->fresh()));
        Cache::flush();
        $this->assertFalse(MediaConsent::maybeNotify($this->conv->fresh()), 'خلال 30 يوماً لا يتكرر');

        Http::assertSent(fn (Request $r) => data_get($r->data(), 'interactive.action.buttons.1.reply.id') === MediaConsent::NO
            && str_contains(data_get($r->data(), 'interactive.body.text'), '120 يوماً'));
        $this->assertSame(1, WaMessage::where('direction', 'out')->where('source', 'system')->count());
    }

    public function test_the_notice_is_off_by_default_and_never_reaches_admin_chats(): void
    {
        $this->fakeGraph();
        $this->assertFalse(MediaConsent::maybeNotify($this->conv));

        config(['features.env.media_consent_prompt' => true]);
        $this->conv->forceFill(['is_admin_chat' => true])->save();
        $this->assertFalse(MediaConsent::maybeNotify($this->conv->fresh()));
        Http::assertNothingSent();
    }

    public function test_agree_is_recorded_and_never_reaches_the_bot(): void
    {
        Queue::fake();
        $this->fakeGraph();

        $this->press(MediaConsent::YES);

        $this->assertSame('granted', $this->conv->fresh()->media_consent);
        $this->assertSame('yes', AuditLog::where('action', 'wa.media_consent')->sole()->changes['choice']);
        Queue::assertNothingPushed();
        $this->assertSame(0, WaMessage::where('direction', 'out')->count());
    }

    public function test_delete_my_files_erases_them_confirms_and_stops_later_archiving(): void
    {
        Queue::fake();
        $this->fakeGraph();
        config(['features.env.media_archive' => true]);
        $file = $this->stored([], '2026/10/passport.jpg');

        $this->press(MediaConsent::NO);

        $conv = $this->conv->fresh();
        $this->assertSame(['refused', true, 'media_refused'], [$conv->media_consent, $conv->needs_attention, $conv->attention_reason]);
        $this->assertFalse(Storage::disk('wa_media')->exists('2026/10/passport.jpg'));
        $this->assertSame(['purged', null], [$file->fresh()->media_status, $file->fresh()->media_path]);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), $file->media_id));
        $this->assertSame(MediaConsent::REFUSED_REPLY, WaMessage::where('direction', 'out')->sole()->body);
        Queue::assertNotPushed(ProcessWhatsAppReply::class);

        $this->postMetaWebhook('image_with_sha.json')->assertOk();
        $this->assertSame('skipped_consent', WaMessage::where('direction', 'in')->latest('id')->first()->media_status);
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
    }

    // ==================== بطاقة العرض الصادرة ====================

    public function test_a_sent_offer_card_is_archived_on_its_outbound_message(): void
    {
        // واتساب يقبل رابط صورة https وحده
        URL::forceRootUrl('https://nusk.test');
        URL::forceScheme('https');
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('offer-cards/test-card.png', $png);
        $offer = Offer::factory()->ready()->create(['custom_card_path' => 'offer-cards/test-card.png']);
        $this->fakeGraph();

        $first = BotTools::execute('send_offer_card', ['offer_id' => $offer->id], $this->conv);
        $this->assertTrue($first['sent'] ?? false, json_encode($first, JSON_UNESCAPED_UNICODE));
        $this->assertNull(WaMessage::where('direction', 'out')->sole()->media_status, 'العلم مطفأ');

        config(['features.env.media_archive' => true]);
        BotTools::execute('send_offer_card', ['offer_id' => $offer->id], $this->conv);

        $out = WaMessage::where('direction', 'out')->latest('id')->first();
        $this->assertSame(['stored', 'image/png'], [$out->media_status, $out->media_mime]);
        $this->assertSame($png, Storage::disk('wa_media')->get($out->media_path));
    }

    // ==================== A8: forget والتصدير ====================

    public function test_forget_deletes_the_archived_files(): void
    {
        $this->fakeGraph();
        config(['retention.erasure_ledger' => storage_path('framework/testing/erasure-ledger-media.jsonl'), 'retention.batch_sleep_ms' => 0]);
        $this->stored([], '2026/10/one.jpg');
        $this->stored(['msg_type' => 'document', 'media_mime' => 'application/pdf'], '2026/10/two.pdf');

        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id]);
        preg_match('/--token=(\S+)/', Artisan::output(), $m);
        Artisan::call('whatsapp:forget', ['--conversation' => $this->conv->id, '--token' => $m[1]]);
        File::delete(config('retention.erasure_ledger'));

        $this->assertSame([], Storage::disk('wa_media')->allFiles());
        $this->assertSame('erased', $this->conv->fresh()->status);
    }

    public function test_the_access_copy_lists_attachments_without_paths(): void
    {
        $this->stored([], '2026/10/one.jpg');
        Artisan::call('nusk:export-client', ['--phone' => self::PHONE]);
        $path = trim(explode("\n", trim(Artisan::output()))[0]);
        $json = File::get($path);
        File::delete($path);

        $data = json_decode($json, true);
        $this->assertSame(['image', 'image/jpeg', 5, true], [$data['attachments'][0]['kind'], $data['attachments'][0]['type'], $data['attachments'][0]['bytes'], $data['attachments'][0]['kept']]);
        $this->assertStringNotContainsString('2026/10/one.jpg', $json);
    }

    // ==================== الصفحتان والإعدادات والبوت ====================

    public function test_the_legal_pages_describe_attachments_only_while_archiving(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('المرفقات (صور ووثائق)');
        $this->get('/data-deletion')->assertOk()->assertDontSee('المرفقات (صور ووثائق)');

        config(['features.env.media_archive' => true]);
        $this->get('/privacy')->assertOk()->assertSee('المرفقات (صور ووثائق):')->assertSee('120 يوماً')->assertSee('يُسجَّل كل اطّلاع');
        $this->get('/data-deletion')->assertOk()->assertSee('المرفقات (صور ووثائق)')->assertSee('احذف ملفاتي');
    }

    public function test_the_settings_card_shows_counts_and_space_only(): void
    {
        $this->stored(['media_size' => 3 * 1048576]);
        WaMessage::factory()->inbound()->create(['conversation_id' => $this->conv->id, 'media_status' => 'too_large']);
        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();

        $this->actingAs($owner)->get('/whatsapp/settings')->assertInertia(fn ($page) => $page
            ->where('mediaArchive.enabled', false)->where('mediaArchive.used_mb', 3)
            ->where('mediaArchive.week.stored', 1)->where('mediaArchive.week.too_large', 1));
    }

    public function test_the_bot_never_asks_for_a_passport(): void
    {
        $this->assertStringContainsString('لا تطلب من العميل صورة جواز السفر أو الهوية ولا أرقامهما', BotEngine::SYSTEM_RULES);
    }
}

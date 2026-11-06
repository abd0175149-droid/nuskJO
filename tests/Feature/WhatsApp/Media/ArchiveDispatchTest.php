<?php

namespace Tests\Feature\WhatsApp\Media;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/** الجدولة عند الاستلام (E14.3): العلم، ومحادثات الإدارة، والرفض، والسقف. الحمولة معرّف الرسالة وحده، ومعرّف الوسيط معرّفها عند البوابة */
class ArchiveDispatchTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        User::factory()->admin()->create();
        config(['features.env.media_archive' => true]);
    }

    private function last(): WaMessage
    {
        return WaMessage::where('direction', 'in')->latest('id')->first();
    }

    public function test_an_image_is_queued_with_its_id_only(): void
    {
        $this->postMetaWebhook('image_with_sha.json')->assertOk();

        $m = $this->last();
        $this->assertSame(['pending', 'wamid.HBgLMTY1MDU1NTEyMzQVAgASGBRFMTRBMUIyQzNENEU1RjYwNzE4MgA=', 'image'], [$m->media_status, $m->media_id, $m->msg_type]);
        $this->assertSame($m->wamid, $m->media_id, 'يُجلب بمعرّف الرسالة');
        Queue::assertPushedOn('wa-media', ArchiveWhatsAppMedia::class, fn ($job) => $job->messageId === $m->id && $job->capOverride === null);
        Queue::assertPushed(ArchiveWhatsAppMedia::class, fn ($job) => !str_contains(serialize($job), '16505551234') && !str_contains(serialize($job), 'lookaside'));
    }

    public function test_a_document_is_queued_and_audio_is_not_by_default(): void
    {
        $this->postMetaWebhook('document_pdf.json')->assertOk();
        $this->assertSame('pending', $this->last()->media_status);

        $this->postMetaWebhook('audio.json')->assertOk();
        $this->assertNull($this->last()->media_status);
        Queue::assertPushed(ArchiveWhatsAppMedia::class, 1);
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        config(['features.env.media_archive' => false]);

        $this->postMetaWebhook('image_with_sha.json')->assertOk();

        $this->assertNull($this->last()->media_status);
        $this->assertSame('📷 صورة: الحجز', $this->last()->body);
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
    }

    public function test_an_admin_number_image_is_not_archived(): void
    {
        WaAdminNumber::factory()->create(['phone' => '16505551234']);

        $this->postMetaWebhook('image_with_sha.json')->assertOk();

        $this->assertNull($this->last()->media_status);
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
    }

    public function test_a_customer_who_refused_is_skipped(): void
    {
        WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234', 'media_consent' => 'refused']);

        $this->postMetaWebhook('image_with_sha.json')->assertOk();

        $this->assertSame('skipped_consent', $this->last()->media_status);
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
    }

    public function test_over_the_quota_is_skipped_with_one_alert(): void
    {
        Cache::put('wa:media:quota_exceeded', true, 3600);

        $this->postMetaWebhook('image_with_sha.json')->assertOk();
        $this->postMetaWebhook('document_pdf.json')->assertOk();

        $this->assertSame(['skipped_quota', 'skipped_quota'], WaMessage::where('direction', 'in')->orderBy('id')->pluck('media_status')->all());
        Queue::assertNotPushed(ArchiveWhatsAppMedia::class);
        $this->assertSame(1, Notification::where('title', 'like', '%سقفه%')->count());
    }
}

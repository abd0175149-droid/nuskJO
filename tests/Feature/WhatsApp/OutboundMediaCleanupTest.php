<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * الحذف من خوادم ميتا بعد التسليم (E32.12، بقرار المالك): بالعلم وحده، وللأنواع والحالات المضبوطة وحدها (الافتراضي:
 * المستندات بعد «قُرئت»)، لا ما بقي «أُرسلت»، ولا بطاقات البوت، ومرة واحدة لكل ملف.
 */
class OutboundMediaCleanupTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureChannel();
        $this->conv = WaConversation::factory()->windowOpen()->create();
    }

    /** لكل اختبار تزييفه (الأنماط تتراكم والأول يغلب) */
    private function fakeDelete(bool $ok = true): void
    {
        Http::fake(['graph.facebook.com/*' => $ok ? Http::response(['success' => true]) : Http::response(['error' => ['code' => 100]], 400)]);
    }

    private function sentFile(string $type, string $status, string $source = 'staff', int $ageMinutes = 120, string $mediaId = '1001'): WaMessage
    {
        $m = WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'msg_type' => $type, 'status' => $status,
            'source' => $source, 'media_id' => $mediaId, 'media_path' => 'outbound/2026/10/x.pdf', 'media_status' => 'stored']);
        WaMessage::whereKey($m->id)->update(['updated_at' => now()->subMinutes($ageMinutes)]);

        return $m->fresh();
    }

    public function test_nothing_happens_with_the_flag_off(): void
    {
        $this->fakeDelete();
        $doc = $this->sentFile('document', 'read');

        Artisan::call('whatsapp:outbound-media-cleanup');

        $this->assertNull($doc->fresh()->media_remote_deleted_at);
        Http::assertNothingSent();
    }

    public function test_only_read_documents_are_deleted_by_default(): void
    {
        $this->fakeDelete();
        config(['features.env.outbound_media_remote_delete' => true]);
        $read = $this->sentFile('document', 'read', mediaId: '2001');
        $sentOnly = $this->sentFile('document', 'sent', mediaId: '2002');
        $delivered = $this->sentFile('document', 'delivered', mediaId: '2003');
        $image = $this->sentFile('image', 'read', mediaId: '2004');
        $fresh = $this->sentFile('document', 'read', ageMinutes: 5, mediaId: '2005');

        Artisan::call('whatsapp:outbound-media-cleanup');
        Artisan::call('whatsapp:outbound-media-cleanup');

        $this->assertNotNull($read->fresh()->media_remote_deleted_at);
        foreach ([$sentOnly, $delivered, $image, $fresh] as $kept) {
            $this->assertNull($kept->fresh()->media_remote_deleted_at, $kept->media_id);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/2001?') && str_contains($r->url(), 'phone_number_id=106540352242922'));
        // نسختنا الخاصة باقية
        $this->assertSame('stored', $read->fresh()->media_status);
    }

    public function test_the_owner_widens_types_and_statuses_but_bot_cards_stay(): void
    {
        $this->fakeDelete();
        config(['features.env.outbound_media_remote_delete' => true,
            'whatsapp.outbound.remote_delete.types' => ['document', 'image'], 'whatsapp.outbound.remote_delete.statuses' => ['delivered', 'read']]);
        $image = $this->sentFile('image', 'delivered', mediaId: '3001');
        $card = $this->sentFile('image', 'read', source: 'bot', mediaId: '3002');
        $sentOnly = $this->sentFile('image', 'sent', mediaId: '3003');

        Artisan::call('whatsapp:outbound-media-cleanup');

        $this->assertNotNull($image->fresh()->media_remote_deleted_at);
        $this->assertNull($card->fresh()->media_remote_deleted_at);
        $this->assertNull($sentOnly->fresh()->media_remote_deleted_at);
        Http::assertSentCount(1);
    }

    public function test_a_failed_delete_is_counted_and_retried_later(): void
    {
        $this->fakeDelete(false);
        config(['features.env.outbound_media_remote_delete' => true]);
        $read = $this->sentFile('document', 'read');

        Artisan::call('whatsapp:outbound-media-cleanup');

        $this->assertNull($read->fresh()->media_remote_deleted_at);
        $this->assertStringContainsString('تعذّر: 1', Artisan::output());
    }

    public function test_dry_run_counts_only(): void
    {
        $this->fakeDelete();
        config(['features.env.outbound_media_remote_delete' => true]);
        $this->sentFile('document', 'read');

        Artisan::call('whatsapp:outbound-media-cleanup', ['--dry-run' => true]);

        $this->assertStringContainsString('[تجربة] سيُحذف من خوادم واتساب: 1', Artisan::output());
        Http::assertNothingSent();
    }
}

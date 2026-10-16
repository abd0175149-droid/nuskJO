<?php

namespace Tests\Feature\WhatsApp;

use App\Events\NewNotification;
use App\Events\WhatsApp\WaMessageFailed;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\OrphanStatus;
use App\Services\WhatsApp\SendKey;
use App\Services\WhatsApp\StatusApplier;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الإيصال اليتيم (E21): حالة وصلت قبل أن نحفظ معرّف الرسالة لا تضيع. تنتظر في الكاش عشر دقائق وتُطبَّق لحظة كتابة
 * wamid بعد ردّ ميتا، في المسار القديم وفي دفتر الإرسال، ثم يُحذف المفتاح. وبعد المهلة لا يبقى أثر يُطبَّق.
 */
class OrphanStatusTest extends TestCase
{
    use ConfiguresWhatsApp;
    use RefreshDatabase;
    use SignsMetaWebhooks;

    /** wamid حمولات status_sent/delivered/read.json */
    private const WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI0MkZERjYwREJFODBFQTIxNDEA';

    private const FAILED_WAMID = 'wamid.HBgLMTY1MDU1NTEyMzQVAgARGBJPUEFRVUVMRURHRVIwMDAyAA==';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->configureChannel();
        config(['features.env.delivery_status_v2' => true]);
    }

    /** ميتا تقبل الرسالة وتعيد هذا المعرّف */
    private function graphAccepts(string $wamid): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp',
            'contacts' => [['input' => '16505551234', 'wa_id' => '16505551234']], 'messages' => [['id' => $wamid]]])]);
    }

    public function test_an_early_delivered_waits_in_the_cache_and_applies_when_the_row_gets_its_id(): void
    {
        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertSame(0, WaMessage::count(), 'الإيصال لا ينشئ صفاً');
        $this->assertSame('delivered', Cache::get(OrphanStatus::PREFIX.self::WAMID)[0]['status']);

        $this->graphAccepts(self::WAMID);
        $conv = WaConversation::factory()->windowOpen()->create();
        $this->assertTrue(WhatsAppChannel::sendText($conv, 'مرحباً', 'system', sendKey: SendKey::optout('wamid.IN.1'))['ok']);

        $row = WaMessage::sole();
        $this->assertSame(['delivered', self::WAMID], [$row->status, $row->wamid]);
        $this->assertSame('delivered', $row->payload['statusHistory'][0]['s']);
        $this->assertNull(Cache::get(OrphanStatus::PREFIX.self::WAMID), 'المفتاح حُذف بعد التطبيق');
    }

    public function test_an_early_failure_without_opaque_value_applies_through_the_send_ledger(): void
    {
        config(['features.env.send_ledger' => true, 'features.env.meta_error_v2' => true]);
        Event::fake([NewNotification::class, WaMessageFailed::class]);
        $this->postMetaWebhook('status_failed_no_opaque.json')->assertOk();

        $this->graphAccepts(self::FAILED_WAMID);
        $conv = WaConversation::factory()->windowOpen()->create();
        WhatsAppChannel::sendText($conv, 'مرحباً', 'bot', sendKey: SendKey::reply($conv, 1, 'text'));

        $row = WaMessage::sole();
        $this->assertSame(['failed', 131047, 'WINDOW_EXPIRED'], [$row->status, $row->meta_code, $row->error_code]);
        $this->assertNotNull($row->sent_at, 'قبلت ميتا الطلب أولاً ثم فشل التسليم');
        Event::assertDispatched(WaMessageFailed::class, fn ($e) => $e->origin === 'webhook' && $e->messageId === $row->id);
        $this->assertSame('send_failed', $conv->fresh()->attention_reason);
    }

    public function test_an_orphan_expires_after_ten_minutes(): void
    {
        $this->postMetaWebhook('status_read.json')->assertOk();
        $this->travel(11)->minutes();

        $this->graphAccepts(self::WAMID);
        $conv = WaConversation::factory()->windowOpen()->create();
        WhatsAppChannel::sendText($conv, 'مرحباً', 'system', sendKey: SendKey::optout('wamid.IN.2'));

        $this->assertSame('sent', WaMessage::sole()->status, 'لا أثر قابل للتطبيق بعد المهلة');
    }

    public function test_the_cache_keeps_five_compact_items_without_phone_or_duplicates(): void
    {
        foreach ([['delivered', 1], ['delivered', 1], ['read', 2], ['sent', 3], ['played', 4], ['read', 5], ['delivered', 6]] as [$status, $ts]) {
            OrphanStatus::remember('wamid.X', ['id' => 'wamid.X', 'status' => $status, 'timestamp' => (string) $ts, 'recipient_id' => '16505551234',
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'to 16505551234']]]]);
        }

        $list = Cache::get(OrphanStatus::PREFIX.'wamid.X');
        $this->assertCount(OrphanStatus::MAX, $list);
        $this->assertSame([2, 3, 4, 5, 6], array_column($list, 'ts'));
        $this->assertStringNotContainsString('16505551234', json_encode($list));
    }

    public function test_draining_applies_in_metas_order_and_does_nothing_with_the_flag_off(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        $row = WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => 'wamid.Y', 'status' => 'sent']);
        OrphanStatus::remember('wamid.Y', ['status' => 'read', 'timestamp' => '20']);
        OrphanStatus::remember('wamid.Y', ['status' => 'delivered', 'timestamp' => '10']);

        config(['features.env.delivery_status_v2' => false]);
        $this->assertSame(0, OrphanStatus::drain('wamid.Y'));
        $this->assertSame('sent', $row->fresh()->status);

        config(['features.env.delivery_status_v2' => true]);
        $this->assertSame(2, OrphanStatus::drain('wamid.Y'));
        $this->assertSame('read', $row->fresh()->status);
        $this->assertSame(['delivered', 'read'], array_column($row->fresh()->payload['statusHistory'], 's'));
    }

    public function test_expired_orphan_keys_are_removed_by_the_daily_cache_prune(): void
    {
        config(['cache.default' => 'database']);
        OrphanStatus::remember('wamid.Z', ['status' => 'delivered', 'timestamp' => '1']);
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%wa:orphan-status:%')->count());

        // الأمر يقارن بـ time() (لا ساعة الاختبار): المفتاح انتهت صلاحيته
        DB::table('cache')->where('key', 'like', '%wa:orphan-status:%')->update(['expiration' => time() - 1]);
        Artisan::call('ops:prune-cache');

        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%wa:orphan-status:%')->count());
    }

    public function test_an_id_written_while_the_receipt_is_being_cached_still_gets_the_receipt(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create();
        // الإرسال يكتب المعرّف ويفرّغ الكاش بعد أن بحث الويبهوك عن الصف ولم يجده، وقبل أن يحفظ الإيصال
        $created = false;
        Event::listen(\Illuminate\Cache\Events\WritingKey::class, function ($e) use ($conv, &$created) {
            if (!$created && str_starts_with($e->key, OrphanStatus::PREFIX)) {
                $created = true;
                WaMessage::factory()->outbound()->create(['conversation_id' => $conv->id, 'wamid' => self::WAMID, 'status' => 'sent']);
            }
        });

        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertTrue($created);
        $this->assertSame('delivered', WaMessage::sole()->status);
        $this->assertNull(Cache::get(OrphanStatus::PREFIX.self::WAMID));
    }

    public function test_a_status_never_becomes_an_orphan_while_the_flag_is_off(): void
    {
        config(['features.env.delivery_status_v2' => false]);

        $this->postMetaWebhook('status_delivered.json')->assertOk();

        $this->assertFalse(StatusApplier::enabled());
        $this->assertNull(Cache::get(OrphanStatus::PREFIX.self::WAMID));
    }
}

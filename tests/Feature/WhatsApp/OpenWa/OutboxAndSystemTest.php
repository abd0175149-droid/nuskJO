<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Jobs\DispatchOutboxItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaMessageType;
use App\Models\WaOutbox;
use App\Models\WaSendLimit;
use App\Services\WhatsApp\Sending\LimitResolver;
use App\Services\WhatsApp\Sending\Outbox;
use App\Services\WhatsApp\System\Catalog;
use App\Services\WhatsApp\System\Scheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** الطابور ورسائل النظام: الحجز والإرسال، ومرحلة الاختبار، والإلغاء برد العميل، وإعادة الجدولة */
class OutboxAndSystemTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 11:00', 'Asia/Amman')->utc());
        $this->configureChannel();
        $this->fakeGateway();
        WaSendLimit::query()->update(['min_gap_sec' => 0, 'spread' => false]);
        LimitResolver::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tick(): void
    {
        $this->artisan('wa:outbox-tick')->assertSuccessful();
    }

    public function test_tick_claims_and_sends_a_due_text(): void
    {
        Outbox::put('t:1', ['type_key' => 'lead.followup', 'phone' => '962791112233', 'payload' => ['kind' => 'text', 'text' => 'متابعة'], 'due_at' => now()]);
        $this->tick();
        $o = WaOutbox::sole();
        $this->assertSame(WaOutbox::SENT, $o->status);
        $this->assertSame(['متابعة'], $this->sentTexts());
        $m = WaMessage::findOrFail($o->message_id);
        $this->assertSame('lead.followup', $m->type_key);
        $this->assertSame('system', $m->source);
        $this->assertSame('962791112233', WaConversation::findOrFail($o->conversation_id)->phone);
    }

    public function test_same_send_key_never_sends_twice(): void
    {
        Outbox::put('t:1', ['type_key' => 'lead.followup', 'phone' => '962791112233', 'payload' => ['kind' => 'text', 'text' => 'a'], 'due_at' => now()]);
        $this->tick();
        Outbox::put('t:1', ['type_key' => 'lead.followup', 'phone' => '962791112233', 'payload' => ['kind' => 'text', 'text' => 'b'], 'due_at' => now()]);
        $this->tick();
        $this->assertSame(['a'], $this->sentTexts());
        $this->assertSame(1, WaOutbox::count());
    }

    public function test_stale_lease_returns_to_deferred(): void
    {
        $o = Outbox::put('t:1', ['type_key' => 'lead.followup', 'phone' => '962791112233', 'payload' => ['kind' => 'text', 'text' => 'a'], 'due_at' => now()]);
        $o->forceFill(['status' => WaOutbox::SENDING, 'lease_until' => now()->subMinute()])->save();
        $this->tick();
        $this->assertSame(WaOutbox::SENT, $o->fresh()->status);
    }

    public function test_type_in_test_stage_goes_to_test_recipients_only(): void
    {
        WaBotSetting::current()->forceFill(['test_recipients' => json_encode(['962795550001'])])->save();
        $client = Client::factory()->create(['name' => 'سامي أحمد', 'phone' => '0791112233']);
        Scheduler::schedule('client.welcome', 'client', $client->id, now());
        $rows = WaOutbox::all();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->is_test);
        $this->assertSame('962795550001', $rows[0]->phone);
        $this->tick();
        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringStartsWith('🧪 [اختبار: ترحيب بعميل جديد', $texts[0]);
        $this->assertStringContainsString('أهلاً وسهلاً سامي', $texts[0]);
    }

    public function test_live_type_reaches_the_client(): void
    {
        WaMessageType::updateOrCreate(['key' => 'client.welcome'], ['stage' => 'live']);
        Catalog::flush();
        $client = Client::factory()->create(['name' => 'سامي', 'phone' => '0791112233']);
        $this->assertSame(1, WaOutbox::where('type_key', 'client.welcome')->where('phone', '962791112233')->where('is_test', false)->count(),
            'observer schedules the welcome on create');
        Carbon::setTestNow(now()->addMinutes(15));
        $this->testChannel()->forceFill(['last_probe_ok_at' => now()])->save();
        $this->tick();
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_customer_reply_cancels_pending_followups(): void
    {
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'مرحبا'))->assertOk();
        $conv = WaConversation::sole();
        Outbox::put('f:1', ['type_key' => 'lead.followup', 'conversation_id' => $conv->id, 'channel_id' => $conv->channel_id, 'phone' => '962791112233',
            'payload' => ['kind' => 'text', 'text' => 'هل ما زلت مهتماً؟'], 'due_at' => now()->addDay()]);
        $this->postOpenWa('message.received', $this->incoming('962791112233', 'نعم'))->assertOk();
        $this->assertSame(WaOutbox::CANCELLED, WaOutbox::sole()->status);
    }

    public function test_disabled_type_is_not_scheduled_and_open_rows_cancel(): void
    {
        $client = Client::factory()->create(['phone' => '0791112233']);
        WaMessageType::updateOrCreate(['key' => 'client.welcome'], ['enabled' => false]);
        Catalog::flush();
        WaOutbox::query()->delete();
        $this->assertNull(Scheduler::schedule('client.welcome', 'client', $client->id, now()));
        $this->assertSame(0, WaOutbox::count());
    }

    public function test_missing_variable_cancels_instead_of_sending_a_broken_message(): void
    {
        WaMessageType::updateOrCreate(['key' => 'client.welcome'], ['stage' => 'live', 'template' => 'أهلاً {client_name} رقم {receipt_number}']);
        Catalog::flush();
        $client = Client::factory()->create(['phone' => '0791112233']);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->testChannel()->forceFill(['last_probe_ok_at' => now()])->save();
        $this->tick();
        $this->assertSame([], $this->sentTexts());
        $this->assertSame(WaOutbox::CANCELLED, WaOutbox::where('type_key', 'client.welcome')->sole()->status);
    }

    public function test_occurrences_are_in_amman_time(): void
    {
        $at = Scheduler::occurrences('trip.reminder', '2026-11-20')[0];
        $this->assertSame('2026-11-17 11:00', $at->copy()->setTimezone('Asia/Amman')->format('Y-m-d H:i'));
    }
}

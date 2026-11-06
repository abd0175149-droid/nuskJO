<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Models\WaOptout;
use App\Models\WaOutbox;
use App\Models\WaSendLimit;
use App\Services\WhatsApp\Sending\FailureBreaker;
use App\Services\WhatsApp\Sending\LimitResolver;
use App\Services\WhatsApp\Sending\SendGovernor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/** محرك حدود الإرسال الاستباقي بزمن مجمّد: كل شرط في SendGovernor::decide على حدة */
class SendGovernorTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // الثلاثاء 11:00 بتوقيت عمّان: داخل النافذة الافتراضية 9–21
        Carbon::setTestNow(Carbon::parse('2026-11-10 11:00', 'Asia/Amman')->utc());
        $this->configureChannel();
        WaSendLimit::query()->whereNull('channel_id')->whereNull('type_key')->update(['min_gap_sec' => 0, 'spread' => false]);
        LimitResolver::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(array $over = []): WaOutbox
    {
        static $n = 0;
        $n++;

        return WaOutbox::create($over + ['type_key' => 'trip.reminder', 'phone' => '96279000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'payload' => ['kind' => 'text', 'text' => 'x'],
            'due_at' => now(), 'send_key' => 'k'.$n.uniqid(), 'channel_id' => $this->testChannel()->id, 'status' => WaOutbox::SENDING]);
    }

    private function sent(int $count, array $over = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->item($over + ['status' => WaOutbox::SENT, 'sent_at' => now()]);
        }
    }

    public function test_allows_a_fresh_item_inside_the_window(): void
    {
        $this->assertTrue(SendGovernor::decide($this->item(), $this->testChannel())->allowed());
    }

    public function test_outside_the_window_defers_to_its_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 22:30', 'Asia/Amman')->utc());
        $this->testChannel()->forceFill(['last_probe_ok_at' => now()])->save();
        $d = SendGovernor::decide($this->item(), $this->testChannel());
        $this->assertSame('defer', $d->action);
        $this->assertSame('window', $d->code);
        $this->assertSame('2026-11-11 09:00', $d->until->copy()->setTimezone('Asia/Amman')->format('Y-m-d H:i'));
    }

    public function test_type_hour_cap_defers_to_next_hour(): void
    {
        WaSendLimit::create(['type_key' => 'trip.reminder', 'per_hour' => 3]);
        LimitResolver::flush();
        $this->sent(3);
        $d = SendGovernor::decide($this->item(), $this->testChannel());
        $this->assertSame('cap_per_hour', $d->code);
        $this->assertSame('12:00', $d->until->copy()->setTimezone('Asia/Amman')->format('H:i'));
        // نوع آخر لا يتأثر
        $this->assertTrue(SendGovernor::decide($this->item(['type_key' => 'trip.eve']), $this->testChannel())->allowed());
    }

    public function test_channel_override_beats_the_type_default(): void
    {
        WaSendLimit::create(['type_key' => 'trip.reminder', 'per_hour' => 1]);
        WaSendLimit::create(['channel_id' => $this->testChannel()->id, 'type_key' => 'trip.reminder', 'per_hour' => 10]);
        LimitResolver::flush();
        $this->sent(2);
        $this->assertTrue(SendGovernor::decide($this->item(), $this->testChannel())->allowed());
    }

    public function test_warmup_ladder_caps_a_new_number(): void
    {
        $ch = $this->testChannel();
        $ch->forceFill(['warmup_started_at' => now()->subDay()])->save();
        WaSendLimit::query()->update(['per_minute' => null, 'per_hour' => null, 'per_recipient_day' => null]);
        LimitResolver::flush();
        $this->sent(40);
        $d = SendGovernor::decide($this->item(), $ch->fresh());
        $this->assertSame('cap_channel_day', $d->code);
    }

    public function test_recipient_daily_cap_counts_across_types(): void
    {
        $this->sent(3, ['phone' => '962791112233', 'type_key' => 'trip.eve']);
        $d = SendGovernor::decide($this->item(['phone' => '962791112233']), $this->testChannel());
        $this->assertSame('cap_recipient', $d->code);
    }

    public function test_gap_between_messages_on_the_number(): void
    {
        WaSendLimit::query()->whereNull('channel_id')->whereNull('type_key')->update(['min_gap_sec' => 60, 'jitter_pct' => 0]);
        LimitResolver::flush();
        $this->item(['status' => WaOutbox::SENT, 'sent_at' => now()->subSeconds(20)]);
        $d = SendGovernor::decide($this->item(), $this->testChannel());
        $this->assertSame('gap', $d->code);
        $this->assertSame(40, (int) now()->diffInSeconds($d->until));
    }

    public function test_optout_is_a_final_denial(): void
    {
        WaOptout::create(['phone' => '962791112233', 'reason' => 'x', 'created_at' => now()]);
        $this->assertSame('deny', SendGovernor::decide($this->item(['phone' => '962791112233']), $this->testChannel())->action);
    }

    public function test_expired_item_expires(): void
    {
        $this->assertSame('expire', SendGovernor::decide($this->item(['expires_at' => now()->subMinute()]), $this->testChannel())->action);
    }

    public function test_paused_or_offline_number_defers(): void
    {
        $ch = $this->testChannel();
        $ch->forceFill(['paused_until' => now()->addHour()])->save();
        $this->assertSame('channel_paused', SendGovernor::decide($this->item(), $ch->fresh())->code);
        $ch->forceFill(['paused_until' => null, 'status' => 'disconnected'])->save();
        $this->assertSame('channel_down', SendGovernor::decide($this->item(), $ch->fresh())->code);
    }

    public function test_breaker_defers(): void
    {
        $ch = $this->testChannel();
        for ($i = 0; $i < 5; $i++) {
            FailureBreaker::failure($ch, 'GW_ERROR');
        }
        $this->assertSame('breaker', SendGovernor::decide($this->item(), $ch)->code);
    }

    public function test_test_items_skip_caps_and_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30', 'Asia/Amman')->utc());
        $this->testChannel()->forceFill(['last_probe_ok_at' => now()])->save();
        $this->assertTrue(SendGovernor::decide($this->item(['is_test' => true]), $this->testChannel())->allowed());
    }
}

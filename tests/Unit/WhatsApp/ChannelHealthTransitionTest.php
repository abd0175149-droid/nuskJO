<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\HealthWriter;
use App\Enums\WaHealth;
use App\Events\WhatsApp\ChannelHealthChanged;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelHealth;
use App\Services\WhatsApp\Settings\SettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * جدول ملكية الانتقالات (E22): من كل حالة إلى كل حالة لكل كاتب، بفحص بشري وبدونه. المتوقع مكتوب هنا مستقلاً عن
 * ChannelHealth::ALLOWED. ما خارجه لا يكتب شيئاً ولا يطلق حدثاً؛ BLOCKED لا يخرج إلا بالمدير؛ ولا عودة إلى UNKNOWN.
 */
class ChannelHealthTransitionTest extends TestCase
{
    use RefreshDatabase;

    /** "من>إلى" => الكتّاب المسموحون (و* = الفحص يحتاج إنساناً) */
    private const EXPECTED = [
        'UNKNOWN>AVAILABLE' => ['poll'],
        'UNKNOWN>LIMITED' => ['webhook', 'poll'],
        'UNKNOWN>BLOCKED' => ['webhook', 'poll', 'send_error'],
        'UNKNOWN>TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        'AVAILABLE>LIMITED' => ['webhook', 'poll'],
        'AVAILABLE>BLOCKED' => ['webhook', 'poll', 'send_error'],
        'AVAILABLE>TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        'LIMITED>AVAILABLE' => ['poll'],
        'LIMITED>BLOCKED' => ['webhook', 'poll', 'send_error'],
        'LIMITED>TOKEN_INVALID' => ['send_error', 'poll', 'token_check'],
        'TOKEN_INVALID>AVAILABLE' => ['poll*'],
        'TOKEN_INVALID>LIMITED' => ['poll*'],
        'TOKEN_INVALID>BLOCKED' => ['webhook', 'poll', 'send_error'],
        'BLOCKED>AVAILABLE' => ['manual'],
        'BLOCKED>LIMITED' => ['manual'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.channel_health' => true]);
        Event::fake([ChannelHealthChanged::class]);
    }

    private function state(WaHealth $h): void
    {
        WaBotSetting::current()->forceFill(['wa_health' => $h->value, 'wa_health_reason' => 'start', 'wa_health_source' => 'poll'])->save();
    }

    private function expected(WaHealth $from, WaHealth $to, HealthWriter $by, bool $human): bool
    {
        $writers = self::EXPECTED[$from->value.'>'.$to->value] ?? [];

        return in_array($by->value, $writers, true) || ($human && in_array($by->value.'*', $writers, true));
    }

    public function test_every_transition_for_every_writer_matches_the_ownership_table(): void
    {
        $checked = 0;
        foreach (WaHealth::cases() as $from) {
            foreach (WaHealth::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                foreach (HealthWriter::cases() as $by) {
                    foreach ([false, true] as $human) {
                        $this->state($from);
                        $version = (int) WaBotSetting::current()->fresh()->settings_version;
                        Event::fake([ChannelHealthChanged::class]);

                        $moved = ChannelHealth::transition($to, $by, 'test:'.$by->value, null, null, $human);

                        $label = "{$from->value} → {$to->value} by {$by->value}".($human ? ' (human)' : '');
                        $want = $this->expected($from, $to, $by, $human);
                        $this->assertSame($want, $moved, $label);
                        $row = WaBotSetting::current()->fresh();
                        $this->assertSame($want ? $to->value : $from->value, $row->wa_health, $label);
                        $this->assertSame($want ? $version + 1 : $version, (int) $row->settings_version, $label);
                        $want ? Event::assertDispatchedTimes(ChannelHealthChanged::class, 1) : Event::assertNotDispatched(ChannelHealthChanged::class);
                        $checked++;
                    }
                }
            }
        }
        $this->assertSame(5 * 4 * 5 * 2, $checked);
    }

    public function test_nothing_returns_to_unknown_and_blocked_leaves_only_by_the_manager(): void
    {
        foreach (HealthWriter::cases() as $by) {
            $this->assertFalse(ChannelHealth::allows(WaHealth::Available, WaHealth::Unknown, $by, true));
            $this->assertSame($by === HealthWriter::Manual, ChannelHealth::allows(WaHealth::Blocked, WaHealth::Available, $by, true));
            $this->assertFalse(ChannelHealth::allows(WaHealth::Blocked, WaHealth::TokenInvalid, $by, true));
        }
    }

    public function test_a_real_transition_records_reason_source_and_until_and_fires_once(): void
    {
        $until = now()->addDays(3)->startOfMinute();

        $this->assertTrue(ChannelHealth::transition(WaHealth::Blocked, HealthWriter::Webhook, 'restriction:RESTRICTED_CUSTOMER_INITIATED_MESSAGING', 'قيد', $until));

        $s = WaBotSetting::current()->fresh();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertSame('webhook', $s->wa_health_source);
        $this->assertSame('restriction:RESTRICTED_CUSTOMER_INITIATED_MESSAGING — قيد', $s->wa_health_reason);
        $this->assertTrue($until->equalTo($s->wa_health_until));
        $this->assertNotNull($s->wa_health_changed_at);
        $this->assertFalse((bool) $s->wa_suspended);
        Event::assertDispatched(ChannelHealthChanged::class, fn ($e) => [$e->from, $e->to, $e->source, $e->reasonCode]
            === ['UNKNOWN', 'BLOCKED', 'webhook', 'restriction:RESTRICTED_CUSTOMER_INITIATED_MESSAGING']);
    }

    public function test_the_same_state_refreshes_until_without_an_event_or_a_new_version(): void
    {
        ChannelHealth::transition(WaHealth::Limited, HealthWriter::Webhook, 'restriction:A', null, now()->addDay());
        $version = (int) WaBotSetting::current()->fresh()->settings_version;
        Event::fake([ChannelHealthChanged::class]);

        $this->assertFalse(ChannelHealth::transition(WaHealth::Limited, HealthWriter::Webhook, 'restriction:B', null, now()->addDays(5)->startOfMinute()));

        $s = WaBotSetting::current()->fresh();
        $this->assertSame($version, (int) $s->settings_version);
        $this->assertTrue(now()->addDays(5)->startOfMinute()->equalTo($s->wa_health_until));
        $this->assertSame('restriction:B', $s->wa_health_meta['last_reason']);
        Event::assertNotDispatched(ChannelHealthChanged::class);
    }

    public function test_a_token_error_while_blocked_is_kept_in_the_summary_only(): void
    {
        $this->state(WaHealth::Blocked);

        $this->assertFalse(ChannelHealth::fromSendError(WaHealth::TokenInvalid, 'token:190'));

        $s = WaBotSetting::current()->fresh();
        $this->assertSame('BLOCKED', $s->wa_health);
        $this->assertSame('token:190', $s->wa_health_meta['token_while_blocked']);
    }

    public function test_the_conditional_write_does_not_overwrite_a_state_another_writer_changed(): void
    {
        $this->state(WaHealth::Blocked);

        $this->assertFalse(SettingsWriter::applyIf(['wa_health' => 'AVAILABLE'], ['wa_health' => 'LIMITED']));
        $this->assertSame('BLOCKED', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_with_the_flag_off_only_the_manager_can_move_the_state(): void
    {
        config(['features.env.channel_health' => false]);

        $this->assertFalse(ChannelHealth::transition(WaHealth::Blocked, HealthWriter::Webhook, 'ban:DISABLE'));
        $this->assertSame('UNKNOWN', WaBotSetting::current()->fresh()->wa_health);

        $this->state(WaHealth::Blocked);
        $this->assertTrue(ChannelHealth::transition(WaHealth::Available, HealthWriter::Manual, 'manual', 'رفع يدوي بعد مراجعة', null, true));
        $this->assertSame('AVAILABLE', WaBotSetting::current()->fresh()->wa_health);
    }

    public function test_only_blocked_and_flagged_token_invalid_block_sending(): void
    {
        foreach (WaHealth::cases() as $h) {
            $this->assertSame($h === WaHealth::Blocked, $h->blocksSending(), $h->value);
        }
        config(['features.env.channel_health_block_on_token' => true]);
        $this->assertTrue(WaHealth::TokenInvalid->blocksSending());
        $this->assertFalse(WaHealth::Limited->blocksSending());
    }
}

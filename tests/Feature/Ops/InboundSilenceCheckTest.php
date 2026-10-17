<?php

namespace Tests\Feature\Ops;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Ops\InboundSilence;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صمت الوارد في وقت الدوام (E37): لا حدث من ميتا منذ 180 دقيقة داخل ساعات الدوام، والساعة نفسها فيها حركة خلال
 * 14 يوماً، فيُنبَّه المدراء مرة كل 6 ساعات بلا رقم ولا نص عميل. خارج الدوام، وفي الأسبوع الهادئ، وبالعلم مطفأ: لا شيء.
 */
class InboundSilenceCheckTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = '📭 لا وارد من واتساب';

    private User $admin;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['features.env.inbound_silence_alert' => true]);
        $this->admin = User::factory()->admin()->create();
        $this->conv = WaConversation::factory()->create(['phone' => '16505551234']);
    }

    /** الأحد 2026-10-11 الساعة 11:00 بتوقيت عمّان */
    private function sundayMorning(): Carbon
    {
        return Carbon::parse('2026-10-11 11:00', 'Asia/Amman');
    }

    private function inbound(Carbon $at, string $body = 'نص العميل السري'): void
    {
        WaMessage::factory()->inbound()->create([
            'conversation_id' => $this->conv->id, 'body' => $body, 'created_at' => $at->copy()->utc(), 'updated_at' => $at->copy()->utc(),
        ]);
    }

    /** حركة معتادة: وارد قبل أسبوع في الساعات 11 و13 و17 */
    private function usualTraffic(Carbon $now): void
    {
        foreach ([11, 13, 17] as $hour) {
            $this->inbound($now->copy()->subDays(7)->setTime($hour, 10));
        }
    }

    private function alerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->where('title', self::TITLE)->count();
    }

    public function test_silence_in_business_hours_alerts_admins_once_every_six_hours(): void
    {
        $now = $this->sundayMorning();
        $this->usualTraffic($now);
        $this->inbound($now->copy()->subHours(4));
        $this->travelTo($now);

        $this->artisan('whatsapp:inbound-silence')->expectsOutputToContain('أُرسل تنبيه للمدراء')->assertSuccessful();

        $this->assertSame(1, $this->alerts());
        $note = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString('منذ 4 ساعة في وقت الدوام', $note->body);
        $this->assertSame(OpsAlerts::URL, $note->action_url);
        // بلا رقم ولا نص عميل
        $everything = json_encode($note->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('16505551234', $everything);
        $this->assertStringNotContainsString('نص العميل السري', $everything);

        // بعد ساعتين: الصمت مستمر، ولا تكرار
        $this->travelTo($now->copy()->addHours(2));
        $this->assertSame('already_alerted', InboundSilence::check());
        $this->assertSame(1, $this->alerts());

        // بعد 6 ساعات وخمس دقائق (17:05، وفي الساعة 17 حركة معتادة): تنبيه ثانٍ
        $this->travelTo($now->copy()->addHours(6)->addMinutes(5));
        $this->assertSame('alerted', InboundSilence::check());
        $this->assertSame(2, $this->alerts());
    }

    public static function offHours(): array
    {
        return [
            'friday 11:00' => ['2026-10-16 11:00'],
            'sunday 23:00' => ['2026-10-11 23:00'],
            'saturday 08:59' => ['2026-10-10 08:59'],
            'thursday 21:00' => ['2026-10-15 21:00'],
        ];
    }

    #[DataProvider('offHours')]
    public function test_nothing_outside_business_hours(string $amman): void
    {
        $now = Carbon::parse($amman, 'Asia/Amman');
        $this->inbound($now->copy()->subDays(7));
        $this->inbound($now->copy()->subHours(10));
        $this->travelTo($now);

        $this->assertSame('outside_hours', InboundSilence::check());
        $this->assertSame(0, $this->alerts());
    }

    public function test_business_hours_follow_the_config(): void
    {
        config(['ops.business_hours' => ['days' => [5], 'from' => '10:00', 'to' => '12:00', 'timezone' => 'Asia/Amman']]);

        $this->assertTrue(InboundSilence::inBusinessHours(Carbon::parse('2026-10-16 11:30', 'Asia/Amman')));
        $this->assertFalse(InboundSilence::inBusinessHours(Carbon::parse('2026-10-16 12:00', 'Asia/Amman')));
        $this->assertFalse(InboundSilence::inBusinessHours(Carbon::parse('2026-10-11 11:00', 'Asia/Amman')));
        // الساعة بتوقيت عمّان لا UTC: 08:30 UTC هي 11:30 في عمّان
        $this->assertTrue(InboundSilence::inBusinessHours(Carbon::parse('2026-10-16 08:30', 'UTC')));
    }

    public function test_the_flag_off_means_nothing(): void
    {
        config(['features.env.inbound_silence_alert' => false]);
        $now = $this->sundayMorning();
        $this->usualTraffic($now);
        $this->inbound($now->copy()->subHours(4));
        $this->travelTo($now);

        $this->artisan('whatsapp:inbound-silence')->expectsOutputToContain('مطفأ')->assertSuccessful();
        $this->assertSame(0, $this->alerts());
    }

    public function test_a_quiet_fortnight_means_nothing(): void
    {
        $now = $this->sundayMorning();
        // آخر حركة في هذه الساعة قبل 15 يوماً، خارج نافذة الـ 14
        $this->inbound($now->copy()->subDays(15)->setTime(11, 10));
        $this->inbound($now->copy()->subDays(7)->setTime(15, 10));
        $this->inbound($now->copy()->subHours(4));
        $this->travelTo($now);

        $this->assertSame('quiet_period', InboundSilence::check());
        $this->assertSame(0, $this->alerts());
    }

    public function test_any_meta_event_counts_and_the_threshold_is_configurable(): void
    {
        $now = $this->sundayMorning();
        $this->usualTraffic($now);
        $this->inbound($now->copy()->subHours(4));
        // حالة تسليم فقط (لا رسالة) قبل نصف ساعة: ميتا ما زالت ترسل لنا
        DB::table('wa_webhook_events')->insert([
            'received_at' => $now->copy()->subMinutes(30)->utc(), 'body_sha256' => str_repeat('a', 64),
            'status' => 'processed', 'field_summary' => 'statuses:1',
        ]);
        // ساعة الخادم بتوقيت UTC كما في الإنتاج
        $this->travelTo($now->copy()->utc());

        $this->assertSame(30, InboundSilence::minutesSinceLast());
        $this->assertSame('recent', InboundSilence::check());

        DB::table('wa_webhook_events')->delete();
        config(['ops.inbound_silence_minutes' => 300]);
        $this->assertSame('recent', InboundSilence::check(), '4 ساعات أقل من عتبة 300 دقيقة');

        config(['ops.inbound_silence_minutes' => 180]);
        $this->assertSame('alerted', InboundSilence::check());
        $this->assertSame(1, $this->alerts());
    }

    public function test_the_health_card_shows_minutes_without_touching_up(): void
    {
        $now = $this->sundayMorning();
        $this->travelTo($now);
        $this->assertNull(app(OpsHealth::class)->snapshot()['last_inbound_minutes']);

        $this->inbound($now->copy()->subMinutes(95));
        $snapshot = app(OpsHealth::class)->snapshot();

        $this->assertSame(95, $snapshot['last_inbound_minutes']);
        $this->assertNotContains('inbound_silence', $snapshot['codes']);

        $owner = User::factory()->withPermissions(['whatsapp.settings'], 'owner')->create();
        $this->actingAs($owner)->get('/whatsapp/settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('ops.last_inbound_minutes', 95));
    }

    public function test_the_check_is_scheduled_every_fifteen_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'whatsapp:inbound-silence'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaWebhookEvent;
use App\Services\Retention\ConversationEraser;
use App\Services\WhatsApp\WebhookIntake;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * الاحتفاظ (E20.8): الجسم المشفّر لا يبقى بعد 72 ساعة، والأثر الوصفي 14 يوماً، وحارس الخصوصية ينبّه إن بقي جسم،
 * و«حذف بياناتي» يحذف كل صف في جسمه رقم العميل أو معرّفه. ونص /privacy و/data-deletion يذكر المدة.
 */
class WebhookPruneTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private function stored(string $raw, array $attrs = []): WaWebhookEvent
    {
        [$event] = WebhookIntake::store($raw);
        $event->forceFill($attrs)->save();

        return $event->fresh();
    }

    private function payloadFrom(string $phone, string $wamid): string
    {
        $p = json_decode((string) file_get_contents($this->fixturePath('text.json')), true);
        $p['entry'][0]['changes'][0]['value']['messages'][0]['from'] = $phone;
        $p['entry'][0]['changes'][0]['value']['messages'][0]['id'] = $wamid;
        $p['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = $phone;

        return json_encode($p);
    }

    public function test_a_failed_body_is_wiped_after_its_window_and_the_row_kept(): void
    {
        $old = $this->stored($this->payloadFrom('16505551234', 'wamid.P1'), ['status' => WaWebhookEvent::FAILED, 'attempts' => 1]);
        $fresh = $this->stored($this->payloadFrom('16505553333', 'wamid.P2'), ['status' => WaWebhookEvent::FAILED, 'attempts' => 1]);
        $fresh->forceFill(['purge_after' => now()->addHours(80)])->save();

        $this->travel(73)->hours();
        Artisan::call('whatsapp:webhook-events-prune');

        $this->assertNull($old->fresh()->getRawOriginal('raw_body'));
        $this->assertSame(WaWebhookEvent::FAILED, $old->fresh()->status, 'الصف يبقى أثراً وصفياً');
        $this->assertNotNull($fresh->fresh()->getRawOriginal('raw_body'));
    }

    public function test_metadata_older_than_fourteen_days_is_deleted(): void
    {
        $old = $this->stored($this->payloadFrom('16505551234', 'wamid.P1'), ['status' => WaWebhookEvent::PROCESSED, 'raw_body' => null, 'received_at' => now()->subDays(15)]);
        $recent = $this->stored($this->payloadFrom('16505553333', 'wamid.P2'), ['status' => WaWebhookEvent::PROCESSED, 'raw_body' => null, 'received_at' => now()->subDays(13)]);

        Artisan::call('whatsapp:webhook-events-prune');

        $this->assertNull(WaWebhookEvent::find($old->id));
        $this->assertNotNull(WaWebhookEvent::find($recent->id));
    }

    public function test_a_processed_row_that_kept_its_body_is_wiped_at_once(): void
    {
        $row = $this->stored($this->payloadFrom('16505551234', 'wamid.P1'), ['status' => WaWebhookEvent::PROCESSED]);

        Artisan::call('whatsapp:webhook-events-prune');

        $this->assertNull($row->fresh()->getRawOriginal('raw_body'));
    }

    public function test_a_body_left_after_the_run_raises_the_privacy_alarm(): void
    {
        $admin = User::factory()->admin()->create();
        // purge_after خاطئ (أبعد من 72 ساعة): المحو لا يلتقطه، والحارس يلتقطه
        $this->stored($this->payloadFrom('16505551234', 'wamid.P1'), ['status' => WaWebhookEvent::DEAD, 'received_at' => now()->subHours(80), 'purge_after' => now()->addDay()]);

        $this->assertSame(1, Artisan::call('whatsapp:webhook-events-prune'));

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%بعد مدة الاحتفاظ%')->count());
    }

    public function test_forget_deletes_bodies_that_carry_the_customer_and_keeps_others(): void
    {
        $this->configureChannel();
        $this->fakeGraph();
        $conv = WaConversation::factory()->create(['phone' => '16505551234', 'wa_phone' => '16505551234']);
        $mine = $this->stored($this->payloadFrom('16505551234', 'wamid.P1'), ['status' => WaWebhookEvent::FAILED]);
        $other = $this->stored($this->payloadFrom('16505553333', 'wamid.P2'), ['status' => WaWebhookEvent::FAILED]);

        $eraser = new ConversationEraser;
        $plan = $eraser->plan($conv);
        $this->assertSame(1, $plan->counts['webhook_bodies']);
        $done = $eraser->execute($plan);

        $this->assertSame(1, $done['webhook_bodies']);
        $this->assertNull(WaWebhookEvent::find($mine->id));
        $this->assertNotNull(WaWebhookEvent::find($other->id));
    }

    public function test_the_legal_pages_state_the_window(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('إشعارات واتساب التي تعذّرت معالجتها')->assertSee('72 ساعة كحد أقصى');
        $this->get('/data-deletion')->assertOk()->assertSee('إشعارات واتساب التي تعذّرت معالجتها')->assertSee('72 hours at most', false);
    }

    public function test_the_prune_and_replay_commands_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression]);
        $replay = $events->first(fn ($e) => str_contains((string) $e[0], 'whatsapp:replay-webhooks'));
        $prune = $events->first(fn ($e) => str_contains((string) $e[0], 'whatsapp:webhook-events-prune'));

        $this->assertSame('* * * * *', $replay[1]);
        $this->assertSame('15 * * * *', $prune[1]);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('wa_webhook_events'));
    }
}

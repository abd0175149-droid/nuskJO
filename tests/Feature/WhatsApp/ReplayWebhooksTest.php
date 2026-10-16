<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Jobs\ProcessWhatsAppReply;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaWebhookEvent;
use App\Services\WhatsApp\WebhookIntake;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * whatsapp:replay-webhooks (E20.7): الفاشل يُعاد بعد إصلاح سببه ويُمحى جسمه، و3 محاولات ثم dead بإشعار مجمّع،
 * والمنقطع (pending) يُلتقط، والمعطوب والمرفوض لا يُلتقطان، و--dry-run لا يكتب ولا يطبع جسماً.
 * والوارد القديم المعاد لا يرد عليه البوت (بوابة الحداثة).
 */
class ReplayWebhooksTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    private bool $broken = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
        config(['features.env.inbound_event_log' => true]);
        WaMessage::creating(function () {
            if ($this->broken) {
                throw new \RuntimeException('علة مؤقتة');
            }
        });
    }

    private function raw(string $fixture = 'text.json'): string
    {
        return (string) file_get_contents($this->fixturePath($fixture));
    }

    private function stored(string $raw, array $attrs): WaWebhookEvent
    {
        [$event] = WebhookIntake::store($raw);
        $event->forceFill($attrs)->save();

        return $event->fresh();
    }

    public function test_a_failed_payload_is_processed_after_the_fix_and_its_body_wiped(): void
    {
        $this->broken = true;
        $this->postRawWebhook($this->raw())->assertOk();
        $event = WaWebhookEvent::sole();
        $this->assertSame([WaWebhookEvent::FAILED, 1], [$event->status, $event->attempts]);

        // قبل الموعد: لا شيء
        $this->broken = false;
        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(WaWebhookEvent::FAILED, $event->fresh()->status);

        $this->travel(3)->minutes();
        Artisan::call('whatsapp:replay-webhooks');

        $event->refresh();
        $this->assertSame([WaWebhookEvent::PROCESSED, 2], [$event->status, $event->attempts]);
        $this->assertNull($event->getRawOriginal('raw_body'));
        $this->assertSame(1, WaMessage::count());
        Queue::assertPushed(ProcessWhatsAppReply::class, 1);
    }

    public function test_three_failures_make_it_dead_with_one_alert_an_hour(): void
    {
        $admin = User::factory()->admin()->create();
        $this->broken = true;
        $this->postRawWebhook($this->raw())->assertOk();

        $this->travel(3)->minutes();
        Artisan::call('whatsapp:replay-webhooks');
        $event = WaWebhookEvent::sole();
        $this->assertSame([WaWebhookEvent::FAILED, 2], [$event->status, $event->attempts]);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $event->next_attempt_at->timestamp, 2);

        $this->travel(11)->minutes();
        Artisan::call('whatsapp:replay-webhooks');
        $event->refresh();
        $this->assertSame([WaWebhookEvent::DEAD, 3], [$event->status, $event->attempts]);
        $this->assertNotNull($event->getRawOriginal('raw_body'), 'يبقى للمراجعة اليدوية حتى purge_after');

        // صف dead ثانٍ في الساعة نفسها: لا إشعار ثانٍ
        $other = json_encode(json_decode($this->raw(), true));
        $this->stored($other, ['status' => WaWebhookEvent::FAILED, 'attempts' => 2, 'next_attempt_at' => now()->subMinute()]);
        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(2, WaWebhookEvent::where('status', WaWebhookEvent::DEAD)->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%تعذّرت معالجة%')->count());

        // ولا يُلتقط بعدها
        $this->travel(1)->hours();
        $this->broken = false;
        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(0, WaMessage::count());
    }

    public function test_an_interrupted_pending_row_is_picked_up_after_two_minutes(): void
    {
        $event = $this->stored($this->raw(), []);
        $this->assertSame(WaWebhookEvent::PENDING, $event->status);

        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(WaWebhookEvent::PENDING, $event->fresh()->status, 'قد يكون الطلب ما زال يعالجها');

        $this->travel(3)->minutes();
        Artisan::call('whatsapp:replay-webhooks');
        $this->assertSame(WaWebhookEvent::PROCESSED, $event->fresh()->status);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_malformed_and_rejected_rows_are_never_picked(): void
    {
        $this->stored('{"not json', ['status' => WaWebhookEvent::MALFORMED, 'received_at' => now()->subHour()]);
        $this->stored($this->raw(), ['status' => WaWebhookEvent::REJECTED, 'raw_body' => null, 'received_at' => now()->subHour()]);

        Artisan::call('whatsapp:replay-webhooks');

        $this->assertSame(0, WaMessage::count());
        $this->assertSame([0, 0], WaWebhookEvent::orderBy('id')->pluck('attempts')->all());
    }

    public function test_dry_run_writes_nothing_and_prints_no_body(): void
    {
        $event = $this->stored($this->raw(), ['status' => WaWebhookEvent::FAILED, 'attempts' => 1, 'next_attempt_at' => now()->subMinute(), 'field_summary' => 'messages:1']);

        Artisan::call('whatsapp:replay-webhooks', ['--dry-run' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString("#{$event->id} failed attempts=1 messages:1", $out);
        $this->assertStringNotContainsString('16505551234', $out);
        $this->assertStringNotContainsString('messaging_product', $out);
        $this->assertSame([WaWebhookEvent::FAILED, 1], [$event->fresh()->status, $event->fresh()->attempts]);
        $this->assertSame(0, WaMessage::count());
    }

    public function test_a_single_row_respects_the_budget_unless_forced_and_is_audited(): void
    {
        $event = $this->stored($this->raw(), ['status' => WaWebhookEvent::DEAD, 'attempts' => 3]);

        $this->assertSame(1, Artisan::call('whatsapp:replay-webhooks', ['--id' => $event->id]));
        $this->assertSame(0, WaMessage::count());

        Artisan::call('whatsapp:replay-webhooks', ['--id' => $event->id, '--force' => true]);
        $this->assertSame(WaWebhookEvent::PROCESSED, $event->fresh()->status);
        $this->assertSame(1, WaMessage::count());
        $row = AuditLog::where('action', AuditAction::WaWebhookReplay->value)->sole();
        $this->assertSame([$event->id, 'system'], [$row->entity_id, $row->actor_type]);
        $this->assertStringNotContainsString('16505551234', json_encode($row->changes));
    }

    public function test_an_undecryptable_body_becomes_dead(): void
    {
        $event = $this->stored($this->raw(), ['status' => WaWebhookEvent::FAILED, 'attempts' => 1, 'next_attempt_at' => now()->subMinute()]);
        DB::table('wa_webhook_events')->where('id', $event->id)
            ->update(['raw_body' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('x')]);

        Artisan::call('whatsapp:replay-webhooks');

        $this->assertSame([WaWebhookEvent::DEAD, 'decrypt_failed'], [$event->fresh()->status, $event->fresh()->error]);
    }

    public function test_a_late_replayed_message_goes_to_staff_not_the_bot(): void
    {
        $payload = json_decode($this->raw(), true);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) now()->subMinutes(30)->timestamp;
        $this->stored(json_encode($payload), ['status' => WaWebhookEvent::FAILED, 'attempts' => 1, 'next_attempt_at' => now()->subMinute()]);

        Artisan::call('whatsapp:replay-webhooks');

        $this->fakeGemini('لن يُستدعى');
        Queue::pushed(ProcessWhatsAppReply::class)->first()->handle();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'generativelanguage'));
        $conv = WaConversation::sole();
        $this->assertTrue((bool) $conv->needs_attention);
        $this->assertSame('stale', $conv->attention_reason);
    }
}

<?php

namespace Tests\Feature\WhatsApp\OpenWa;

use App\Services\WhatsApp\OpenWa\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\TestCase;

/**
 * جلسة متوقفة على البوابة (تهيئة انتهت مهلتها بعد إعادة تشغيلها، 2026-11-07): نافذة الربط تشغّلها بدل انتظار رمز لا
 * يأتي، والمراقب يعيدها بعد 5 دقائق لا 30.
 */
class StoppedSessionTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase;

    private function stoppedGateway(): void
    {
        $this->fakeGateway([
            'nuskjo-openwa:2785/api/sessions/*/qr' => Http::response(['message' => 'Session not started'], 400),
            'nuskjo-openwa:2785/api/sessions/sess-1' => Http::response(['status' => 'disconnected', 'phone' => self::TEST_CHANNEL_PHONE]),
        ]);
    }

    public function test_the_link_dialog_starts_a_stopped_session_once(): void
    {
        Queue::fake();
        $this->configureChannel();
        $this->stoppedGateway();
        $ch = $this->testChannel();
        $ch->forceFill(['status' => 'disconnected'])->save();

        $this->assertSame(['ok' => true, 'qr' => null, 'status' => 'disconnected'], SessionManager::qr($ch));
        SessionManager::qr($ch);

        Queue::assertPushed(\Illuminate\Queue\CallQueuedClosure::class, 1);
        Queue::pushed(\Illuminate\Queue\CallQueuedClosure::class)->first()->handle(app());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sessions/sess-1/start'));
    }

    public function test_the_watch_restarts_a_stopped_number_after_five_minutes(): void
    {
        \Illuminate\Support\Sleep::fake();
        $this->configureChannel();
        $this->stoppedGateway();
        $ch = $this->testChannel();
        $ch->forceFill(['status' => 'disconnected', 'probe_failures' => 5, 'last_restart_at' => now()->subMinutes(6)])->save();

        $this->artisan('whatsapp:channel-watch')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sessions/sess-1/start'));
        $this->assertTrue($ch->refresh()->last_restart_at->isAfter(now()->subMinute()));
    }

    public function test_a_number_stuck_on_ready_keeps_the_longer_cooldown(): void
    {
        \Illuminate\Support\Sleep::fake();
        $this->configureChannel();
        $this->fakeGateway([
            'nuskjo-openwa:2785/api/sessions/sess-1' => Http::response(['status' => 'ready', 'phone' => self::TEST_CHANNEL_PHONE]),
            'nuskjo-openwa:2785/api/sessions/sess-1/contacts/check/*' => Http::response(['message' => 'boom'], 500),
        ]);
        $ch = $this->testChannel();
        $ch->forceFill(['probe_failures' => 5, 'last_restart_at' => now()->subMinutes(6)])->save();

        $this->artisan('whatsapp:channel-watch')->assertSuccessful();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/start'));
    }
}

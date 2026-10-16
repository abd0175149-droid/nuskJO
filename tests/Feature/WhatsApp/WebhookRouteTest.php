<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Models\WaMessage;
use App\Services\WhatsApp\WebhookIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresWhatsApp;
use Tests\Concerns\SignsMetaWebhooks;
use Tests\TestCase;

/**
 * مسار الويبهوك (E20.2–E20.3): الحجم قبل التوقيع، والتوقيع الفاشل وحده يُعدّ ويُدقَّق بالعيّنات وينبّه عند الكثرة،
 * ولا 429 أبداً. (خروج المسار من الجلسة في WebhookSessionlessTest.)
 */
class WebhookRouteTest extends TestCase
{
    use ConfiguresWhatsApp, RefreshDatabase, SignsMetaWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->fakeGraph();
        $this->configureChannel();
    }

    public function test_bad_signatures_are_counted_sampled_and_alerted_but_never_throttled(): void
    {
        $admin = User::factory()->admin()->create();
        config(['audit.extended' => true]);
        $raw = (string) file_get_contents($this->fixturePath('text.json'));

        for ($i = 0; $i < 25; $i++) {
            $this->postRawWebhook($raw, 'wrong-secret')->assertStatus(403);
        }

        $this->assertSame(['hour' => 25, 'day' => 25], WebhookIntake::badSignatureCounts());
        $this->assertLessThanOrEqual(10, AuditLog::where('action', AuditAction::WaWebhookBadSignature->value)->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%توقيعات ويبهوك%')->count());

        // الموقّع الصحيح بعدها مباشرة: 200، ولا يمس العدّاد
        $this->postRawWebhook($raw)->assertOk();
        $this->assertSame(25, WebhookIntake::badSignatureCounts()['hour']);
        $this->assertSame(1, WaMessage::count());
    }

    public function test_a_three_megabyte_signed_body_is_accepted(): void
    {
        $payload = json_decode((string) file_get_contents($this->fixturePath('text.json')), true);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = str_repeat('a', 3 * 1024 * 1024 - 2048);
        $raw = json_encode($payload);
        $this->assertGreaterThan(3 * 1024 * 1024 - 4096, strlen($raw));

        $this->postRawWebhook($raw)->assertOk();
        $this->assertSame(1, WaMessage::count());
    }

    public function test_a_body_over_the_limit_is_refused_before_the_signature_is_checked(): void
    {
        $raw = str_repeat('x', (int) config('whatsapp.webhook.max_bytes') + 1);

        // توقيع خاطئ عمداً: 413 لا 403، أي أن الحجم سبق حساب HMAC ولم يُعدّ توقيعاً فاشلاً
        $this->postRawWebhook($raw, 'wrong-secret')->assertStatus(413);
        $this->assertSame(0, WebhookIntake::badSignatureCounts()['hour']);
    }

    public function test_the_size_limit_never_goes_below_three_megabytes(): void
    {
        config(['whatsapp.webhook.max_bytes' => 1024]);
        $this->assertSame(4 * 1024 * 1024, (int) (require config_path('whatsapp.php'))['webhook']['max_bytes']);

        putenv('WA_WEBHOOK_MAX_BYTES=1000');
        try {
            $this->assertSame(3 * 1024 * 1024, (require config_path('whatsapp.php'))['webhook']['max_bytes']);
        } finally {
            putenv('WA_WEBHOOK_MAX_BYTES');
        }
    }
}

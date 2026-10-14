<?php

namespace Tests\Feature\Ops;

use App\Jobs\ProcessWhatsAppReply;
use App\Jobs\SendPushNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * وظيفة فشلت نهائياً: سطر سجل منقّى (بلا أسرار ولا أرقام)، وإشعار للمدراء مرة في الساعة لكل صنف،
 * يُدفع للجهاز مباشرة لا عبر الطابور الذي قد يكون هو المعطّل.
 */
class JobFailedAlertTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function failJob(object $command, string $message = 'boom'): void
    {
        $payload = json_encode([
            'uuid' => 'u-1', 'displayName' => $command::class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'data' => ['commandName' => $command::class, 'command' => serialize($command)],
        ]);

        event(new JobFailed('database', new SyncJob(app(), $payload, 'database', 'wa-replies'), new \RuntimeException($message)));
    }

    private function alerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->where('title', '⚠️ فشلت مهمة خلفية')->count();
    }

    public function test_a_failed_job_is_logged_without_secrets_or_numbers(): void
    {
        $this->captureLogs();

        $this->failJob(new ProcessWhatsAppReply(42, 'tok'), 'Gemini HTTP 500 for https://x.test/?key=SECRET123 while replying to +962790000000');

        $line = collect($this->logged)->firstWhere('message', 'job_failed');
        $this->assertSame('error', $line['level']);
        $this->assertSame('ProcessWhatsAppReply', $line['context']['job']);
        $this->assertSame(42, $line['context']['conversation_id']);
        $this->assertSame(\RuntimeException::class, $line['context']['exception']);
        $encoded = json_encode($this->logged, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET123', $encoded);
        $this->assertStringNotContainsString('962790000000', $encoded);
    }

    public function test_admins_get_one_alert_per_job_class_per_hour(): void
    {
        $this->failJob(new ProcessWhatsAppReply(1, 'a'));
        $this->failJob(new ProcessWhatsAppReply(2, 'b'));
        $this->assertSame(1, $this->alerts());

        $notification = Notification::where('user_id', $this->admin->id)->first();
        $this->assertSame('فشلت ProcessWhatsAppReply — راجع صحة النظام.', $notification->body);
        $this->assertSame('/whatsapp/settings#ops', $notification->action_url);

        $this->travel(61)->minutes();
        $this->failJob(new ProcessWhatsAppReply(3, 'c'));
        $this->assertSame(2, $this->alerts());
    }

    public function test_ops_alerts_push_directly_not_through_the_queue(): void
    {
        $this->failJob(new ProcessWhatsAppReply(1, 'a'));

        // TestCase يزيّف SendPushNotification عبر Bus
        Bus::assertNotDispatched(SendPushNotification::class);
    }

    public function test_ordinary_notifications_still_push_through_the_queue(): void
    {
        NotificationService::send($this->admin->id, 'عنوان', 'نص');

        Bus::assertDispatched(SendPushNotification::class, 1);
    }
}

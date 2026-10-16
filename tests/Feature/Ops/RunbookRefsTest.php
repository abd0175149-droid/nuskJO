<?php

namespace Tests\Feature\Ops;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ops\OpsAlerts;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\Ops\RunbookMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * كل تنبيه للمدراء يحيل إلى كتيّبه في docs/RUNBOOK.md (E25): الربط بأطول بادئة في config/runbooks.php، والنص
 * « — الكتيب RB-NN» بالعلم alert_runbook_refs وحده، بلا رقم عميل ولا نص رسالة.
 */
class RunbookRefsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function lastBody(): string
    {
        return (string) Notification::where('user_id', $this->admin->id)->latest('id')->value('body');
    }

    public function test_the_longest_matching_prefix_wins_on_segment_boundaries(): void
    {
        $this->assertSame('RB-02', RunbookMap::for('meta:send:190'));
        $this->assertSame('RB-03', RunbookMap::for('meta:send:368'));
        $this->assertSame('RB-13', RunbookMap::for('meta:send:1900'), 'بادئة 190 لا تلتقط 1900');
        $this->assertSame('RB-11', RunbookMap::for('jobfailed:ProcessWhatsAppReply'));
        $this->assertSame('RB-11', RunbookMap::for('health:queue_stale'));
        $this->assertSame('RB-02', RunbookMap::for('health:TOKEN_INVALID:TOKEN_EXPIRED'));
        $this->assertSame('RB-12', RunbookMap::for('health:BLOCKED:RESTRICTED'));
        $this->assertSame('RB-04', RunbookMap::for('wa:webhook-drift:ab12'));
        $this->assertSame('RB-04', RunbookMap::for('wa:webhook:dead'));
        $this->assertSame('RB-02', RunbookMap::for('meta:credentials:critical:token_invalid'));
        $this->assertSame('RB-12', RunbookMap::for('meta:channel:critical:blocked'));
        $this->assertNull(RunbookMap::for('newthing:x'));
        $this->assertNull(RunbookMap::for('metadata'), 'meta لا تلتقط metadata');
    }

    public function test_an_ops_alert_ends_with_its_runbook_only_with_the_flag(): void
    {
        $alerts = app(OpsAlerts::class);

        $alerts->notifyAdmins('jobfailed:Probe', '⚠️ فشلت مهمة خلفية', 'فشلت Probe — راجع صحة النظام.');
        $this->assertSame('فشلت Probe — راجع صحة النظام.', $this->lastBody(), 'العلم مطفأ: النص كما هو');

        config(['features.env.alert_runbook_refs' => true]);
        $alerts->notifyAdmins('jobfailed:Other', '⚠️ فشلت مهمة خلفية', 'فشلت Other — راجع صحة النظام.');
        $this->assertSame('فشلت Other — راجع صحة النظام. — الكتيب RB-11', $this->lastBody());

        // مفتاح بلا كتيّب: النص كما هو، ولا استثناء
        $alerts->notifyAdmins('newthing:x', 'عنوان', 'نص');
        $this->assertSame('نص', $this->lastBody());
    }

    public function test_a_failed_job_alert_carries_the_runbook_and_no_customer_data(): void
    {
        config(['features.env.alert_runbook_refs' => true]);
        $payload = json_encode([
            'uuid' => 'u-1', 'displayName' => ProcessWhatsAppReply::class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'data' => ['commandName' => ProcessWhatsAppReply::class, 'command' => serialize(new ProcessWhatsAppReply(42, 'tok'))],
        ]);
        event(new JobFailed('database', new SyncJob(app(), $payload, 'database', 'wa-replies'), new \RuntimeException('to +962790000000')));

        $body = $this->lastBody();
        $this->assertStringEndsWith(' — الكتيب RB-11', $body);
        $this->assertDoesNotMatchRegularExpression('/\d{6,}/', $body);
    }

    public function test_inbox_alerts_take_an_explicit_runbook(): void
    {
        WhatsAppInbox::notifyAdmins('⛔ إيقاف', 'نص', '/whatsapp/settings', 'RB-03');
        $this->assertSame('نص', $this->lastBody());

        config(['features.env.alert_runbook_refs' => true]);
        WhatsAppInbox::notifyAdmins('⛔ إيقاف', 'نص', '/whatsapp/settings', runbook: 'RB-03');
        $this->assertSame('نص — الكتيب RB-03', $this->lastBody());

        WhatsAppInbox::notifyAdmins('⛔ إيقاف', 'نص2', '/whatsapp/settings', 'كتيّب؟');
        $this->assertSame('نص2', $this->lastBody(), 'قيمة ليست RB-NN لا تُلحق');
        WhatsAppInbox::notifyAdmins('عميل ينتظر', 'نص3');
        $this->assertSame('نص3', $this->lastBody());
    }
}

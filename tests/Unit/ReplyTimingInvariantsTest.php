<?php

namespace Tests\Unit;

use App\Jobs\ArchiveWhatsAppMedia;
use App\Jobs\ProcessWhatsAppReply;
use App\Jobs\SendPushNotification;
use App\Services\WhatsApp\ReplyRun;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ثوابت التوقيت مرتبطة: لو انكسر ترتيبها عادت الردود الضائعة أو المزدوجة.
 *   المهلة 125 < stopwaitsecs 130 < stop_grace_period 140   (النشر لا يقتل رداً جارياً)
 *   المهلة 125 < القفل 180 < retry_after 240 < retryUntil 900 ≤ رمز الدمج 3600
 */
#[Group('INV-15')]
class ReplyTimingInvariantsTest extends TestCase
{
    private function supervisorStopwait(string $program): int
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

        return (int) $ini["program:{$program}"]['stopwaitsecs'];
    }

    private function composeGrace(): int
    {
        $this->assertSame(1, preg_match('/^\s+stop_grace_period:\s*(\d+)s\s*$/m', file_get_contents(base_path('docker-compose.yml')), $m));

        return (int) $m[1];
    }

    public function test_a_running_reply_survives_a_graceful_stop(): void
    {
        $job = new ProcessWhatsAppReply(1, 't');
        $stopwait = $this->supervisorStopwait('laravel-wa');

        $this->assertLessThan($stopwait, $job->timeout);
        $this->assertLessThan($this->composeGrace(), $stopwait);
        $this->assertFalse($job->failOnTimeout);
    }

    public function test_the_model_budget_and_final_send_fit_in_the_job(): void
    {
        // الإرسال إلى ميتا مهلته 25 ثانية بعد الميزانية
        $this->assertLessThanOrEqual((new ProcessWhatsAppReply(1, 't'))->timeout, ReplyRun::BUDGET_SECONDS + 25);
    }

    public function test_budget_thresholds_keep_their_order(): void
    {
        // الحلقة تتوقف قبل الإعادة، والإعادة قبل البطاقة، والبطاقة قبل الصوت، وكلها داخل الميزانية
        $order = [ReplyRun::MIN_TURN_SECONDS, ReplyRun::RETRY_MIN_SECONDS, ReplyRun::CARD_MIN_SECONDS, ReplyRun::VOICE_MIN_SECONDS, ReplyRun::BUDGET_SECONDS];
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertSame(count($order), count(array_unique($order)));

        // نداء بأطول مهلة بعد بدء دورة يبقى منه وقت للإرسال
        $this->assertGreaterThan(5, ReplyRun::MIN_TURN_SECONDS);
        $this->assertLessThan(ReplyRun::BUDGET_SECONDS, ReplyRun::CALL_TIMEOUT_SECONDS + ReplyRun::MIN_TURN_SECONDS);
        // أقصر تفريغ مسموح يترك للحلقة حصتها
        $this->assertGreaterThanOrEqual(5, ReplyRun::VOICE_MIN_SECONDS - ReplyRun::VOICE_RESERVE_SECONDS);
        $this->assertGreaterThan(ReplyRun::MIN_TURN_SECONDS, ReplyRun::VOICE_RESERVE_SECONDS);
    }

    public function test_lock_retry_and_debounce_outlast_the_job(): void
    {
        $job = new ProcessWhatsAppReply(1, 't');
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $retryUntil = now()->diffInSeconds($job->retryUntil());

        $this->assertLessThan(ProcessWhatsAppReply::LOCK_SECONDS, $job->timeout);
        $this->assertLessThan($retryAfter, ProcessWhatsAppReply::LOCK_SECONDS);
        $this->assertLessThan($retryUntil, $retryAfter);
        $this->assertLessThanOrEqual(ProcessWhatsAppReply::DEBOUNCE_MINUTES * 60, $retryUntil);
        $this->assertGreaterThan($job->timeout, ProcessWhatsAppReply::RUNNING_SECONDS);
    }

    public function test_a_running_media_download_survives_a_graceful_stop(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);
        $media = $ini['program:laravel-media'];
        preg_match('/--timeout=(\d+)/', $media['command'], $m);

        $this->assertStringContainsString('--queue=wa-media', $media['command']);
        $this->assertLessThan((int) $m[1], (new ArchiveWhatsAppMedia(1))->timeout);
        $this->assertLessThan((int) $media['stopwaitsecs'], (int) $m[1]);
        $this->assertLessThan($this->composeGrace(), (int) $media['stopwaitsecs']);
    }

    /** ملفات الموظف (E32): طابورها أولاً عند العامل نفسه، والوظيفة تحت مهلته، وعمر الطابور الأقصى تحت المنظّف (15 دقيقة) */
    public function test_an_outbound_file_job_fits_the_media_worker_and_the_reaper(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);
        $media = $ini['program:laravel-media'];
        preg_match('/--timeout=(\d+)/', $media['command'], $m);
        preg_match('/--queue=([\w,-]+)/', $media['command'], $q);
        $job = new \App\Jobs\SendWhatsAppMediaJob(1);

        $this->assertSame(['wa-media-out', 'wa-media'], explode(',', $q[1]), 'ملفات الموظف قبل أرشفة الوارد');
        $this->assertSame('wa-media-out', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertLessThan((int) $m[1], $job->timeout);
        $this->assertLessThan($job->timeout, (int) config('whatsapp.outbound.upload_timeout_max') + 25 + 5);
        $this->assertLessThan((int) config('whatsapp.send.reap_minutes', 15), (int) config('whatsapp.outbound.queue_max_minutes'));
    }

    public function test_the_queue_workers_stop_before_the_web_server(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

        foreach (['laravel-wa', 'laravel-queue'] as $worker) {
            $this->assertGreaterThan((int) $ini['program:nginx']['priority'], (int) $ini["program:{$worker}"]['priority'], $worker);
            $this->assertGreaterThan((int) $ini['program:php-fpm']['priority'], (int) $ini["program:{$worker}"]['priority'], $worker);
        }
    }

    public function test_replies_have_their_own_worker_and_the_default_worker_waits_for_a_push(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);
        $wa = $ini['program:laravel-wa']['command'];
        $default = $ini['program:laravel-queue']['command'];

        $this->assertSame('wa-replies', ProcessWhatsAppReply::queueName());
        $this->assertStringContainsString('--queue='.ProcessWhatsAppReply::queueName(), $wa);
        // retryUntil يحكم إعادة الرد، و--tries كان يُفشله بعد إطلاقات WithoutOverlapping
        $this->assertStringNotContainsString('--tries', $wa);
        $this->assertStringContainsString('--queue=default', $default);
        $this->assertStringNotContainsString('wa-replies', $default);

        $this->assertGreaterThan((new SendPushNotification(1, 't', 'b'))->timeout, $this->supervisorStopwait('laravel-queue'));
    }

    public function test_a_reply_is_dispatched_to_the_reply_queue(): void
    {
        Queue::fake();

        ProcessWhatsAppReply::schedule(7);

        Queue::assertPushedOn('wa-replies', ProcessWhatsAppReply::class);
    }
}

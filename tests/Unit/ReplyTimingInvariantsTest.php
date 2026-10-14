<?php

namespace Tests\Unit;

use App\Jobs\ProcessWhatsAppReply;
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
        $stopwait = $this->supervisorStopwait('laravel-queue');

        $this->assertLessThan($stopwait, $job->timeout);
        $this->assertLessThan($this->composeGrace(), $stopwait);
        $this->assertFalse($job->failOnTimeout);
    }

    public function test_the_model_budget_and_final_send_fit_in_the_job(): void
    {
        // الإرسال إلى ميتا مهلته 25 ثانية بعد الميزانية
        $this->assertLessThanOrEqual((new ProcessWhatsAppReply(1, 't'))->timeout, \App\Services\WhatsApp\ReplyRun::BUDGET_SECONDS + 25);
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

    public function test_the_queue_worker_stops_before_the_web_server(): void
    {
        $ini = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

        $this->assertGreaterThan((int) $ini['program:nginx']['priority'], (int) $ini['program:laravel-queue']['priority']);
        $this->assertGreaterThan((int) $ini['program:php-fpm']['priority'], (int) $ini['program:laravel-queue']['priority']);
    }
}

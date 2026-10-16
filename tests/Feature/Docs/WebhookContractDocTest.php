<?php

namespace Tests\Feature\Docs;

use App\Services\WhatsApp\WebhookStats;
use Tests\TestCase;

/**
 * docs/whatsapp-webhook-contract.md (E20.11): الأرقام بين «» تطابق config('whatsapp.webhook') وكتلة nginx،
 * فلا يتغير أحدهما دون الآخر.
 */
class WebhookContractDocTest extends TestCase
{
    private function doc(): string
    {
        return (string) file_get_contents(base_path('docs/whatsapp-webhook-contract.md'));
    }

    /** @return list<string> كل قيمة بين «» */
    private function quoted(): array
    {
        preg_match_all('/«([0-9]+)»/u', $this->doc(), $m);

        return $m[1];
    }

    public function test_every_number_in_the_contract_matches_the_configuration(): void
    {
        $c = config('whatsapp.webhook');
        $expected = [
            '3' => 'حد ميتا الأقصى',
            (string) ($c['max_bytes'] / 1024 / 1024) => 'max_bytes',
            (string) $c['bad_signature_alert'] => 'bad_signature_alert',
            (string) $c['body_hours'] => 'body_hours',
            (string) $c['meta_days'] => 'meta_days',
            (string) $c['pending_stale_minutes'] => 'pending_stale_minutes',
            (string) $c['max_attempts'] => 'max_attempts',
            (string) $c['retry_minutes'][0] => 'retry_minutes[0]',
            (string) $c['retry_minutes'][1] => 'retry_minutes[1]',
            (string) $c['time_limit'] => 'time_limit',
        ];

        $quoted = $this->quoted();
        foreach ($expected as $value => $key) {
            $this->assertContains((string) $value, $quoted, "القيمة {$value} ({$key}) غائبة عن الوثيقة بين «»");
        }
        foreach ($quoted as $value) {
            $this->assertArrayHasKey($value, $expected, "«{$value}» في الوثيقة لا يطابق أي إعداد");
        }
        $this->assertSame(4 * 1024 * 1024, $c['max_bytes']);
        $this->assertGreaterThanOrEqual(3 * 1024 * 1024, $c['max_bytes']);
    }

    public function test_nginx_and_php_timeouts_are_consistent_with_the_contract(): void
    {
        $nginx = WebhookStats::nginx();
        $this->assertSame('4m', $nginx['webhook_max']);
        $this->assertSame(100, $nginx['keepalive']);
        $this->assertStringContainsString('client_max_body_size 4m', $this->doc());

        // PHP في المتحكم أقل من كتلة nginx، وهي أقل من Cloudflare (100 ثانية)
        $conf = (string) file_get_contents(base_path('docker/nginx.conf'));
        preg_match('/location\s*=\s*\/api\/whatsapp\/webhook\s*\{([^}]*)\}/', $conf, $block);
        preg_match('/fastcgi_read_timeout\s+([0-9]+)s/', $block[1], $read);
        $this->assertLessThan((int) $read[1], (int) config('whatsapp.webhook.time_limit'));
        $this->assertLessThan(100, (int) $read[1]);
        $this->assertDoesNotMatchRegularExpression('/^\s*limit_req(_zone)?\s/m', $conf, 'لا حد معدّل على أي مسار');
    }

    public function test_the_contract_covers_every_answer_the_controller_gives(): void
    {
        foreach (['200', '403', '413', '503', 'لا 429 أبداً', 'Retry-After: 30', 'inbound_event_log', 'webhook_fence_enforce', 'whatsapp:replay-webhooks', 'whatsapp:webhook-events-prune'] as $needle) {
            $this->assertStringContainsString($needle, $this->doc());
        }
    }
}

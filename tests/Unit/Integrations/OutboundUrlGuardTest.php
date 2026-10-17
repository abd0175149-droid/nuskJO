<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\GuardRejection;
use App\Services\Integrations\OutboundUrlGuard;
use App\Services\Integrations\ResolvedTarget;
use Tests\Concerns\BuildsIntegrations;
use Tests\TestCase;

/**
 * حارس الروابط الصادرة (E40.4): https وحده، بلا userinfo، والمنفذ 443، واسم مضيف لا عنوان، ولا مضيف داخلي ولا مضيفنا،
 * ثم كل عنوان محلول عام وإلا رُفض الرابط كله. والعنوان العام الأول يُعاد للتثبيت.
 */
class OutboundUrlGuardTest extends TestCase
{
    use BuildsIntegrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.self_hosts' => ['nusk.example.org'], 'integrations.allowed_ports' => [], 'integrations.internal_allow' => null]);
    }

    private function check(string $url, array $dns = []): ResolvedTarget|GuardRejection
    {
        $this->fakeDns($dns);

        return app(OutboundUrlGuard::class)->check($url);
    }

    private function assertRejected(string $code, string $url, array $dns = []): void
    {
        $result = $this->check($url, $dns);
        $this->assertInstanceOf(GuardRejection::class, $result, $url);
        $this->assertSame($code, $result->code, $url);
        $this->assertNotSame('', $result->message());
    }

    public function test_shape_rules_reject_before_any_lookup(): void
    {
        $this->assertRejected('not_https', 'http://x.com/hook');
        $this->assertRejected('userinfo', 'https://user:pw@x.com/hook');
        $this->assertRejected('ip_literal', 'https://127.0.0.1/hook');
        $this->assertRejected('ip_literal', 'https://[::1]/hook');
        $this->assertRejected('ip_literal', 'https://2130706433/hook');
        $this->assertRejected('port', 'https://x.com:8443/hook');
        $this->assertRejected('self_host', 'https://nusk.example.org/webhooks/whatsapp');
        $this->assertRejected('internal_host', 'https://localhost/hook');
        $this->assertRejected('internal_host', 'https://n8n/hook');
        $this->assertRejected('internal_host', 'https://printer.local/hook');
        $this->assertRejected('internal_host', 'https://svc.internal/hook');
        $this->assertRejected('too_long', 'https://x.com/'.str_repeat('a', 520));
        $this->assertRejected('invalid', 'not a url');
    }

    public function test_an_allowed_port_from_the_environment_passes(): void
    {
        config(['integrations.allowed_ports' => [8443]]);

        $this->assertInstanceOf(ResolvedTarget::class, $this->check('https://x.com:8443/hook'));
    }

    public function test_hosts_resolving_to_private_reserved_or_mapped_addresses_are_rejected(): void
    {
        foreach (['10.0.0.5', '169.254.169.254', '100.64.1.1', '127.0.0.1', '192.168.1.10', '172.16.4.4', '0.0.0.0', '198.18.0.1', '224.0.0.1', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1'] as $ip) {
            $this->assertRejected('private_address', 'https://hooks.example.com/x', ['hooks.example.com' => [$ip]]);
        }
    }

    public function test_one_private_address_among_public_ones_rejects_the_host(): void
    {
        $this->assertRejected('private_address', 'https://hooks.example.com/x', ['hooks.example.com' => [self::PUBLIC_IP, '10.0.0.5']]);
    }

    public function test_an_unresolvable_host_is_rejected(): void
    {
        $this->assertRejected('dns_failed', 'https://hooks.example.com/x', ['hooks.example.com' => []]);
    }

    public function test_a_public_host_returns_the_pinned_target(): void
    {
        $target = $this->check('https://hooks.example.com/webhook/abc?x=1', ['hooks.example.com' => ['2606:2800:220:1::1', self::PUBLIC_IP]]);

        $this->assertInstanceOf(ResolvedTarget::class, $target);
        $this->assertSame('hooks.example.com', $target->host);
        $this->assertSame(self::PUBLIC_IP, $target->ip, 'IPv4 أولاً');
        $this->assertSame(443, $target->port);
        $this->assertSame('hooks.example.com:443:'.self::PUBLIC_IP, $target->curlResolve());
        $this->assertSame('h:443:[2606:2800:220:1::1]', (new ResolvedTarget('https://h/', 'h', '2606:2800:220:1::1', 443))->curlResolve());
    }

    public function test_the_internal_exception_comes_from_the_environment_for_one_exact_host_and_port(): void
    {
        config(['integrations.internal_allow' => 'n8n.internal:5678']);

        $target = $this->check('https://n8n.internal:5678/webhook/x', ['n8n.internal' => ['172.18.0.7']]);
        $this->assertInstanceOf(ResolvedTarget::class, $target);
        $this->assertTrue($target->internalException);
        $this->assertSame('172.18.0.7', $target->ip);

        $this->assertRejected('port', 'https://n8n.internal:5679/webhook/x', ['n8n.internal' => ['172.18.0.7']]);
        $this->assertRejected('private_address', 'https://other.example.com/x', ['other.example.com' => ['172.18.0.7']]);
    }

    public function test_cidr_matching(): void
    {
        $this->assertTrue(OutboundUrlGuard::inCidr('100.127.255.255', '100.64.0.0/10'));
        $this->assertFalse(OutboundUrlGuard::inCidr('100.128.0.0', '100.64.0.0/10'));
        $this->assertTrue(OutboundUrlGuard::inCidr('fdff::1', 'fc00::/7'));
        $this->assertFalse(OutboundUrlGuard::inCidr('10.0.0.1', 'fc00::/7'));
        $this->assertTrue(OutboundUrlGuard::isPublic(self::PUBLIC_IP));
        $this->assertFalse(OutboundUrlGuard::isPublic('not-an-ip'));
    }
}

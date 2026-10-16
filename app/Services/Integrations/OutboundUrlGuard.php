<?php

namespace App\Services\Integrations;

/**
 * حارس الروابط الصادرة (E40، ومعه C171): يُستدعى عند حفظ الوجهة وعند كل تسليم، لأن الـ DNS قد يتغير.
 * https فقط، بلا userinfo، والمنفذ 443 وحده (أو المسموح من البيئة)، واسم مضيف لا عنوان IP، ولا مضيف داخلي ولا مضيفنا.
 * ثم تحليل DNS مرة واحدة: أي عنوان خاص أو محجوز أو loopback أو link-local أو CGNAT يرفض الرابط كله، والعنوان العام
 * الأول يُثبَّت للإرسال. الاستثناء الداخلي الوحيد host:port من البيئة (INTEGRATIONS_INTERNAL_ALLOW)، لا من الواجهة.
 */
class OutboundUrlGuard
{
    public const MAX_LENGTH = 512;

    /** نطاقات ممنوعة فوق FILTER_FLAG_NO_PRIV_RANGE وFILTER_FLAG_NO_RES_RANGE (تُفحص صراحة أيضاً) */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    private const INTERNAL_SUFFIXES = ['.local', '.internal', '.localhost', '.lan', '.home.arpa'];

    public function __construct(private readonly DnsResolver $dns) {}

    public function check(string $url): ResolvedTarget|GuardRejection
    {
        $url = trim($url);
        if (strlen($url) > self::MAX_LENGTH) {
            return new GuardRejection('too_long');
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return new GuardRejection('invalid');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return new GuardRejection('not_https');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return new GuardRejection('userinfo');
        }

        $host = rtrim(strtolower($parts['host']), '.');
        $port = (int) ($parts['port'] ?? 443);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || preg_match('/^[0-9.]+$/', $host)) {
            return new GuardRejection('ip_literal');
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            return new GuardRejection('invalid');
        }
        if (in_array($host, array_map('strtolower', (array) config('integrations.self_hosts', [])), true)) {
            return new GuardRejection('self_host');
        }

        $internal = $this->internalException($host, $port);
        if (!$internal) {
            if ($port !== 443 && !in_array($port, (array) config('integrations.allowed_ports', []), true)) {
                return new GuardRejection('port');
            }
            if ($host === 'localhost' || !str_contains($host, '.') || $this->hasInternalSuffix($host)) {
                return new GuardRejection('internal_host');
            }
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            return new GuardRejection('dns_failed');
        }
        if (!$internal) {
            foreach ($ips as $ip) {
                if (!self::isPublic($ip)) {
                    return new GuardRejection('private_address');
                }
            }
        }

        // IPv4 أولاً: الحاوية قد لا تملك مساراً لـ IPv6
        usort($ips, fn ($a, $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return new ResolvedTarget($url, $host, $ips[0], $port, $internal);
    }

    public static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** الاستثناء الداخلي من البيئة وحدها: host:port مطابق حرفياً */
    private function internalException(string $host, int $port): bool
    {
        $allow = strtolower(trim((string) config('integrations.internal_allow')));

        return $allow !== '' && $allow === "{$host}:{$port}";
    }

    private function hasInternalSuffix(string $host): bool
    {
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

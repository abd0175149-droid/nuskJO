<?php

namespace App\Services\Integrations;

/** هدف تسليم فُحص (E40): المضيف والعنوان المحلول والمنفذ. الإرسال يثبّت هذا العنوان (CURLOPT_RESOLVE) ضد DNS rebinding */
final class ResolvedTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly string $ip,
        public readonly int $port,
        public readonly bool $internalException = false,
    ) {}

    /** سطر CURLOPT_RESOLVE: host:port:ip، وعنوان IPv6 بين قوسين */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}

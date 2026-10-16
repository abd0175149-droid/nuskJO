<?php

namespace App\Services\Integrations;

/**
 * تحليل اسم المضيف إلى عناوينه (A وAAAA) مرة واحدة لكل فحص (E40). الاختبارات تستبدله بنسخة ثابتة
 * ($this->app->instance(DnsResolver::class, ...))، فلا نداء DNS حقيقي في الاختبار.
 */
class DnsResolver
{
    /** @return list<string> العناوين، أو [] إن تعذّر التحليل */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}

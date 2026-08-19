<?php

namespace App\Services\SeoAnalytics;

class SeoTechnicalDnsResolver
{
    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

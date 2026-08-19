<?php

namespace App\Services\SeoAnalytics;

use RuntimeException;

final class SeoTechnicalUrlGuard
{
    public function __construct(
        private readonly SeoTechnicalUrlNormalizer $normalizer,
        private readonly SeoTechnicalDnsResolver $dns,
    ) {}

    public function configured(): bool
    {
        return filled(config('seo_analytics.technical_health.site_url'));
    }

    public function siteOrigin(): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('SEO_TECHNICAL_SITE_URL no esta configurado.');
        }

        $url = $this->assertAllowed((string) config('seo_analytics.technical_health.site_url'));

        return $this->normalizer->origin($url);
    }

    /** @return array<int, string> */
    public function allowedHosts(): array
    {
        $site = $this->normalizer->normalize((string) config('seo_analytics.technical_health.site_url'));
        $hosts = [strtolower((string) parse_url($site, PHP_URL_HOST))];

        foreach ((array) config('seo_analytics.technical_health.allowed_hosts', []) as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '' || str_contains($host, '*') || str_contains($host, '://') || str_contains($host, '/')) {
                throw new RuntimeException('SEO_TECHNICAL_ALLOWED_HOSTS contiene un host invalido.');
            }
            $this->rejectLiteralOrLocalHost($host);
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    public function assertAllowed(string $url): string
    {
        $normalized = $this->normalizer->normalize($url);
        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));
        $port = parse_url($normalized, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        $this->rejectLiteralOrLocalHost($host);
        if (! in_array($host, $this->allowedHostsWithoutRecursion(), true)) {
            throw new RuntimeException('La URL tecnica utiliza un host no autorizado.');
        }
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new RuntimeException('La URL tecnica utiliza un puerto no permitido.');
        }

        return $normalized;
    }

    /** @return array{url: string, host: string, port: int, ip: string} */
    public function assertFetchable(string $url): array
    {
        $normalized = $this->assertAllowed($url);
        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));
        $port = (int) (parse_url($normalized, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));
        $addresses = $this->dns->resolve($host);
        if ($addresses === []) {
            throw new RuntimeException('El host tecnico no se puede resolver de forma segura.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new RuntimeException('El host tecnico resuelve a una direccion no publica.');
            }
        }

        return ['url' => $normalized, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** @return array<int, string> */
    private function allowedHostsWithoutRecursion(): array
    {
        $site = $this->normalizer->normalize((string) config('seo_analytics.technical_health.site_url'));
        $hosts = [strtolower((string) parse_url($site, PHP_URL_HOST))];
        foreach ((array) config('seo_analytics.technical_health.allowed_hosts', []) as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '' || str_contains($host, '*') || str_contains($host, '://') || str_contains($host, '/')) {
                throw new RuntimeException('SEO_TECHNICAL_ALLOWED_HOSTS contiene un host invalido.');
            }
            $this->rejectLiteralOrLocalHost($host);
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    private function rejectLiteralOrLocalHost(string $host): void
    {
        $host = trim($host, '[]');
        if ($host === ''
            || strlen($host) > 253
            || str_contains($host, '%')
            || str_ends_with($host, '.')
            || preg_match('/[^a-z0-9.-]/i', $host) === 1
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1
            || ! $this->hasCanonicalDnsLabels($host)
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('No se permiten hosts locales ni direcciones IP literales.');
        }
    }

    private function hasCanonicalDnsLabels(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }
}

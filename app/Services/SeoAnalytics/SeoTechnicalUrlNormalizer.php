<?php

namespace App\Services\SeoAnalytics;

use RuntimeException;

final class SeoTechnicalUrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('URL tecnica vacia o invalida.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new RuntimeException('URL tecnica invalida.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('La URL tecnica debe utilizar HTTP o HTTPS y contener host.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Las URLs tecnicas no pueden contener credenciales.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        if ($port !== null && $port !== $defaultPort) {
            $authority .= ':'.$port;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = array_key_exists('query', $parts) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$authority.$path.$query;
    }

    public function resolve(string $baseUrl, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('Referencia URL vacia.');
        }
        if (parse_url($reference, PHP_URL_SCHEME) !== null) {
            return $this->normalize($reference);
        }

        $base = parse_url($this->normalize($baseUrl));
        if (! is_array($base)) {
            throw new RuntimeException('URL base invalida.');
        }
        $origin = strtolower((string) $base['scheme']).'://'.strtolower((string) $base['host']);
        $port = $base['port'] ?? null;
        if ($port !== null) {
            $origin .= ':'.$port;
        }

        if (str_starts_with($reference, '//')) {
            return $this->normalize($base['scheme'].':'.$reference);
        }
        if (str_starts_with($reference, '?')) {
            return $this->normalize($origin.($base['path'] ?? '/').$reference);
        }

        $referenceParts = parse_url($reference);
        $referencePath = (string) ($referenceParts['path'] ?? '');
        $path = str_starts_with($referencePath, '/')
            ? $referencePath
            : rtrim(str_replace('\\', '/', dirname((string) ($base['path'] ?? '/'))), '/').'/'.$referencePath;
        $path = $this->removeDotSegments($path);
        $query = array_key_exists('query', $referenceParts) ? '?'.$referenceParts['query'] : '';

        return $this->normalize($origin.$path.$query);
    }

    public function origin(string $url): string
    {
        $parts = parse_url($this->normalize($url));
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public function hash(string $url): string
    {
        return hash('sha256', $this->normalize($url));
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments).(str_ends_with($path, '/') ? '/' : '');
    }
}

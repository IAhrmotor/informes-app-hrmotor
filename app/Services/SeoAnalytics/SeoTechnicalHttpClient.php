<?php

namespace App\Services\SeoAnalytics;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class SeoTechnicalHttpClient
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly SeoTechnicalUrlGuard $guard,
        private readonly SeoTechnicalUrlNormalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function fetch(string $url, int $maxBodyBytes, string $accept = '*/*'): array
    {
        $startedAt = hrtime(true);
        $current = $url;
        $redirects = 0;
        $visited = [];

        while (true) {
            try {
                $target = $this->guard->assertFetchable($current);
            } catch (RuntimeException $exception) {
                return $this->errorResult(
                    $current,
                    $redirects,
                    $startedAt,
                    $redirects > 0 ? 'blocked_redirect' : 'unsafe_target',
                    $exception->getMessage(),
                );
            }

            $hash = $this->normalizer->hash($target['url']);
            if (isset($visited[$hash])) {
                return $this->errorResult($target['url'], $redirects, $startedAt, 'redirect_loop', 'Cadena de redireccion ciclica.');
            }
            $visited[$hash] = true;

            try {
                $response = $this->request($target, $accept);
            } catch (ConnectionException $exception) {
                return $this->errorResult($target['url'], $redirects, $startedAt, 'network_error', $exception->getMessage());
            } catch (Throwable $exception) {
                return $this->errorResult($target['url'], $redirects, $startedAt, 'request_error', $exception->getMessage());
            }

            if (in_array($response->status(), self::REDIRECT_STATUSES, true) && filled($response->header('Location'))) {
                if ($redirects >= $this->maxRedirects()) {
                    return $this->errorResult($target['url'], $redirects, $startedAt, 'redirect_limit', 'Se supero el limite de redirecciones.');
                }
                try {
                    $current = $this->normalizer->resolve($target['url'], (string) $response->header('Location'));
                    $this->guard->assertAllowed($current);
                } catch (RuntimeException $exception) {
                    return $this->errorResult($target['url'], $redirects + 1, $startedAt, 'blocked_redirect', $exception->getMessage());
                }
                $redirects++;

                continue;
            }

            $xRobotsTag = $this->headerValue($response, 'X-Robots-Tag');
            try {
                [$body, $truncated] = $this->boundedBody($response, $maxBodyBytes);
            } catch (Throwable $exception) {
                return $this->bodyReadErrorResult($target['url'], $redirects, $startedAt, $response, $xRobotsTag, $exception);
            }

            return [
                'final_url' => $target['url'],
                'http_status' => $response->status(),
                'redirect_count' => $redirects,
                'response_time_ms' => $this->elapsedMilliseconds($startedAt),
                'content_type' => $this->contentType($response),
                'x_robots_tag' => $this->boundedValue($xRobotsTag, 512),
                'x_robots_tag_full' => $xRobotsTag,
                'body' => $body,
                'body_truncated' => $truncated,
                'error_code' => null,
                'error_message' => null,
            ];
        }
    }

    /** @param array{url: string, host: string, port: int, ip: string} $target */
    private function request(array $target, string $accept): Response
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('El transporte HTTP no permite fijar la resolucion DNS validada.');
        }

        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        return Http::withHeaders([
            'User-Agent' => (string) config('seo_analytics.technical_health.user_agent', 'HRMotor-SEO-Health/1.0'),
            'Accept' => $accept,
        ])->withOptions($this->transportOptions($target, $ip))
            ->connectTimeout((int) config('seo_analytics.technical_health.connect_timeout_seconds', 3))
            ->timeout((int) config('seo_analytics.technical_health.request_timeout_seconds', 10))
            ->get($target['url']);
    }

    /** @param array{url: string, host: string, port: int, ip: string} $target
     * @return array<string, mixed>
     */
    private function transportOptions(array $target, string $ip): array
    {
        return [
            'allow_redirects' => false,
            'stream' => true,
            'read_timeout' => (int) config('seo_analytics.technical_health.request_timeout_seconds', 10),
            'proxy' => '',
            'verify' => true,
            'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$ip]],
        ];
    }

    /** @return array{0: string, 1: bool} */
    private function boundedBody(Response $response, int $maxBytes): array
    {
        $maxBytes = max(1, $maxBytes);
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $readLimit = $maxBytes + 1;
            while (strlen($body) < $readLimit && ! $stream->eof()) {
                $chunk = $stream->read($readLimit - strlen($body));
                if ($chunk === '') {
                    throw new RuntimeException('La lectura del body remoto no pudo continuar.');
                }
                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }
        $truncated = strlen($body) > $maxBytes;

        return [substr($body, 0, $maxBytes), $truncated];
    }

    private function contentType(Response $response): ?string
    {
        $value = trim((string) $response->header('Content-Type'));

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function headerValue(Response $response, string $name): ?string
    {
        $value = trim((string) $response->header($name));

        return $value === '' ? null : $value;
    }

    private function boundedValue(?string $value, int $limit): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $limit);
    }

    /** @return array<string, mixed> */
    private function bodyReadErrorResult(
        string $url,
        int $redirects,
        int $startedAt,
        Response $response,
        ?string $xRobotsTag,
        Throwable $exception,
    ): array {
        return [
            'final_url' => $url,
            'http_status' => $response->status(),
            'redirect_count' => $redirects,
            'response_time_ms' => $this->elapsedMilliseconds($startedAt),
            'content_type' => $this->contentType($response),
            'x_robots_tag' => $this->boundedValue($xRobotsTag, 512),
            'x_robots_tag_full' => $xRobotsTag,
            'body' => '',
            'body_truncated' => true,
            'error_code' => 'body_read_error',
            'error_message' => IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 500),
        ];
    }

    /** @return array<string, mixed> */
    private function errorResult(
        string $url,
        int $redirects,
        int $startedAt,
        string $code,
        string $message,
    ): array {
        return [
            'final_url' => $this->safeNormalizedUrl($url),
            'http_status' => null,
            'redirect_count' => $redirects,
            'response_time_ms' => $this->elapsedMilliseconds($startedAt),
            'content_type' => null,
            'x_robots_tag' => null,
            'x_robots_tag_full' => null,
            'body' => '',
            'body_truncated' => false,
            'error_code' => $code,
            'error_message' => IntegrationErrorSanitizer::sanitizeMessage($message, 500),
        ];
    }

    private function safeNormalizedUrl(string $url): ?string
    {
        try {
            return $this->normalizer->normalize($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function maxRedirects(): int
    {
        return min(5, max(0, (int) config('seo_analytics.technical_health.max_redirects', 5)));
    }
}

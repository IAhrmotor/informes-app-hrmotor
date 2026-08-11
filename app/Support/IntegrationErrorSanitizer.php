<?php

namespace App\Support;

final class IntegrationErrorSanitizer
{
    private const SENSITIVE_KEYS = [
        'access_token',
        'refresh_token',
        'client_secret',
        'password',
        'passwd',
        'authorization',
        'api_key',
        'developer_token',
        'cookie',
        'set_cookie',
        'session_id',
        'csrf_token',
        'x_csrf_token',
        'x_xsrf_token',
        'secret',
    ];

    public static function remoteFailure(string $integration, int $status, mixed $payload = null): string
    {
        $type = self::remoteErrorType($payload);

        return sprintf(
            '%s: error remoto HTTP %d%s.',
            $integration,
            $status,
            $type === null ? '' : ' (tipo '.$type.')'
        );
    }

    public static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = match (true) {
                is_array($value) => self::sanitizeContext($value),
                $value instanceof \Throwable => self::sanitizeThrowable($value),
                default => self::sanitizeScalar($value),
            };
        }

        return $sanitized;
    }

    public static function sanitizeMessage(string $message, int $maxLength = 2000): string
    {
        return mb_substr((string) self::sanitizeScalar($message), 0, max(1, $maxLength));
    }

    private static function remoteErrorType(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $candidate = data_get($payload, 'error.type')
            ?? data_get($payload, 'error.status')
            ?? data_get($payload, 'error.code')
            ?? data_get($payload, 'errorCode')
            ?? data_get($payload, 'error');

        if (! is_scalar($candidate)) {
            return null;
        }

        $type = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $candidate);

        return $type === '' ? null : mb_substr($type, 0, 80);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower(str_replace('-', '_', trim($key)));

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || str_ends_with($normalized, '_password')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_token');
    }

    private static function sanitizeScalar(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i', '[redacted]', $value);
        $value = preg_replace(
            '/(?i)(access_token|refresh_token|client_secret|password|passwd|authorization|api_key|developer_token|cookie|set-cookie|session_id|csrf_token|x-csrf-token|x-xsrf-token)(\s*[=:]\s*)[^\s,;&]+/',
            '$1$2[redacted]',
            $value
        );

        return $value;
    }

    private static function sanitizeThrowable(\Throwable $throwable): array
    {
        return [
            'type' => $throwable::class,
            'code' => $throwable->getCode(),
            'message' => self::sanitizeMessage($throwable->getMessage()),
            'trace' => collect($throwable->getTrace())
                ->take(20)
                ->map(static fn (array $frame): array => array_filter([
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'class' => $frame['class'] ?? null,
                    'function' => $frame['function'] ?? null,
                ], static fn (mixed $value): bool => $value !== null))
                ->values()
                ->all(),
        ];
    }
}

<?php

namespace App\Services\Analytics;

use DateTimeInterface;
use JsonException;
use RuntimeException;

final class AnalyticalSnapshotFingerprint
{
    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        try {
            $canonical = json_encode([
                'metric_key' => $this->requiredString($snapshot, 'metric_key'),
                'data_date' => $this->date($snapshot['data_date'] ?? null),
                'current_value' => $this->decimal($snapshot['current_value'] ?? null),
                'baseline_value' => $this->decimal($snapshot['baseline_value'] ?? null),
                'absolute_change' => $this->decimal($snapshot['absolute_change'] ?? null),
                'relative_change' => $this->decimal($snapshot['relative_change'] ?? null),
                'is_evaluable' => $this->boolean($snapshot),
                'evaluation_reason' => $this->nullableString($snapshot['evaluation_reason'] ?? null),
                'engine_version' => $this->requiredString($snapshot, 'engine_version'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo serializar el snapshot analitico.', 0, $exception);
        }

        return hash('sha256', $canonical);
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("El snapshot no contiene {$key}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException('El motivo factual del snapshot no es valido.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function boolean(array $values): bool
    {
        if (! array_key_exists('is_evaluable', $values)
            || ! in_array($values['is_evaluable'], [true, false, 1, 0, '1', '0'], true)) {
            throw new RuntimeException('La evaluabilidad factual del snapshot no es valida.');
        }

        return (bool) $values['is_evaluable'];
    }

    private function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T|\s|$)/D', $value)) {
            return substr($value, 0, 10);
        }

        throw new RuntimeException('La fecha factual del snapshot no es valida.');
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = is_string($value) || is_int($value) ? (string) $value : '';
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $raw)) {
            throw new RuntimeException('Un valor factual del snapshot no es decimal valido.');
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $normalized = $whole.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }
}

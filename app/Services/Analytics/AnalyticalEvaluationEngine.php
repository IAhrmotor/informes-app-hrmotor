<?php

namespace App\Services\Analytics;

use RuntimeException;

final class AnalyticalEvaluationEngine
{
    public const STATUSES = ['ok', 'observation', 'deviation', 'critical', 'not-evaluable'];

    public const DIRECTIONS = ['stable', 'favorable', 'unfavorable', 'not_evaluable'];

    public const MAGNITUDE_BANDS = ['none', 'observation', 'deviation', 'critical', 'not-evaluable'];

    public const COMPARISON_MODES = ['relative_percent', 'absolute_percentage_points', 'absolute_value'];

    public const FAVORABLE_DIRECTIONS = ['increase', 'decrease'];

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $rule
     * @return array{status: string, direction: string, magnitude_band: string, reason_code: string}
     */
    public function evaluate(array $snapshot, array $rule): array
    {
        $this->validateRule($rule);

        if (! ($snapshot['is_evaluable'] ?? false)) {
            return [
                'status' => 'not-evaluable',
                'direction' => 'not_evaluable',
                'magnitude_band' => 'not-evaluable',
                'reason_code' => in_array($snapshot['evaluation_reason'] ?? null, ['missing_current', 'insufficient_history'], true)
                    ? $snapshot['evaluation_reason']
                    : 'snapshot_not_evaluable',
            ];
        }

        $current = $this->requiredDecimal($snapshot, 'current_value');
        $baseline = $this->requiredDecimal($snapshot, 'baseline_value');
        $change = $this->requiredDecimal($snapshot, 'absolute_change');
        $direction = $this->direction($change, (string) $rule['favorable_direction']);

        if ($rule['comparison_mode'] === 'relative_percent') {
            return $this->evaluateVolume($snapshot, $rule, $current, $baseline, $change, $direction);
        }

        $magnitude = $rule['comparison_mode'] === 'absolute_percentage_points'
            ? $this->shiftDecimal($this->absolute($change), 2)
            : $this->absolute($change);

        return $this->classify($magnitude, $rule, $direction);
    }

    /** @param array<string, mixed> $snapshot
     * @param  array<string, mixed>  $rule
     * @return array{status: string, direction: string, magnitude_band: string, reason_code: string}
     */
    private function evaluateVolume(
        array $snapshot,
        array $rule,
        string $current,
        string $baseline,
        string $change,
        string $direction,
    ): array {
        $this->assertNonNegative($current);
        $this->assertNonNegative($baseline);
        $minimumBaseline = $this->unsigned((string) $rule['minimum_baseline']);
        $minimumChange = $this->unsigned((string) $rule['minimum_absolute_change']);
        $absoluteChange = $this->absolute($change);

        if ($this->isZero($baseline)) {
            if ($this->isZero($current)) {
                return $this->result('ok', 'stable', 'none', 'within_range');
            }

            if ($this->compare($absoluteChange, $minimumChange) < 0) {
                return $this->result('ok', $direction, 'none', 'below_materiality');
            }

            return $this->result(
                'observation',
                $direction,
                'observation',
                $direction === 'favorable' ? 'favorable_opportunity' : 'low_baseline_material_change',
            );
        }

        if ($this->compare($absoluteChange, $minimumChange) < 0) {
            return $this->result('ok', $direction, 'none', 'below_materiality');
        }

        if ($this->compare($this->unsigned($baseline), $minimumBaseline) < 0) {
            return $this->result(
                'observation',
                $direction,
                'observation',
                $direction === 'favorable' ? 'favorable_opportunity' : 'low_baseline_material_change',
            );
        }

        $relativeChange = $snapshot['relative_change'] ?? null;
        if (! is_string($relativeChange) && ! is_numeric($relativeChange)) {
            throw new RuntimeException('El snapshot evaluable no contiene variacion relativa.');
        }

        return $this->classify(
            $this->shiftDecimal($this->absolute((string) $relativeChange), 2),
            $rule,
            $direction,
        );
    }

    /** @param array<string, mixed> $rule
     * @return array{status: string, direction: string, magnitude_band: string, reason_code: string}
     */
    private function classify(string $magnitude, array $rule, string $direction): array
    {
        $band = match (true) {
            $this->compare($magnitude, $this->unsigned((string) $rule['critical_threshold'])) >= 0 => 'critical',
            $this->compare($magnitude, $this->unsigned((string) $rule['deviation_threshold'])) >= 0 => 'deviation',
            $this->compare($magnitude, $this->unsigned((string) $rule['observation_threshold'])) >= 0 => 'observation',
            default => 'none',
        };

        if ($band === 'none' || $direction === 'stable') {
            return $this->result('ok', $direction, 'none', 'within_range');
        }

        if ($direction === 'favorable') {
            return $this->result('observation', 'favorable', $band, 'favorable_opportunity');
        }

        return $this->result($band, 'unfavorable', $band, 'unfavorable_change');
    }

    /** @param array<string, mixed> $rule */
    private function validateRule(array $rule): void
    {
        if (! in_array($rule['comparison_mode'] ?? null, self::COMPARISON_MODES, true)
            || ! in_array($rule['favorable_direction'] ?? null, self::FAVORABLE_DIRECTIONS, true)) {
            throw new RuntimeException('La regla analitica contiene un contrato no soportado.');
        }

        foreach (['observation_threshold', 'deviation_threshold', 'critical_threshold'] as $field) {
            $this->unsigned((string) ($rule[$field] ?? ''));
        }

        if ($rule['comparison_mode'] === 'relative_percent') {
            $this->unsigned((string) ($rule['minimum_baseline'] ?? ''));
            $this->unsigned((string) ($rule['minimum_absolute_change'] ?? ''));
        }
    }

    /** @param array<string, mixed> $values */
    private function requiredDecimal(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) && ! is_numeric($value)) {
            throw new RuntimeException("El snapshot evaluable no contiene {$key}.");
        }

        $this->signed((string) $value);

        return (string) $value;
    }

    private function direction(string $change, string $favorableDirection): string
    {
        if ($this->isZero($change)) {
            return 'stable';
        }

        $increased = ! str_starts_with($change, '-');
        $favorable = ($favorableDirection === 'increase' && $increased)
            || ($favorableDirection === 'decrease' && ! $increased);

        return $favorable ? 'favorable' : 'unfavorable';
    }

    /** @return array{status: string, direction: string, magnitude_band: string, reason_code: string} */
    private function result(string $status, string $direction, string $band, string $reason): array
    {
        return [
            'status' => $status,
            'direction' => $direction,
            'magnitude_band' => $band,
            'reason_code' => $reason,
        ];
    }

    private function assertNonNegative(string $value): void
    {
        if (str_starts_with($value, '-') && ! $this->isZero($value)) {
            throw new RuntimeException('Una metrica de volumen no puede ser negativa.');
        }
    }

    private function absolute(string $value): string
    {
        $this->signed($value);

        return $this->unsigned(ltrim($value, '+-'));
    }

    private function isZero(string $value): bool
    {
        return trim(str_replace(['-', '+', '.'], '', $value), '0') === '';
    }

    private function shiftDecimal(string $value, int $places): string
    {
        [$whole, $fraction] = $this->parts($this->unsigned($value));
        $digits = $whole.$fraction;
        $position = strlen($whole) + $places;
        if ($position >= strlen($digits)) {
            return ltrim($digits.str_repeat('0', $position - strlen($digits)), '0') ?: '0';
        }

        $shiftedWhole = substr($digits, 0, $position);
        $shiftedFraction = rtrim(substr($digits, $position), '0');

        return (ltrim($shiftedWhole, '0') ?: '0').($shiftedFraction === '' ? '' : '.'.$shiftedFraction);
    }

    private function compare(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = $this->parts($this->unsigned($left));
        [$rightWhole, $rightFraction] = $this->parts($this->unsigned($right));
        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) <=> strlen($rightWhole);
        }

        $wholeComparison = strcmp($leftWhole, $rightWhole);
        if ($wholeComparison !== 0) {
            return $wholeComparison <=> 0;
        }

        $scale = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(str_pad($leftFraction, $scale, '0'), str_pad($rightFraction, $scale, '0')) <=> 0;
    }

    /** @return array{0: string, 1: string} */
    private function parts(string $value): array
    {
        $parts = explode('.', $value, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function signed(string $value): void
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new RuntimeException('El valor analitico no es un decimal valido.');
        }
    }

    private function unsigned(string $value): string
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw new RuntimeException('El valor analitico no es un decimal no negativo valido.');
        }

        [$whole, $fraction] = $this->parts($value);
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }
}

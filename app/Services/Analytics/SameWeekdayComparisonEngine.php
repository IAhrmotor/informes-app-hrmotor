<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use RuntimeException;

final class SameWeekdayComparisonEngine
{
    public const VERSION = 'same_weekday_v1';

    public const REFERENCE_OFFSETS = [7, 14, 21, 28];

    public const MINIMUM_REFERENCE_SAMPLES = 3;

    public const YEAR_REFERENCE_OFFSET = 364;

    private const SCALE = 8;

    /**
     * @param  array<string, int|float|string|null>  $valuesByDate
     * @return array<string, int|string|bool|null>
     */
    public function compare(
        CarbonImmutable $targetDate,
        int|float|string|null $currentValue,
        array $valuesByDate,
    ): array {
        $references = [];
        foreach (self::REFERENCE_OFFSETS as $offset) {
            $date = $targetDate->subDays($offset)->toDateString();
            $references[$offset] = array_key_exists($date, $valuesByDate) && $valuesByDate[$date] !== null
                ? $this->normalizeSourceValue($valuesByDate[$date])
                : null;
        }

        $yearDate = $targetDate->subDays(self::YEAR_REFERENCE_OFFSET)->toDateString();
        $yearAgo = array_key_exists($yearDate, $valuesByDate) && $valuesByDate[$yearDate] !== null
            ? $this->normalizeSourceValue($valuesByDate[$yearDate])
            : null;
        $current = $currentValue !== null ? $this->normalizeSourceValue($currentValue) : null;
        $validReferences = array_values(array_filter($references, static fn (?string $value): bool => $value !== null));
        $referenceCount = count($validReferences);

        $result = [
            'target_date' => $targetDate->toDateString(),
            'current_value' => $current,
            'd7_value' => $references[7],
            'd14_value' => $references[14],
            'd21_value' => $references[21],
            'd28_value' => $references[28],
            'reference_count' => $referenceCount,
            'baseline_value' => null,
            'absolute_change' => null,
            'relative_change' => null,
            'd364_value' => $yearAgo,
            'year_absolute_change' => null,
            'year_relative_change' => null,
            'is_evaluable' => false,
            'evaluation_reason' => $current === null ? 'missing_current' : 'insufficient_history',
        ];

        if ($current !== null && $yearAgo !== null) {
            $result['year_absolute_change'] = $this->derived((float) $current - (float) $yearAgo);
            $result['year_relative_change'] = $this->isZero($yearAgo)
                ? null
                : $this->derived(((float) $current - (float) $yearAgo) / (float) $yearAgo);
        }

        if ($current === null || $referenceCount < self::MINIMUM_REFERENCE_SAMPLES) {
            return $result;
        }

        $baselineValue = array_sum(array_map('floatval', $validReferences)) / $referenceCount;
        $absoluteValue = (float) $current - $baselineValue;
        $baseline = $this->derived($baselineValue);
        $absolute = $this->derived($absoluteValue);

        return array_replace($result, [
            'baseline_value' => $baseline,
            'absolute_change' => $absolute,
            'relative_change' => $this->isZero($baseline)
                ? null
                : $this->derived($absoluteValue / $baselineValue),
            'is_evaluable' => true,
            'evaluation_reason' => null,
        ]);
    }

    private function normalizeSourceValue(int|float|string $value): string
    {
        $raw = (string) $value;
        if (! preg_match('/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/D', $raw, $matches)) {
            throw new RuntimeException('El valor analitico no es un decimal valido.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches['fraction'] ?? '';
        $excess = substr($fraction, self::SCALE);
        if ($excess !== '' && trim($excess, '0') !== '') {
            throw new RuntimeException('El valor analitico supera la precision soportada.');
        }

        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
        $isZero = $whole === '0' && trim($fraction, '0') === '';

        return ($matches['sign'] === '-' && ! $isZero ? '-' : '').$whole.'.'.$fraction;
    }

    private function derived(float $value): string
    {
        return number_format(round($value, self::SCALE), self::SCALE, '.', '');
    }

    private function isZero(string $value): bool
    {
        return trim(str_replace(['-', '.'], '', $value), '0') === '';
    }
}

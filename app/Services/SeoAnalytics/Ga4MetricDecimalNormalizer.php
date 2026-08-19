<?php

namespace App\Services\SeoAnalytics;

use RuntimeException;

final class Ga4MetricDecimalNormalizer
{
    public function normalize(string $value): string
    {
        if (strlen($value) > 128 || preg_match(
            '/^(?<whole>\d+)(?:\.(?<fraction>\d+))?(?:[eE](?<exponent>[+-]?\d+))?$/D',
            $value,
            $matches,
        ) !== 1) {
            throw new RuntimeException('Google Analytics devolvio keyEvents invalido.');
        }

        $fraction = $matches['fraction'] ?? '';
        $digits = ltrim($matches['whole'].$fraction, '0');
        if ($digits === '') {
            return '0.000000';
        }

        $exponent = $this->exponent($matches['exponent'] ?? '');
        $shift = $exponent - strlen($fraction) + 6;

        if ($shift >= 0) {
            if (strlen($digits) + $shift > 18) {
                throw new RuntimeException('Google Analytics devolvio keyEvents fuera del rango soportado.');
            }
            $scaled = $digits.str_repeat('0', $shift);
        } else {
            $discard = -$shift;
            if ($discard >= strlen($digits) || trim(substr($digits, -$discard), '0') !== '') {
                throw new RuntimeException('Google Analytics devolvio keyEvents con precision superior a la soportada.');
            }
            $scaled = substr($digits, 0, -$discard);
        }

        $scaled = ltrim($scaled, '0');
        if ($scaled === '') {
            return '0.000000';
        }
        if (strlen($scaled) > 18) {
            throw new RuntimeException('Google Analytics devolvio keyEvents fuera del rango soportado.');
        }

        $scaled = str_pad($scaled, 7, '0', STR_PAD_LEFT);

        return substr($scaled, 0, -6).'.'.substr($scaled, -6);
    }

    private function exponent(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        $negative = $value[0] === '-';
        $unsigned = in_array($value[0], ['+', '-'], true) ? substr($value, 1) : $value;
        $digits = ltrim($unsigned, '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 6) {
            throw new RuntimeException($negative
                ? 'Google Analytics devolvio keyEvents con precision superior a la soportada.'
                : 'Google Analytics devolvio keyEvents fuera del rango soportado.');
        }

        $exponent = (int) $digits;

        return $negative ? -$exponent : $exponent;
    }
}

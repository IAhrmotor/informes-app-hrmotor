<?php

namespace Tests\Unit;

use App\Services\SeoAnalytics\Ga4MetricDecimalNormalizer;
use RuntimeException;
use Tests\TestCase;

class Ga4MetricDecimalNormalizerTest extends TestCase
{
    public function test_normalizes_decimal_and_scientific_values_without_floating_point(): void
    {
        $normalizer = new Ga4MetricDecimalNormalizer;
        $cases = [
            '0' => '0.000000',
            '3' => '3.000000',
            '0.25' => '0.250000',
            '1.75' => '1.750000',
            '1.333333' => '1.333333',
            '2.6e-05' => '0.000026',
            '4e-06' => '0.000004',
            '2.6E-05' => '0.000026',
            '1e0' => '1.000000',
            '1e3' => '1000.000000',
            '1E3' => '1000.000000',
            '1e+3' => '1000.000000',
            '1.23e2' => '123.000000',
            '1.23e-2' => '0.012300',
            '0001.2300' => '1.230000',
            '1.1234560' => '1.123456',
            '999999999999.999999' => '999999999999.999999',
            '0e-10' => '0.000000',
            '0e+100' => '0.000000',
            '0e-999999' => '0.000000',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $normalizer->normalize($input));
        }
    }

    public function test_rejects_precision_overflow_and_invalid_syntax_without_rounding(): void
    {
        $normalizer = new Ga4MetricDecimalNormalizer;
        $cases = [
            '4e-07' => 'precision superior',
            '1.1234567' => 'precision superior',
            '1000000000000' => 'fuera del rango',
            '1e999999' => 'fuera del rango',
            '1e-999999' => 'precision superior',
            'NaN' => 'keyEvents invalido',
            'Infinity' => 'keyEvents invalido',
            '-Infinity' => 'keyEvents invalido',
            '-1' => 'keyEvents invalido',
            '-2.6e-05' => 'keyEvents invalido',
            '1,25' => 'keyEvents invalido',
            '0x10' => 'keyEvents invalido',
            'abc' => 'keyEvents invalido',
            '' => 'keyEvents invalido',
        ];

        foreach ($cases as $input => $expectedMessage) {
            try {
                $normalizer->normalize((string) $input);
                $this->fail('Expected RuntimeException for invalid metric value.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }
}

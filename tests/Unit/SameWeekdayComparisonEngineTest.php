<?php

namespace Tests\Unit;

use App\Services\Analytics\SameWeekdayComparisonEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SameWeekdayComparisonEngineTest extends TestCase
{
    private SameWeekdayComparisonEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SameWeekdayComparisonEngine;
    }

    public function test_four_weekly_references_and_year_reference_are_compared_on_exact_dates(): void
    {
        $result = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '10', [
            '2026-08-12' => '8',
            '2026-08-05' => '9',
            '2026-07-29' => '10',
            '2026-07-22' => '11',
            '2025-08-20' => '5',
            '2025-08-19' => '999',
        ]);

        $this->assertSame('2026-08-19', $result['target_date']);
        $this->assertSame('8.00000000', $result['d7_value']);
        $this->assertSame('9.00000000', $result['d14_value']);
        $this->assertSame('10.00000000', $result['d21_value']);
        $this->assertSame('11.00000000', $result['d28_value']);
        $this->assertSame(4, $result['reference_count']);
        $this->assertSame('9.50000000', $result['baseline_value']);
        $this->assertSame('0.50000000', $result['absolute_change']);
        $this->assertSame('0.05263158', $result['relative_change']);
        $this->assertSame('5.00000000', $result['d364_value']);
        $this->assertSame('5.00000000', $result['year_absolute_change']);
        $this->assertSame('1.00000000', $result['year_relative_change']);
        $this->assertTrue($result['is_evaluable']);
        $this->assertNull($result['evaluation_reason']);
    }

    public function test_three_references_are_evaluable_but_two_are_insufficient(): void
    {
        $three = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '10', [
            '2026-08-12' => '8', '2026-07-29' => '10', '2026-07-22' => '12',
        ]);
        $this->assertTrue($three['is_evaluable']);
        $this->assertSame(3, $three['reference_count']);
        $this->assertSame('10.00000000', $three['baseline_value']);

        $two = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '10', [
            '2026-08-12' => '8', '2026-08-05' => '12',
        ]);
        $this->assertFalse($two['is_evaluable']);
        $this->assertSame('insufficient_history', $two['evaluation_reason']);
        $this->assertNull($two['baseline_value']);
        $this->assertNull($two['absolute_change']);
        $this->assertNull($two['relative_change']);
    }

    public function test_persisted_zero_is_valid_and_zero_baseline_has_no_relative_change(): void
    {
        $zero = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '0', [
            '2026-08-12' => '0', '2026-08-05' => '0', '2026-07-29' => '0', '2026-07-22' => '0',
            '2025-08-20' => '0',
        ]);
        $this->assertTrue($zero['is_evaluable']);
        $this->assertSame(4, $zero['reference_count']);
        $this->assertSame('0.00000000', $zero['baseline_value']);
        $this->assertSame('0.00000000', $zero['absolute_change']);
        $this->assertNull($zero['relative_change']);
        $this->assertSame('0.00000000', $zero['year_absolute_change']);
        $this->assertNull($zero['year_relative_change']);

        $growth = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '10', [
            '2026-08-12' => '0', '2026-08-05' => '0', '2026-07-29' => '0', '2026-07-22' => '0',
        ]);
        $this->assertSame('10.00000000', $growth['absolute_change']);
        $this->assertNull($growth['relative_change']);
    }

    public function test_real_zero_current_can_produce_minus_one_relative_change(): void
    {
        $result = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), '0', [
            '2026-08-12' => '10', '2026-08-05' => '10', '2026-07-29' => '10', '2026-07-22' => '10',
        ]);

        $this->assertSame('-10.00000000', $result['absolute_change']);
        $this->assertSame('-1.00000000', $result['relative_change']);
    }

    public function test_missing_current_is_distinct_from_zero_and_d364_is_optional(): void
    {
        $result = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), null, [
            '2026-08-12' => '1', '2026-08-05' => '2', '2026-07-29' => '3', '2026-07-22' => '4',
        ]);

        $this->assertFalse($result['is_evaluable']);
        $this->assertSame('missing_current', $result['evaluation_reason']);
        $this->assertNull($result['current_value']);
        $this->assertNull($result['baseline_value']);
        $this->assertNull($result['d364_value']);
    }

    #[DataProvider('roundingCases')]
    public function test_derived_values_are_rounded_deterministically(string $current, array $history, string $baseline, string $relative): void
    {
        $result = $this->engine->compare(CarbonImmutable::parse('2026-08-19'), $current, $history);

        $this->assertSame($baseline, $result['baseline_value']);
        $this->assertSame($relative, $result['relative_change']);
    }

    public static function roundingCases(): array
    {
        return [
            'repeating average and ratio' => ['2', [
                '2026-08-12' => '1', '2026-08-05' => '1', '2026-07-29' => '2',
            ], '1.33333333', '0.50000000'],
            'ga4 precision remains visible' => ['0.000026', [
                '2026-08-12' => '0.000004', '2026-08-05' => '0.000026', '2026-07-29' => '0.000048',
            ], '0.00002600', '0.00000000'],
        ];
    }
}

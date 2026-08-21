<?php

namespace Tests\Unit;

use App\Services\Analytics\AnalyticalEvaluationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnalyticalEvaluationEngineTest extends TestCase
{
    private AnalyticalEvaluationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AnalyticalEvaluationEngine;
    }

    #[DataProvider('unfavorableVolumeProvider')]
    public function test_volume_thresholds_are_inclusive_and_preserve_expected_band(
        string $relative,
        string $status,
        string $band,
    ): void {
        $result = $this->engine->evaluate(
            $this->snapshot((string) (100 + ((float) $relative * 100)), '100', (string) ((float) $relative * 100), $relative),
            $this->volumeRule('0', '0'),
        );

        $this->assertSame($status, $result['status']);
        $this->assertSame($band, $result['magnitude_band']);
        $this->assertSame('unfavorable', $result['direction']);
    }

    public static function unfavorableVolumeProvider(): array
    {
        return [
            'below observation' => ['-0.09999999', 'ok', 'none'],
            'observation boundary' => ['-0.10000000', 'observation', 'observation'],
            'below deviation' => ['-0.19999999', 'observation', 'observation'],
            'deviation boundary' => ['-0.20000000', 'deviation', 'deviation'],
            'below critical' => ['-0.34999999', 'deviation', 'deviation'],
            'critical boundary' => ['-0.35000000', 'critical', 'critical'],
        ];
    }

    public function test_volume_materiality_low_baseline_and_zero_baseline_are_conservative(): void
    {
        $clickRule = $this->volumeRule('50', '10');
        $this->assertSame(
            ['status' => 'ok', 'direction' => 'unfavorable', 'magnitude_band' => 'none', 'reason_code' => 'below_materiality'],
            $this->engine->evaluate($this->snapshot('54', '60', '-6', '-0.1'), $clickRule),
        );

        $leadRule = $this->volumeRule('5', '2');
        $lowBaseline = $this->engine->evaluate($this->snapshot('0', '4', '-4', '-1'), $leadRule);
        $this->assertSame('observation', $lowBaseline['status']);
        $this->assertSame('unfavorable', $lowBaseline['direction']);
        $this->assertSame('low_baseline_material_change', $lowBaseline['reason_code']);

        $this->assertSame('ok', $this->engine->evaluate($this->snapshot('0', '1', '-1', '-1'), $leadRule)['status']);
        $this->assertSame('stable', $this->engine->evaluate($this->snapshot('0', '0', '0', null), $leadRule)['direction']);
        $zeroGrowth = $this->engine->evaluate($this->snapshot('3', '0', '3', null), $leadRule);
        $this->assertSame('observation', $zeroGrowth['status']);
        $this->assertSame('favorable', $zeroGrowth['direction']);
        $this->assertSame('favorable_opportunity', $zeroGrowth['reason_code']);
    }

    public function test_large_favorable_movement_is_observation_but_retains_critical_magnitude(): void
    {
        $result = $this->engine->evaluate(
            $this->snapshot('142', '100', '42', '0.42'),
            $this->volumeRule('50', '10'),
        );

        $this->assertSame('observation', $result['status']);
        $this->assertSame('favorable', $result['direction']);
        $this->assertSame('critical', $result['magnitude_band']);
        $this->assertSame('favorable_opportunity', $result['reason_code']);
    }

    #[DataProvider('ctrProvider')]
    public function test_ctr_uses_absolute_percentage_points(string $change, string $status): void
    {
        $result = $this->engine->evaluate(
            $this->snapshot('0.03', '0.03', $change, '999'),
            $this->absoluteRule('absolute_percentage_points', 'increase'),
        );

        $this->assertSame($status, $result['status']);
    }

    public static function ctrProvider(): array
    {
        return [
            ['-0.004', 'ok'],
            ['-0.005', 'observation'],
            ['-0.012', 'deviation'],
            ['-0.025', 'critical'],
        ];
    }

    public function test_favorable_ctr_uses_observation_visual_with_critical_magnitude(): void
    {
        $result = $this->engine->evaluate(
            $this->snapshot('0.055', '0.03', '0.025', '0.83333333'),
            $this->absoluteRule('absolute_percentage_points', 'increase'),
        );

        $this->assertSame('observation', $result['status']);
        $this->assertSame('favorable', $result['direction']);
        $this->assertSame('critical', $result['magnitude_band']);
    }

    #[DataProvider('positionProvider')]
    public function test_position_inverts_favorable_direction(string $current, string $change, string $status, string $direction): void
    {
        $result = $this->engine->evaluate(
            $this->snapshot($current, '5', $change, '999'),
            $this->absoluteRule('absolute_value', 'decrease'),
        );

        $this->assertSame($status, $result['status']);
        $this->assertSame($direction, $result['direction']);
    }

    public static function positionProvider(): array
    {
        return [
            ['5.3', '0.3', 'ok', 'unfavorable'],
            ['5.7', '0.7', 'observation', 'unfavorable'],
            ['6.3', '1.3', 'deviation', 'unfavorable'],
            ['7.5', '2.5', 'critical', 'unfavorable'],
            ['2.5', '-2.5', 'observation', 'favorable'],
        ];
    }

    public function test_non_evaluable_reason_is_propagated_and_d364_does_not_affect_result(): void
    {
        $missing = $this->engine->evaluate([
            'is_evaluable' => false,
            'evaluation_reason' => 'missing_current',
        ], $this->volumeRule('50', '10'));
        $this->assertSame('not-evaluable', $missing['status']);
        $this->assertSame('not_evaluable', $missing['direction']);
        $this->assertSame('missing_current', $missing['reason_code']);

        $insufficient = $this->engine->evaluate([
            'is_evaluable' => false,
            'evaluation_reason' => 'insufficient_history',
        ], $this->volumeRule('50', '10'));
        $this->assertSame('not-evaluable', $insufficient['status']);
        $this->assertSame('insufficient_history', $insufficient['reason_code']);

        $snapshot = $this->snapshot('85', '100', '-15', '-0.15');
        $first = $this->engine->evaluate($snapshot + ['d364_value' => '1'], $this->volumeRule('50', '10'));
        $second = $this->engine->evaluate($snapshot + ['d364_value' => '999999'], $this->volumeRule('50', '10'));
        $this->assertSame($first, $second);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $current, string $baseline, string $absolute, ?string $relative): array
    {
        return [
            'is_evaluable' => true,
            'current_value' => $current,
            'baseline_value' => $baseline,
            'absolute_change' => $absolute,
            'relative_change' => $relative,
        ];
    }

    /** @return array<string, mixed> */
    private function volumeRule(string $minimumBaseline, string $minimumChange): array
    {
        return [
            'comparison_mode' => 'relative_percent',
            'favorable_direction' => 'increase',
            'observation_threshold' => '10',
            'deviation_threshold' => '20',
            'critical_threshold' => '35',
            'minimum_baseline' => $minimumBaseline,
            'minimum_absolute_change' => $minimumChange,
        ];
    }

    /** @return array<string, mixed> */
    private function absoluteRule(string $mode, string $direction): array
    {
        return [
            'comparison_mode' => $mode,
            'favorable_direction' => $direction,
            'observation_threshold' => '0.5',
            'deviation_threshold' => '1',
            'critical_threshold' => '2',
            'minimum_baseline' => null,
            'minimum_absolute_change' => null,
        ];
    }
}

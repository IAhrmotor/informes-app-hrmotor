<?php

namespace Tests\Unit;

use App\Services\Analytics\AnalyticalSnapshotFingerprint;
use PHPUnit\Framework\TestCase;

class AnalyticalSnapshotFingerprintTest extends TestCase
{
    private AnalyticalSnapshotFingerprint $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprints = new AnalyticalSnapshotFingerprint;
    }

    public function test_fingerprint_is_canonical_and_ignores_non_classification_fields(): void
    {
        $snapshot = $this->snapshot();
        $reordered = array_reverse($snapshot, true);
        $reordered['current_value'] = '010.00000000';

        $expected = $this->fingerprints->hash($snapshot);
        $this->assertSame($expected, $this->fingerprints->hash($reordered));
        $this->assertSame($expected, $this->fingerprints->hash($snapshot + ['computed_at' => '2099-01-01 00:00:00']));
        $this->assertSame($expected, $this->fingerprints->hash($snapshot + ['d364_value' => '999999.00000000']));
        $this->assertSame(
            $this->fingerprints->hash($snapshot + ['current_value' => '0']),
            $this->fingerprints->hash($snapshot + ['current_value' => '0.00000000']),
        );
    }

    public function test_every_classification_input_changes_the_fingerprint_and_null_differs_from_zero(): void
    {
        $snapshot = $this->snapshot();
        $original = $this->fingerprints->hash($snapshot);

        foreach ([
            'metric_key' => 'search_console_impressions',
            'data_date' => '2026-08-20',
            'current_value' => '11.00000000',
            'baseline_value' => '21.00000000',
            'absolute_change' => '-9.00000000',
            'relative_change' => '-0.45000000',
            'is_evaluable' => false,
            'evaluation_reason' => 'insufficient_history',
            'engine_version' => 'same_weekday_v2',
        ] as $field => $value) {
            $this->assertNotSame($original, $this->fingerprints->hash([...$snapshot, $field => $value]), $field);
        }

        $this->assertNotSame(
            $this->fingerprints->hash([...$snapshot, 'relative_change' => null]),
            $this->fingerprints->hash([...$snapshot, 'relative_change' => '0']),
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'metric_key' => 'search_console_clicks',
            'data_date' => '2026-08-19',
            'current_value' => '10.00000000',
            'baseline_value' => '20.00000000',
            'absolute_change' => '-10.00000000',
            'relative_change' => '-0.50000000',
            'is_evaluable' => true,
            'evaluation_reason' => null,
            'engine_version' => 'same_weekday_v1',
        ];
    }
}

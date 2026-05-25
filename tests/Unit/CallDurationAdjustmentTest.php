<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallMetricsAggregator;
use App\Services\Reports\Calls\SalesforceCallSyncService;
use Tests\TestCase;

class CallDurationAdjustmentTest extends TestCase
{
    public function test_ajusta_duracion_por_origen_y_no_baja_de_cero(): void
    {
        $service = app(SalesforceCallSyncService::class);

        $this->assertSame(75, $service->adjustedDuration(80, 'commercial_direct'));
        $this->assertSame(70, $service->adjustedDuration(80, 'switchboard'));
        $this->assertSame(70, $service->adjustedDuration(80, 'portal'));
        $this->assertSame(0, $service->adjustedDuration(4, 'commercial_direct'));
        $this->assertSame(0, $service->adjustedDuration(4, 'portal'));
    }

    public function test_promedio_usa_solo_llamadas_atendidas(): void
    {
        $aggregator = app(CallMetricsAggregator::class);
        $bucket = $aggregator->emptyBucket();

        $aggregator->add($bucket, ['is_answered' => true, 'adjusted_duration_seconds' => 40, 'direction' => 'inbound']);
        $aggregator->add($bucket, ['is_answered' => false, 'adjusted_duration_seconds' => 100, 'direction' => 'inbound']);

        $this->assertSame(40.0, $aggregator->finalize($bucket)['average_talk_seconds']);
    }
}

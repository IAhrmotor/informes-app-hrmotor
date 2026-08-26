<?php

namespace Tests\Unit;

use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use Tests\TestCase;

class CommercialPerformanceKpiTest extends TestCase
{
    public function test_fronteras_exactas_del_semaforo(): void
    {
        $service = app(CommercialPerformanceDatasetService::class);

        $this->assertSame('green', $service->trafficLight(100));
        $this->assertSame('yellow', $service->trafficLight(99.99));
        $this->assertSame('yellow', $service->trafficLight(80));
        $this->assertSame('orange', $service->trafficLight(79.99));
        $this->assertSame('orange', $service->trafficLight(60));
        $this->assertSame('red', $service->trafficLight(59.99));
    }
}

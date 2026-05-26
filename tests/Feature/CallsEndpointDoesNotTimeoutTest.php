<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsEndpointDoesNotTimeoutTest extends TestCase
{
    use CreatesCallDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_summary_responde_con_volumen_razonable(): void
    {
        $this->insertCallRows(1500);

        $startedAt = microtime(true);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1500);

        $this->assertLessThan(5.0, microtime(true) - $startedAt);
    }
}

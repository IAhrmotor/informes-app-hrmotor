<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsEndpointsPerformanceTest extends TestCase
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

    public function test_calls_endpoints_respond_with_large_local_dataset(): void
    {
        $this->insertCallRows(1200, [
            'portales_raw' => 'Coches.net',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'operational_team' => 'contact_center',
        ]);

        $this->getJson('/informes/llamadas/data/summary')->assertOk()->assertJsonPath('kpis.total_calls', 1200);
        $this->getJson('/informes/llamadas/data/agents')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/informes/llamadas/data/delegations')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/informes/llamadas/data/portals')->assertOk()->assertJsonPath('ok', true);
    }
}

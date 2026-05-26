<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsCacheKeyIncludesFiltersTest extends TestCase
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

    public function test_cambiar_filtro_usa_cache_distinta(): void
    {
        $this->callRow('inbound', ['direction' => 'inbound']);
        $this->callRow('outbound', ['direction' => 'outbound']);

        $this->getJson('/informes/llamadas/data/summary?direction=inbound')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1)
            ->assertJsonPath('kpis.inbound_calls', 1);

        $this->getJson('/informes/llamadas/data/summary?direction=outbound')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1)
            ->assertJsonPath('kpis.outbound_calls', 1);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 2);
    }
}

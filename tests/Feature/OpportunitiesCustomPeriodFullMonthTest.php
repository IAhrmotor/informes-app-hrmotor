<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class OpportunitiesCustomPeriodFullMonthTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_custom_period_includes_complete_month_until_exclusive_next_day(): void
    {
        $this->opportunityRow('006-april-start', [
            'created_date' => '2026-04-01 00:00:00',
        ]);
        $this->opportunityRow('006-april-end', [
            'created_date' => '2026-04-30 23:59:59',
        ]);
        $this->opportunityRow('006-may-start', [
            'created_date' => '2026-05-01 00:00:00',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-04-01',
            'current_end' => '2026-04-30',
            'comparison_start' => '2026-03-01',
            'comparison_end' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertJsonPath('periodo_actual.inicio', '2026-04-01')
            ->assertJsonPath('periodo_actual.fin', '2026-04-30')
            ->assertJsonPath('kpis.oportunidades_totales', 2);
    }
}

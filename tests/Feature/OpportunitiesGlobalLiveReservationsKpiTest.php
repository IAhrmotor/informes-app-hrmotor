<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class OpportunitiesGlobalLiveReservationsKpiTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_global_live_reservations_kpi_ignores_date_and_excludes_closed_or_cv_signed(): void
    {
        $this->opportunityRow('006-old-live', [
            'created_date' => '2025-01-10 10:00:00',
            'reservation' => true,
            'cv_signed' => false,
            'stage_name' => 'Reserva',
        ]);
        $this->opportunityRow('006-current-live', [
            'created_date' => '2026-05-10 10:00:00',
            'reservation' => true,
            'cv_signed' => false,
            'stage_name' => 'Reserva',
        ]);
        $this->opportunityRow('006-closed-lost', [
            'created_date' => '2026-05-11 10:00:00',
            'reservation' => true,
            'cv_signed' => false,
            'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunityRow('006-cv-signed', [
            'created_date' => '2026-05-12 10:00:00',
            'reservation' => true,
            'cv_signed' => true,
            'stage_name' => 'Contrato',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('kpis.reservas_vivas_actuales_salesforce', 2);
    }

    public function test_global_live_reservations_kpi_respects_opportunity_type_filter(): void
    {
        $this->opportunityRow('006-sale', [
            'record_type_name' => 'Venta',
            'reservation' => true,
            'cv_signed' => false,
        ]);
        $this->opportunityRow('006-appraisal', [
            'record_type_name' => 'Tasacion',
            'reservation' => true,
            'cv_signed' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query([
            'opportunity_type' => 'Venta',
        ]))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_vivas_actuales_salesforce', 1);
    }
}

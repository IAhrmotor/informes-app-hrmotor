<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ReservationsSalesCohortAndDuplicateQualityTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    public function test_fecha_de_creacion_define_una_cohorte_unica_y_admite_resultados_posteriores(): void
    {
        $this->opportunityRow('006-cohort', [
            'created_date' => '2026-07-15 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-03',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-cohort',
        ]);
        $this->opportunityRow('006-outside', [
            'created_date' => '2026-06-30 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-outside',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('universe_date_criterion', 'created_date')
            ->assertJsonPath('universe_date_label', 'Fecha de creación')
            ->assertJsonPath('kpis.oportunidades_totales', 1)
            ->assertJsonPath('kpis.cv_firmados', 1);
    }

    public function test_ventas_y_reservas_duplicadas_cuentan_una_vez_y_exponen_la_incidencia(): void
    {
        foreach ([
            ['006-sale-a', '005-a', 'Comercial A', 'Alcobendas'],
            ['006-sale-b', '005-b', 'Comercial B', 'Alicante'],
        ] as [$id, $ownerId, $ownerName, $delegation]) {
            $this->opportunityRow($id, [
                'created_date' => '2026-07-10 10:00:00',
                'cv_signed' => true,
                'cv_signed_date' => '2026-07-22',
                'stage_name' => 'Contrato',
                'vehicle_interest_id' => '01t-duplicated-sale',
                'vehicle_plate' => '1234ABC',
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'owner_delegation' => $delegation,
            ]);
        }

        foreach (['006-reservation-a', '006-reservation-b'] as $id) {
            $this->opportunityRow($id, [
                'created_date' => '2026-07-12 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-07-25',
                'vehicle_interest_id' => '01t-duplicated-reservation',
                'vehicle_plate' => '5678DEF',
            ]);
        }

        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 4)
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('data_quality.duplicate_event_groups', 2)
            ->json();

        $saleIncident = collect(data_get($summary, 'data_quality.incidents'))->firstWhere('type', 'sale');
        $this->assertSame(['006-sale-a', '006-sale-b'], $saleIncident['opportunity_ids']);
        $this->assertContains('owner_id', $saleIncident['conflicting_fields']);
        $this->assertNotContains('delivery_store', $saleIncident['conflicting_fields']);
        $this->assertSame('data_quality_incident', $saleIncident['breakdown_status']);

        $commercials = $this->getJson('/informes/reservas-ventas/data/commercials?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json('commercials');
        $incidentRow = collect($commercials)->firstWhere('comercial', 'Incidencia de datos');
        $this->assertSame(1, $incidentRow['cv_firmados']);

        $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.http_build_query(array_merge(
            $this->julyFilters(),
            ['metric' => 'cv_firmados'],
        )))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('audit_rows', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.quality_status', 'duplicate_event');
    }

    private function julyFilters(): array
    {
        return [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-07-01',
            'current_end' => '2026-07-31',
            'comparison_start' => '2026-06-01',
            'comparison_end' => '2026-06-30',
        ];
    }
}

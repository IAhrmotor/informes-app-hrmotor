<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_commercials_y_portals_devuelven_datos(): void
    {
        $this->createOpportunity('006-1', [
            'created_date' => '2026-05-10 10:00:00',
            'owner_name' => 'Comercial Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'record_type_name' => 'Venta',
            'reservation' => true,
            'cv_signed' => false,
            'stage_name' => 'Reserva',
        ]);
        $this->createOpportunity('006-2', [
            'created_date' => '2026-05-11 10:00:00',
            'owner_name' => 'Comercial Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Meta',
            'record_type_name' => 'Cambio',
            'reservation' => true,
            'cv_signed' => true,
            'stage_name' => 'Contrato',
        ]);
        $this->createOpportunity('006-3', [
            'created_date' => '2026-05-12 10:00:00',
            'owner_name' => 'Comercial Dos',
            'owner_delegation' => 'Sant Boi',
            'portal_resolved' => 'Web',
            'record_type_name' => 'Tasacion',
            'reservation' => true,
            'cv_signed' => true,
            'stage_name' => 'Cerrada Perdida',
        ]);

        $query = [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ];

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 3)
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('kpis.oportunidades_caidas', 1)
            ->assertJsonStructure(['executive_insights', 'executive_insights_source']);

        $this->getJson('/informes/reservas-ventas/data/commercials?'.http_build_query($query))
            ->assertOk()
            ->assertJsonStructure(['zones', 'delegations', 'commercials']);

        $this->getJson('/informes/reservas-ventas/data/portals?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('items.0.portal', 'Web');
    }

    private function createOpportunity(string $id, array $attributes): void
    {
        SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'owner_id' => '005-'.$id,
            'owner_name' => 'Comercial',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_resolution_source' => 'opportunity',
            'reservation' => false,
            'cv_signed' => false,
        ], $attributes));
    }
}

<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDateCriterionTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtra_por_creacion_reserva_y_firma_cv(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-created',
            'created_date' => '2026-05-08 10:00:00',
            'reservation_date' => '2026-04-01',
            'cv_signed_date' => '2026-04-02',
            'stage_name' => 'Reserva',
            'owner_id' => '005-1',
            'owner_name' => 'Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-reserved',
            'created_date' => '2026-04-01 10:00:00',
            'reservation_date' => '2026-05-08',
            'cv_signed_date' => '2026-04-02',
            'stage_name' => 'Reserva',
            'owner_id' => '005-2',
            'owner_name' => 'Dos',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'reservation' => true,
            'cv_signed' => false,
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-signed',
            'created_date' => '2026-04-01 10:00:00',
            'reservation_date' => '2026-04-02',
            'cv_signed_date' => '2026-05-08',
            'stage_name' => 'Contrato',
            'owner_id' => '005-3',
            'owner_name' => 'Tres',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'reservation' => true,
            'cv_signed' => true,
        ]);

        $base = [
            'period' => 'custom',
            'current_start' => '2026-05-08',
            'current_end' => '2026-05-09',
            'comparison_start' => '2026-05-01',
            'comparison_end' => '2026-05-02',
        ];

        $this->assertTotal(1, array_merge($base, ['date_criterion' => 'created_date']));
        $this->assertTotal(1, array_merge($base, ['date_criterion' => 'reservation_date']));
        $this->assertTotal(1, array_merge($base, ['date_criterion' => 'cv_signed_date']));
    }

    private function assertTotal(int $expected, array $query): void
    {
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', $expected);
    }
}

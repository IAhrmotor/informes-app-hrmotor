<?php

namespace Tests\Feature;

use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ReservationsSalesCohortAndDuplicateQualityTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    public function test_reservas_ventas_no_devuelve_conclusiones_evaluativas_ni_recomendaciones(): void
    {
        $this->opportunityRow('006-descriptive', [
            'created_date' => '2026-07-15 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-20',
            'stage_name' => 'Reserva',
        ]);

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json();

        $this->assertSame([], $payload['executive_insights']);
        $this->assertSame([], $payload['insights']);
        $this->assertSame('none', $payload['executive_insights_source']);
        $this->assertArrayNotHasKey('recommendation', $payload);
        $this->assertArrayNotHasKey('recomendacion', $payload);
        $this->assertArrayNotHasKey('problem_detected', $payload);
        $this->assertArrayNotHasKey('problema_detectado', $payload);

        $html = $this->get('/informes/reservas-ventas')->assertOk()->getContent();
        foreach (['baja conversión', 'problemas de cierre', 'necesita formación', 'portal ineficiente'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($html));
        }
    }

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

    public function test_kpi_y_csv_comparten_ids_incluso_sin_relaciones_y_minimizan_datos_personales(): void
    {
        $pii = [
            'account_name' => 'PERSONA FICTICIA DETECTABLE',
            'account_phone' => '+34999999999',
            'account_person_email' => 'persona-detectable@example.test',
            'account_company_email' => 'empresa-detectable@example.test',
        ];
        $rows = [
            '006-normal' => [],
            '006-without-account' => array_merge($pii, ['account_id' => null]),
            '006-without-owner' => ['owner_id' => null, 'owner_name' => null],
            '006-without-product' => ['vehicle_interest_id' => null, 'vehicle_plate' => null],
            '006-duplicate-a' => ['vehicle_interest_id' => '01t-duplicate', 'reservation_date' => '2026-07-20'],
            '006-duplicate-b' => ['vehicle_interest_id' => '01t-duplicate', 'reservation_date' => '2026-07-20'],
        ];

        foreach ($rows as $id => $overrides) {
            $this->opportunityRow($id, array_merge([
                'name' => 'OPPORTUNITY '.$id.' PERSONA FICTICIA',
                'created_date' => '2026-07-15 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-07-'.str_pad((string) (10 + count($overrides)), 2, '0', STR_PAD_LEFT),
                'stage_name' => 'Reserva',
            ], $overrides));
        }

        $filters = array_merge($this->julyFilters(), ['metric' => 'oportunidades_totales']);
        $request = Request::create('/diagnostics/reservas-ventas', 'GET', $filters);
        $kpiIds = app(ReservationsSalesDashboardDatasetService::class)->cohortOpportunityIds($request);
        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json();
        $csv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.http_build_query($filters))
            ->assertOk()
            ->streamedContent();
        [$header, $records] = $this->csvRecords($csv);
        $idIndex = array_search('Opportunity ID', $header, true);
        $csvIds = collect($records)->pluck($idIndex)->sort()->values()->all();

        $this->assertSame(count($kpiIds), data_get($summary, 'kpis.oportunidades_totales'));
        $this->assertSame($kpiIds, $csvIds);
        foreach (['Opportunity name', 'Account name', 'Account phone', 'Account person email', 'Account company email'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $header);
        }
        foreach (array_merge(array_values($pii), ['PERSONA FICTICIA']) as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $csv);
        }

        $duplicateFilters = array_merge($this->julyFilters(), ['metric' => 'reservas_vivas']);
        $duplicateCsv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.http_build_query($duplicateFilters))
            ->assertOk()
            ->streamedContent();
        [$duplicateHeader, $duplicateRecords] = $this->csvRecords($duplicateCsv);
        $countedIndex = array_search('Contado en KPI', $duplicateHeader, true);
        $duplicateIdIndex = array_search('Opportunity ID', $duplicateHeader, true);

        $this->assertEqualsCanonicalizing(array_keys($rows), array_column($duplicateRecords, $duplicateIdIndex));
        $this->assertContains('0', array_column($duplicateRecords, $countedIndex));
    }

    private function csvRecords(string $content): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', trim($content)),
            fn (string $line): bool => $line !== ''
        ));
        $records = array_map(function (string $line): array {
            $record = str_getcsv($line);
            $record[0] = ltrim($record[0], "\xEF\xBB\xBF");

            return $record;
        }, $lines);

        return [array_shift($records), $records];
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

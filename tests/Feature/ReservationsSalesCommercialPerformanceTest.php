<?php

namespace Tests\Feature;

use App\Models\CommercialDelegationSnapshot;
use App\Models\CommercialPerformanceMonthlyTarget;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Models\SalesforceUser;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReservationsSalesCommercialPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_actividad_mensual_reutiliza_comercial_efectivo_y_fechas_de_cada_hito(): void
    {
        $this->coverHistoryMonth('2026-08');
        $this->commercial('005-worker', 'Comercial Worker');
        $this->snapshot('005-worker', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-converted',
            'name' => 'Lead convertido',
            'created_date' => '2026-07-01 08:00:00',
            'fecha_asignacion' => '2026-08-05 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Lead',
            'record_type_normalized' => 'venta',
            'owner_id' => '005-owner-different',
            'owner_name' => 'Owner distinto',
            'persona_que_trabajo_id' => '005-worker',
            'persona_que_trabajo_name' => 'Comercial Worker',
            'is_deleted' => false,
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-appraisal',
            'name' => 'Tasación fuera',
            'created_date' => '2026-08-01 08:00:00',
            'fecha_asignacion' => '2026-08-06 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Tasación',
            'record_type_normalized' => 'tasacion',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'is_deleted' => false,
        ]);

        $this->opportunity('006-activity', [
            'created_date' => '2026-08-02 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-08-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-20',
            'stage_name' => 'Contrato',
            'informe_rentabilidad' => 1500,
        ]);
        $this->opportunity('006-old-reservation-sale', [
            'created_date' => '2026-07-02 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-21',
            'stage_name' => 'Contrato',
            'informe_rentabilidad' => null,
        ]);
        $lost = $this->opportunity('006-cancelled', [
            'created_date' => '2026-07-03 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-11',
            'stage_name' => 'Cerrada Perdida',
            'close_date' => '2026-09-13',
            'salesforce_last_modified_at' => '2026-08-24 12:00:00',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-history',
            'opportunity_salesforce_id' => $lost->salesforce_id,
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-22 11:00:00',
            'reservation_date' => '2026-07-11',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => true,
            'quality_status' => 'valid',
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('semantics.cohort', false)
            ->assertJsonPath('semantics.cancellation_date_field', 'salesforce_opportunity_stage_transitions.transitioned_at')
            ->assertJsonPath('items.0.commercial_id', '005-worker')
            ->assertJsonPath('items.0.leads', 1)
            ->assertJsonPath('items.0.opportunities', 1)
            ->assertJsonPath('items.0.reservations_total', 1)
            ->assertJsonPath('items.0.reservations_active', 0)
            ->assertJsonPath('items.0.sales', 2)
            ->assertJsonPath('items.0.cancellations', 1)
            ->assertJsonPath('items.0.reservation_to_sale_pct', 200)
            ->assertJsonPath('items.0.margin_total', 1500)
            ->assertJsonPath('items.0.sales_with_margin', 1)
            ->assertJsonPath('items.0.sales_without_margin', 1)
            ->assertJsonPath('items.0.margin_coverage_pct', 50);

        $this->assertSame(2, collect($response->json('evolution'))->firstWhere('month', '2026-07')['reservations_total']);
    }

    public function test_filtro_comercial_no_recalcula_ranking_ni_media_de_delegacion(): void
    {
        foreach ([['005-a', 'Ana'], ['005-b', 'Bea']] as [$id, $name]) {
            $this->commercial($id, $name);
            $this->snapshot($id, 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            SalesforceLead::query()->create([
                'salesforce_id' => '00Q-'.$id,
                'name' => 'Lead '.$name,
                'created_date' => '2026-08-01 08:00:00',
                'fecha_asignacion' => '2026-08-02 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'record_type_normalized' => 'venta',
                'owner_id' => $id,
                'owner_name' => $name,
                'is_deleted' => false,
            ]);
        }
        $this->opportunity('006-a', [
            'owner_id' => '005-a', 'owner_name' => 'Ana',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-a')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ranking', 1)
            ->assertJsonPath('items.0.delegation_average_reservations', 0.5)
            ->assertJsonPath('items.0.delegation_reservations_deviation', 0.5);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-b')
            ->assertOk()
            ->assertJsonPath('items.0.ranking', 2)
            ->assertJsonPath('items.0.delegation_average_reservations', 0.5);
    }

    public function test_cumplimiento_agregado_suma_objetivos_individuales_en_resumen_evolucion_y_filtro(): void
    {
        foreach ([['005-target-a', 'Objetivo A'], ['005-target-b', 'Objetivo B']] as [$commercialId, $name]) {
            $this->commercial($commercialId, $name);
            $this->snapshot($commercialId, 'Alicante', 'Zona Mediterraneo', '2026-05-01');

            foreach (range(1, 18) as $index) {
                $this->opportunity("006-july-{$commercialId}-{$index}", [
                    'owner_id' => $commercialId, 'owner_name' => $name,
                    'created_date' => '2026-07-05 10:00:00',
                    'reservation' => true, 'reservation_date' => '2026-07-05',
                ]);
            }

            foreach (range(1, 9) as $index) {
                $this->opportunity("006-august-{$commercialId}-{$index}", [
                    'owner_id' => $commercialId, 'owner_name' => $name,
                    'created_date' => '2026-08-05 10:00:00',
                    'reservation' => true, 'reservation_date' => '2026-08-05',
                ]);
            }
        }

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 18)
            ->assertJsonPath('summary.objective', 36)
            ->assertJsonPath('summary.fulfillment_pct', 50);
        $july = collect($response->json('evolution'))->firstWhere('month', '2026-07');
        $this->assertSame(36, $july['reservations_total']);
        $this->assertSame(36, $july['objective']);
        $this->assertSame(100, $july['fulfillment_pct']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 36)
            ->assertJsonPath('summary.objective', 36)
            ->assertJsonPath('summary.fulfillment_pct', 100);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-target-a')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 9)
            ->assertJsonPath('summary.objective', 18)
            ->assertJsonPath('summary.fulfillment_pct', 50)
            ->assertJsonPath('items.0.objective', 18)
            ->assertJsonPath('items.0.fulfillment_pct', 50);
    }

    public function test_margen_medio_usa_solo_ventas_con_margen_informado(): void
    {
        $this->commercial('005-margin', 'Comercial margen');
        $this->snapshot('005-margin', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([2000, 1500, null] as $index => $margin) {
            $this->opportunity('006-margin-'.$index, [
                'owner_id' => '005-margin', 'owner_name' => 'Comercial margen',
                'cv_signed' => true, 'cv_signed_date' => '2026-08-10',
                'informe_rentabilidad' => $margin,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.sales', 3)
            ->assertJsonPath('items.0.margin_total', 3500)
            ->assertJsonPath('items.0.average_margin_per_sale', 1750)
            ->assertJsonPath('items.0.sales_with_margin', 2)
            ->assertJsonPath('items.0.sales_without_margin', 1);
    }

    public function test_objetivo_es_mensual_tiene_default_y_validacion_protegida(): void
    {
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 18)
            ->assertJsonPath('objective.is_explicit', false);

        $this->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
            'month' => '2026-08',
            'reservations_target' => 20,
        ])->assertOk()->assertJsonPath('reservations_target', 20);

        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 20,
            'is_explicit' => true,
        ]);
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()->assertJsonPath('objective.reservations_target', 18);
        CommercialPerformanceMonthlyTarget::query()
            ->whereDate('month', '2026-07-01')
            ->update(['reservations_target' => 23, 'updated_at' => now()->addSecond()]);
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 23)
            ->assertJsonPath('objective.is_explicit', false);
        $this->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
            'month' => '2026-08', 'reservations_target' => 0,
        ])->assertUnprocessable();
    }

    public function test_endpoints_solo_permiten_administrador_y_direccion(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-performance@example.test');
        $this->withSession($this->sessionFor($director))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk();

        $viewer = $this->reportUser(ReportUser::ROLE_VIEWER, 'viewer-performance@example.test');
        $this->withSession($this->sessionFor($viewer))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertForbidden();
        $this->withSession($this->sessionFor($viewer))
            ->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
                'month' => '2026-08', 'reservations_target' => 30,
            ])->assertForbidden();
        $this->withSession($this->sessionFor($viewer))
            ->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertForbidden();
    }

    public function test_migracion_usa_identificadores_mysql_validos_y_conserva_fk_y_unique(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_25_120000_create_commercial_performance_foundation.php'));
        $identifiers = [
            'commercial_performance_monthly_targets_month_unique',
            'commercial_perf_target_updated_user_fk',
            'commercial_deleg_snapshot_user_from_uq',
            'commercial_deleg_snapshot_single_open_uq',
            'commercial_deleg_snapshot_user_until_idx',
            'commercial_deleg_snapshot_deleg_from_idx',
            'sf_opp_stage_history_uq',
            'sf_opp_stage_transition_date_stage_idx',
            'sf_opp_stage_transition_opp_date_idx',
            'sf_opp_stage_transition_owner_date_idx',
            'sf_opp_stage_transition_valid_date_idx',
            'sf_opp_history_interval_range_uq',
            'sf_opp_history_interval_coverage_idx',
            'sf_opps_last_modified_idx',
        ];

        $this->assertStringContainsString("foreign('updated_by_report_user_id', 'commercial_perf_target_updated_user_fk')", $migration);
        $this->assertStringContainsString("unique('salesforce_history_id', 'sf_opp_stage_history_uq')", $migration);
        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }

        $updater = $this->reportUser(ReportUser::ROLE_ADMIN, 'target-updater@example.test');
        $target = CommercialPerformanceMonthlyTarget::query()->create([
            'month' => '2026-08-01',
            'reservations_target' => 18,
            'is_explicit' => true,
            'updated_by_report_user_id' => $updater->id,
        ]);
        $updater->delete();
        $this->assertNull($target->fresh()->updated_by_report_user_id);

        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-unique-schema',
            'opportunity_salesforce_id' => '006-unique-schema',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00',
            'source' => 'OpportunityHistory',
            'quality_status' => 'reservation_not_demonstrated',
            'synced_at' => now(),
        ]);

        try {
            SalesforceOpportunityStageTransition::query()->create([
                'salesforce_history_id' => '0Jh-unique-schema',
                'opportunity_salesforce_id' => '006-other-opportunity',
                'new_stage' => 'Cerrada Perdida',
                'transitioned_at' => '2026-08-21 10:00:00',
                'source' => 'OpportunityHistory',
                'quality_status' => 'reservation_not_demonstrated',
                'synced_at' => now(),
            ]);
            $this->fail('salesforce_history_id debe conservar una restricción UNIQUE.');
        } catch (QueryException) {
            $this->assertDatabaseCount('salesforce_opportunity_stage_transitions', 1);
        }
    }

    public function test_dashboard_reutiliza_un_unico_bloque_y_controles_de_organizacion(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-ui-performance@example.test');
        $html = $this->withSession($this->sessionFor($director))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="reportFilters"'));
        $this->assertSame(1, substr_count($html, 'id="zone"'));
        $this->assertSame(1, substr_count($html, 'id="commercialDelegation"'));
        $this->assertSame(1, substr_count($html, 'id="commercial"'));
        $this->assertSame(1, substr_count($html, 'id="resetFilters"'));
        $this->assertSame(1, substr_count($html, 'id="performanceMonth"'));
        $this->assertSame(1, substr_count($html, 'id="performanceTarget"'));
        $this->assertStringNotContainsString('performance-filters', $html);
        $this->assertStringNotContainsString('performanceZone', $html);
        $this->assertStringNotContainsString('performanceDelegation', $html);
        $this->assertStringNotContainsString('performanceCommercial', $html);
        $this->assertStringContainsString('id="performanceColumnsButton"', $html);
        $this->assertStringContainsString('Añadir o quitar columnas', $html);
        $this->assertStringNotContainsString('Media reservas deleg.', $html);
        $this->assertStringNotContainsString('Media deleg.', $html);
        $this->assertStringNotContainsString('Carga bajo demanda.', $html);
        $this->assertStringNotContainsString('id="performanceAuditRows"', $html);
        $this->assertSame(2, substr_count($html, 'class="table-scroll-top is-hidden"'));

        $viewer = $this->reportUser(ReportUser::ROLE_VIEWER, 'viewer-ui-performance@example.test');
        $viewerHtml = $this->withSession($this->sessionFor($viewer))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="performanceMonth"', $viewerHtml);
        $this->assertStringNotContainsString('id="performanceTarget"', $viewerHtml);
        $this->assertStringNotContainsString('panel-rendimiento-comercial', $viewerHtml);
    }

    public function test_javascript_despacha_un_solo_dataset_segun_modo_y_preserva_objetivo_al_limpiar(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));

        $this->assertStringNotContainsString('performanceZone', $javascript);
        $this->assertStringNotContainsString('performanceDelegation', $javascript);
        $this->assertStringNotContainsString('performanceCommercial', $javascript);
        $this->assertStringContainsString("setParam(params, 'zone', document.getElementById('zone').value)", $javascript);
        $this->assertStringContainsString("setParam(params, 'delegation', document.getElementById('commercialDelegation').value)", $javascript);
        $this->assertStringContainsString("setParam(params, 'commercial', document.getElementById('commercial').value)", $javascript);
        $this->assertStringContainsString('if (isCommercialPerformanceMode())', $javascript);
        $this->assertStringContainsString("isCommercialPerformanceMode() || document.getElementById('period')?.value !== 'custom'", $javascript);
        $this->assertStringContainsString('reservationsSalesCommercialPerformanceColumnsV1', $javascript);
        $this->assertStringContainsString("{ key: 'traffic_light', label: 'Semáforo', alwaysVisible: true }", $javascript);
        $this->assertStringContainsString('function formatPerformanceMonth', $javascript);
        $this->assertStringContainsString("return value === null || value === undefined ? 'N/D'", $javascript);
        $this->assertStringContainsString('function invalidatePerformanceAudit', $javascript);
        $this->assertStringContainsString('function initPerformanceScrolls', $javascript);
        $this->assertStringContainsString("bootstrap_approved: 'Bootstrap aprobado'", $javascript);
        $this->assertStringContainsString("observed: 'Observada'", $javascript);
        $this->assertStringContainsString("not_certifiable: 'No certificable'", $javascript);
        $this->assertStringNotContainsString('delegation_average_reservations', $javascript);
        $this->assertStringNotContainsString('delegation_lead_to_reservation_pct', $javascript);

        $resetBlock = substr($javascript, strpos($javascript, 'function bindResetFilters()'), strpos($javascript, 'function bindFilters()') - strpos($javascript, 'function bindResetFilters()'));
        $this->assertStringNotContainsString("document.getElementById('performanceTarget').value", $resetBlock);
    }

    public function test_historico_sin_snapshot_no_inventa_delegacion_ni_ranking(): void
    {
        $this->commercial('005-historic', 'Histórico');
        $this->opportunity('006-historic', [
            'owner_id' => '005-historic',
            'owner_name' => 'Histórico',
            'reservation' => true,
            'reservation_date' => '2026-08-05',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.delegation', 'Histórico no certificable')
            ->assertJsonPath('items.0.delegation_certified', false)
            ->assertJsonPath('items.0.delegation_status', 'not_certifiable')
            ->assertJsonPath('items.0.ranking', null)
            ->assertJsonPath('items.0.delegation_average_reservations', null)
            ->assertJsonPath('filters.commercials.0.id', '005-historic');
    }

    public function test_bootstrap_aprobado_habilita_zona_delegacion_y_ranking_sin_llamarlo_observado(): void
    {
        $this->commercial('005-bootstrap-filter', 'Comercial bootstrap');
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-bootstrap-filter', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-03-31 22:00:00', 'observed_until' => '2026-08-01 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-bootstrap-filter', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-01 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('items.0.delegation_status', 'bootstrap_approved')
            ->assertJsonPath('items.0.ranking', 1)
            ->assertJsonPath('filters.zones.0', 'Zona Mediterraneo')
            ->assertJsonPath('filters.delegations.0', 'Alicante')
            ->assertJsonPath('filters.commercials.0.id', '005-bootstrap-filter')
            ->assertJsonPath('data_quality.delegation_history_evaluable_from', '2026-03-31 22:00:00')
            ->assertJsonPath('data_quality.delegation_history_bootstrap_from', '2026-03-31 22:00:00')
            ->assertJsonPath('data_quality.delegation_history_observed_from', '2026-08-01 00:00:00');
    }

    public function test_universo_incluye_venta_cambio_lead_ayvens_y_excluye_tasacion(): void
    {
        $this->commercial('005-universe', 'Universo');
        $this->snapshot('005-universe', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        foreach ([
            ['00Q-venta', 'Venta', 'venta'],
            ['00Q-cambio', 'Venta con cambio', 'venta_con_cambio'],
            ['00Q-lead', 'Lead', 'lead'],
            ['00Q-ayvens', 'Ayvens', 'ayvens'],
            ['00Q-tasacion', 'Tasación', 'tasacion'],
        ] as [$id, $raw, $normalized]) {
            SalesforceLead::query()->create([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-08-01 08:00:00',
                'fecha_asignacion' => '2026-08-02 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => $raw,
                'record_type_normalized' => $normalized,
                'owner_id' => '005-universe',
                'owner_name' => 'Universo',
                'is_deleted' => false,
            ]);
        }

        $this->opportunity('006-sale', ['owner_id' => '005-universe', 'owner_name' => 'Universo']);
        $this->opportunity('006-change', ['owner_id' => '005-universe', 'owner_name' => 'Universo', 'record_type_name' => 'Cambio']);
        $this->opportunity('006-appraisal', ['owner_id' => '005-universe', 'owner_name' => 'Universo', 'record_type_name' => 'Tasacion']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.leads', 4)
            ->assertJsonPath('items.0.opportunities', 2);
    }

    public function test_intervalos_preservan_cambio_de_delegacion_e_inactivo_con_actividad(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-moved',
            'name' => 'Comercial inactivo',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR MURCIA',
            'is_active' => false,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-moved', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-01-01', 'observed_until' => '2026-07-31 22:00:00', 'source' => 'test',
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-moved', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'source' => 'test',
        ]);
        $this->opportunity('006-june', [
            'owner_id' => '005-moved', 'owner_name' => 'Comercial inactivo',
            'created_date' => '2026-06-05', 'reservation' => true, 'reservation_date' => '2026-06-06',
        ]);
        $this->opportunity('006-august', [
            'owner_id' => '005-moved', 'owner_name' => 'Comercial inactivo',
            'created_date' => '2026-08-05', 'reservation' => true, 'reservation_date' => '2026-08-06',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-06')
            ->assertOk()->assertJsonPath('items.0.delegation', 'Alicante');
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()->assertJsonPath('items.0.delegation', 'Murcia');
    }

    public function test_cambio_de_perfil_no_elimina_actividad_historica_ni_crea_roster_futuro(): void
    {
        $this->commercial('005-historical-profile', 'Histórico tras perfil');
        $this->snapshot('005-historical-profile', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-historical-profile', 'name' => 'Lead histórico',
            'created_date' => '2026-06-05 10:00:00', 'fecha_asignacion' => '2026-06-05 10:00:00',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-historical-profile', 'owner_name' => 'Histórico tras perfil', 'is_deleted' => false,
        ]);
        $this->opportunity('006-historical-profile', [
            'owner_id' => '005-historical-profile', 'owner_name' => 'Histórico tras perfil',
            'created_date' => '2026-06-06 10:00:00', 'reservation' => true, 'reservation_date' => '2026-06-10',
        ]);
        SalesforceUser::query()->where('salesforce_id', '005-historical-profile')->update(['profile_name' => 'Marketing']);
        app(CommercialDelegationSnapshotService::class)
            ->captureCurrentUsers(CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'));

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-06')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-historical-profile')
            ->assertJsonPath('items.0.commercial', 'Histórico tras perfil')
            ->assertJsonPath('items.0.delegation', 'Alicante')
            ->assertJsonPath('items.0.leads', 1)
            ->assertJsonPath('items.0.opportunities', 1)
            ->assertJsonPath('items.0.reservations_total', 1);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-09')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_responsables_no_comerciales_quedan_en_incidencia_sin_objetivo_y_auditables(): void
    {
        foreach ([
            ['005-api-user', 'API User', 'API User'],
            ['005-marketing-user', 'Marketing', 'Marketing'],
            ['005-admin-user', 'Administrador', 'System Administrator'],
        ] as [$id, $name, $profile]) {
            SalesforceUser::query()->create([
                'salesforce_id' => $id, 'name' => $name, 'profile_name' => $profile,
                'user_delegation' => null, 'is_active' => true,
            ]);
        }
        $this->opportunity('006-api-user', [
            'owner_id' => '005-api-user', 'owner_name' => 'API User',
            'created_date' => '2026-08-02 10:00:00',
        ]);
        $this->opportunity('006-marketing-user', [
            'owner_id' => '005-marketing-user', 'owner_name' => 'Marketing',
            'created_date' => '2026-08-03 10:00:00',
            'reservation' => true, 'reservation_date' => '2026-08-04',
        ]);
        $this->opportunity('006-admin-user', [
            'owner_id' => '005-admin-user', 'owner_name' => 'Administrador',
            'created_date' => '2026-08-05 10:00:00',
            'cv_signed' => true, 'cv_signed_date' => '2026-08-06', 'stage_name' => 'Contrato',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-api-user',
            'opportunity_salesforce_id' => '006-api-user',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-07 10:00:00', 'reservation_date' => '2026-08-01',
            'owner_id' => '005-api-user', 'owner_name' => 'API User',
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => true,
            'quality_status' => 'valid', 'synced_at' => now(),
        ]);
        $this->coverHistoryMonth('2026-08');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', null)
            ->assertJsonPath('items.0.opportunities', 3)
            ->assertJsonPath('items.0.reservations_total', 1)
            ->assertJsonPath('items.0.sales', 1)
            ->assertJsonPath('items.0.cancellations', 1)
            ->assertJsonPath('items.0.objective', null)
            ->assertJsonPath('items.0.fulfillment_pct', null)
            ->assertJsonPath('items.0.traffic_light', null)
            ->assertJsonPath('items.0.ranking', null)
            ->assertJsonPath('summary.objective', 0)
            ->assertJsonPath('summary.fulfillment_pct', null);

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()->json('items'));
        foreach (['006-api-user', '006-marketing-user', '006-admin-user'] as $opportunityId) {
            $rows = $audit->where('opportunity_id', $opportunityId);
            $this->assertNotEmpty($rows);
            $this->assertTrue($rows->every(fn (array $row): bool => $row['counted_in_metric'] === false));
            $this->assertTrue($rows->contains(fn (array $row): bool => $row['exclusion_reason'] === 'non_commercial_responsible'));
        }
    }

    public function test_duplicado_con_atribucion_conflictiva_cuenta_una_vez_y_sale_del_ranking(): void
    {
        foreach ([['005-a', 'Ana'], ['005-b', 'Bea']] as [$id, $name]) {
            $this->commercial($id, $name);
            $this->snapshot($id, 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            $this->opportunity('006-'.$id, [
                'owner_id' => $id,
                'owner_name' => $name,
                'reservation' => true,
                'reservation_date' => '2026-08-10',
                'vehicle_interest_id' => '01t-duplicate-performance',
            ]);
        }

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 1)
            ->assertJsonPath('data_quality.duplicate_conflict_groups', 1);
        $incident = collect($response->json('items'))->firstWhere('commercial', 'Incidencia de datos');
        $this->assertSame(1, $incident['reservations_total']);
        $this->assertNull($incident['objective']);
        $this->assertNull($incident['fulfillment_pct']);
        $this->assertNull($incident['traffic_light']);
        $this->assertNull($incident['ranking']);

        $auditRows = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()->json('items'))->where('event_type', 'reservation');
        $this->assertCount(2, $auditRows);
        $this->assertSame(1, $auditRows->where('counted_in_metric', true)->count());
        $this->assertSame('data_quality_incident', $auditRows->firstWhere('counted_in_metric', true)['metric_attribution']);
    }

    public function test_eventos_antes_y_despues_del_primer_snapshot_mantienen_una_fila_y_un_objetivo(): void
    {
        $this->commercial('005-partial', 'Parcial');
        $this->snapshot('005-partial', 'Alicante', 'Zona Mediterraneo', '2026-08-15');
        foreach ([['006-before', '2026-08-05'], ['006-after', '2026-08-20']] as [$id, $date]) {
            $this->opportunity($id, [
                'owner_id' => '005-partial', 'owner_name' => 'Parcial',
                'created_date' => $date, 'reservation' => true, 'reservation_date' => $date,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-partial')
            ->assertJsonPath('items.0.reservations_total', 2)
            ->assertJsonPath('items.0.objective', 18)
            ->assertJsonPath('items.0.delegation_certified', false)
            ->assertJsonPath('items.0.ranking', null);
    }

    public function test_cambio_de_delegacion_dentro_del_mes_no_duplica_fila_ni_objetivo(): void
    {
        $this->commercial('005-change', 'Cambio interno');
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'observed_until' => '2026-08-15 00:00:00', 'source' => 'test',
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-15 00:00:00', 'observed_until' => '2026-08-31 22:00:00', 'source' => 'test',
        ]);
        foreach ([['006-old-team', '2026-08-05'], ['006-new-team', '2026-08-20']] as [$id, $date]) {
            $this->opportunity($id, [
                'owner_id' => '005-change', 'owner_name' => 'Cambio interno',
                'created_date' => $date, 'reservation' => true, 'reservation_date' => $date,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.reservations_total', 2)
            ->assertJsonPath('items.0.objective', 18)
            ->assertJsonPath('items.0.delegation', 'Histórico no certificable')
            ->assertJsonPath('items.0.ranking', null);
    }

    public function test_roster_certificado_incluye_comercial_sin_actividad_en_media_y_ranking(): void
    {
        $this->commercial('005-zero', 'Sin actividad');
        $this->snapshot('005-zero', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.leads', 0)
            ->assertJsonPath('items.0.opportunities', 0)
            ->assertJsonPath('items.0.reservations_total', 0)
            ->assertJsonPath('items.0.sales', 0)
            ->assertJsonPath('items.0.fulfillment_pct', 0)
            ->assertJsonPath('items.0.traffic_light', 'red')
            ->assertJsonPath('items.0.delegation_average_reservations', 0)
            ->assertJsonPath('items.0.ranking', 1);
    }

    public function test_mes_cubierto_sin_transiciones_muestra_cero_cancelaciones(): void
    {
        $this->commercial('005-covered-zero', 'Cubierto cero');
        $this->snapshot('005-covered-zero', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-covered-zero', [
            'owner_id' => '005-covered-zero', 'owner_name' => 'Cubierto cero',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);
        $this->coverHistoryMonth('2026-08');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'covered')
            ->assertJsonPath('items.0.cancellations', 0)
            ->assertJsonPath('items.0.cancellation_pct', 0)
            ->assertJsonPath('items.0.delegation_cancellation_pct', 0);
    }

    public function test_mes_sin_cobertura_no_convierte_cancelaciones_en_cero(): void
    {
        $this->commercial('005-uncovered', 'Sin cobertura');
        $this->snapshot('005-uncovered', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-uncovered', [
            'owner_id' => '005-uncovered', 'owner_name' => 'Sin cobertura',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'uncovered')
            ->assertJsonPath('items.0.cancellations', null)
            ->assertJsonPath('items.0.cancellation_pct', null)
            ->assertJsonPath('items.0.delegation_cancellation_pct', null);
    }

    public function test_mes_con_cobertura_parcial_mantiene_cancelaciones_no_evaluables(): void
    {
        $this->commercial('005-partial-history', 'Cobertura parcial');
        $this->snapshot('005-partial-history', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-partial-history', [
            'owner_id' => '005-partial-history', 'owner_name' => 'Cobertura parcial',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => '2026-08-01 22:00:00', 'range_end' => '2026-08-15 00:00:00',
            'completed_at' => now(), 'queried_rows' => 0,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'partial')
            ->assertJsonPath('items.0.cancellations', null)
            ->assertJsonPath('items.0.cancellation_pct', null);
    }

    public function test_mes_actual_usa_el_ultimo_cutoff_sin_exigir_cobertura_hasta_la_consulta(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $start = CarbonImmutable::parse('2026-08-01 00:00:00', 'Europe/Madrid')->utc();
        $cutoff = CarbonImmutable::parse('2026-08-26 07:10:00', 'Europe/Madrid')->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $cutoff,
            'completed_at' => $cutoff, 'queried_rows' => 0,
        ]);

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('covered', $coverage['status']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['source_cutoff_at']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['certified_until']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', true)
            ->assertJsonPath('data_quality.cancellation_certified_until', $cutoff->toIso8601String());
    }

    public function test_hueco_interno_antes_del_cutoff_deja_mes_actual_parcial(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        foreach ([
            ['2026-07-31 22:00:00', '2026-08-10 00:00:00'],
            ['2026-08-10 01:00:00', '2026-08-26 05:10:00'],
        ] as [$start, $end]) {
            SalesforceOpportunityHistorySyncInterval::query()->create([
                'range_start' => $start, 'range_end' => $end, 'completed_at' => $end, 'queried_rows' => 0,
            ]);
        }

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('partial', $coverage['status']);
        $this->assertSame('2026-08-10T00:00:00+00:00', $coverage['certified_until']);
    }

    public function test_mes_cerrado_exige_fin_completo_y_distingue_cobertura_incompleta_y_completa(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $july = CarbonImmutable::parse('2026-07-01', 'Europe/Madrid');
        $start = $july->startOfMonth()->utc();
        $end = $july->addMonth()->startOfMonth()->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $end->subHour(),
            'completed_at' => $end, 'queried_rows' => 0,
        ]);
        $service = app(CommercialPerformanceDatasetService::class);

        $this->assertSame('partial', $service->historyCoverage(collect([$july]))['2026-07']['status']);

        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $end->subHour(), 'range_end' => $end,
            'completed_at' => $end, 'queried_rows' => 0,
        ]);

        $this->assertSame('covered', $service->historyCoverage(collect([$july]))['2026-07']['status']);
    }

    public function test_dependencia_de_opportunity_no_resuelta_impide_cero_silencioso(): void
    {
        $this->commercial('005-unresolved-history', 'Dependencia pendiente');
        $this->snapshot('005-unresolved-history', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-missing-opportunity',
            'opportunity_salesforce_id' => '006-reserved-in-july-not-local',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'opportunity_not_local', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addMonth(),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', false)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 1)
            ->assertJsonPath('items.0.cancellations', null);
    }

    public function test_candidata_sin_etapa_previa_deja_cancelaciones_no_evaluables(): void
    {
        $this->commercial('005-no-previous-stage', 'Sin etapa previa');
        $this->snapshot('005-no-previous-stage', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-no-previous-stage',
            'opportunity_salesforce_id' => '006-no-previous-stage',
            'previous_stage' => null, 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'previous_stage_not_demonstrated', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addMonth(),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', false)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 1)
            ->assertJsonPath('items.0.cancellations', null);
    }

    public function test_intervalo_certificado_solapado_y_dependencia_resuelta_eliminan_deuda_antigua(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        $cutoff = CarbonImmutable::parse('2026-08-26 10:00:00', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-resolved-dependency',
            'opportunity_salesforce_id' => '006-resolved-dependency',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-10 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'opportunity_not_local', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addDays(15),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $cutoff,
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 0, 'is_kpi_certified' => true,
        ]);
        SalesforceOpportunityStageTransition::query()
            ->where('salesforce_history_id', '0Jh-resolved-dependency')
            ->update(['quality_status' => 'reservation_not_demonstrated', 'updated_at' => now()->addSecond()]);

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('covered', $coverage['status']);
        $this->assertSame(0, $coverage['unresolved_dependencies']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['certified_until']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', true)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 0);
    }

    public function test_auditoria_restringida_expone_ids_atribucion_cobertura_y_exclusiones_sin_pii(): void
    {
        $this->commercial('005-audit', 'Auditable');
        $this->snapshot('005-audit', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-audit', 'name' => 'PII que no debe salir',
            'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-audit', 'owner_name' => 'Auditable', 'is_deleted' => false,
        ]);

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonPath('pii_excluded', true)
            ->assertJsonPath('items.0.lead_id', '00Q-audit')
            ->assertJsonPath('items.0.commercial_id', '005-audit')
            ->assertJsonPath('items.0.delegation', 'Alicante')
            ->assertJsonPath('items.0.delegation_status', 'observed')
            ->assertJsonPath('items.0.delegation_issue', null);
        $this->assertStringNotContainsString('PII que no debe salir', $response->getContent());
    }

    public function test_auditoria_distingue_observacion_bootstrap_y_no_certificable(): void
    {
        foreach ([
            ['005-audit-observed', 'Audit observed'],
            ['005-audit-bootstrap', 'Audit bootstrap'],
            ['005-audit-uncertified', 'Audit uncertified'],
        ] as [$id, $name]) {
            $this->commercial($id, $name);
            SalesforceLead::query()->create([
                'salesforce_id' => '00Q-'.$id, 'name' => 'Dato excluido de respuesta',
                'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
                'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
                'owner_id' => $id, 'owner_name' => $name, 'is_deleted' => false,
            ]);
        }
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-observed', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-bootstrap', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-03-31 22:00:00', 'observed_until' => '2026-08-15 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-bootstrap', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-15 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $items = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->keyBy('commercial_id');

        $this->assertSame('observed', $items['005-audit-observed']['delegation_status']);
        $this->assertTrue($items['005-audit-observed']['delegation_certified']);
        $this->assertSame('bootstrap_approved', $items['005-audit-bootstrap']['delegation_status']);
        $this->assertTrue($items['005-audit-bootstrap']['delegation_certified']);
        $this->assertSame('not_certifiable', $items['005-audit-uncertified']['delegation_status']);
        $this->assertSame('incomplete_history', $items['005-audit-uncertified']['delegation_issue']);
    }

    public function test_auditoria_de_agosto_excluye_exactamente_el_limite_superior(): void
    {
        $this->commercial('005-audit-boundary', 'Límite auditoría');
        $this->snapshot('005-audit-boundary', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-september-boundary', [
            'owner_id' => '005-audit-boundary', 'owner_name' => 'Límite auditoría',
            'created_date' => '2026-09-01 00:00:00',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonMissing(['opportunity_id' => '006-september-boundary']);
    }

    public function test_scheduler_evitar_solapes_y_deja_un_unico_propietario_de_snapshots(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));
        $opportunitiesCommand = file_get_contents(app_path('Console/Commands/SalesforceSyncOpportunitiesCommand.php'));
        $monthlyCommand = file_get_contents(app_path('Console/Commands/SalesforceSyncMonthlyCommercialCommand.php'));

        $this->assertStringContainsString("dailyAt('07:10')", $scheduler);
        $this->assertStringNotContainsString("dailyAt('02:45')", $scheduler);
        $this->assertStringNotContainsString('CommercialDelegationSnapshotService', $opportunitiesCommand);
        $this->assertStringNotContainsString('captureCurrentUsers', $opportunitiesCommand);
        $this->assertStringContainsString('CommercialDelegationSnapshotService', $monthlyCommand);
        $this->assertStringContainsString('captureCurrentUsers', $monthlyCommand);
        $this->assertStringNotContainsString('--bootstrap-performance-history', $scheduler);
    }

    private function commercial(string $id, string $name): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE',
            'is_active' => true,
        ]);
    }

    private function snapshot(string $id, string $delegation, string $zone, string $from): void
    {
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => $id,
            'delegation' => $delegation,
            'zone' => $zone,
            'observed_from' => $from,
            'source' => 'test',
        ]);
    }

    private function coverHistoryMonth(string $month): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Europe/Madrid')->startOfMonth()->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start,
            'range_end' => $start->addMonth(),
            'completed_at' => now(),
            'queried_rows' => 0,
        ]);
    }

    private function opportunity(string $id, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-08-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Reserva',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'owner_delegation' => 'Alicante',
            'reservation' => false,
            'cv_signed' => false,
        ], $overrides));
    }

    private function reportUser(string $role, string $email): ReportUser
    {
        return ReportUser::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }
}

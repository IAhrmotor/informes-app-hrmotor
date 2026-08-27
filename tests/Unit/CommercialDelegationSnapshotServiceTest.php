<?php

namespace Tests\Unit;

use App\Models\CommercialDelegationSnapshot;
use App\Models\OperationalAlert;
use App\Models\SalesforceUser;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceMonthlyRosterService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialDelegationSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_intervalos_aditivos_y_cierra_el_actual_al_cambiar_o_desactivar(): void
    {
        $user = SalesforceUser::query()->create([
            'salesforce_id' => '005-snapshot',
            'name' => 'Snapshot',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE',
            'is_active' => true,
        ]);
        $service = app(CommercialDelegationSnapshotService::class);

        $service->captureCurrentUsers(CarbonImmutable::parse('2026-06-01'));
        $user->update(['user_delegation' => 'HR MOTOR MURCIA']);
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-01'));
        $user->update(['is_active' => false]);
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-20'));

        $rows = CommercialDelegationSnapshot::query()->orderBy('observed_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(CommercialDelegationSnapshotService::SOURCE_OBSERVED, $rows[0]->source);
        $this->assertSame('Alicante', $rows[0]->delegation);
        $this->assertSame('2026-08-01 00:00:00', $rows[0]->observed_until->toDateTimeString());
        $this->assertSame('Murcia', $rows[1]->delegation);
        $this->assertSame('2026-08-20 00:00:00', $rows[1]->observed_until->toDateTimeString());
        $this->assertNull($rows[0]->open_marker);
        $this->assertNull($rows[1]->open_marker);
        $this->assertDatabaseHas('operational_alerts', [
            'type' => 'commercial_organisation_change',
            'severity' => 'low',
            'state' => OperationalAlert::STATE_OPEN,
        ]);
    }

    public function test_reintento_es_idempotente_y_restriccion_impide_dos_intervalos_abiertos(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-single-open', 'name' => 'Único', 'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE', 'is_active' => true,
        ]);
        $service = app(CommercialDelegationSnapshotService::class);
        $at = CarbonImmutable::parse('2026-08-01 10:00:00');
        $service->captureCurrentUsers($at);
        $service->captureCurrentUsers($at);

        $this->assertSame(1, CommercialDelegationSnapshot::query()->whereNull('observed_until')->count());
        $this->expectException(QueryException::class);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-single-open', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-02 10:00:00', 'source' => 'test',
        ]);
    }

    public function test_bootstrap_es_cerrado_idempotente_y_no_mueve_la_observacion_real(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-bootstrap', 'name' => 'Bootstrap', 'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE', 'is_active' => true,
        ]);
        $service = app(CommercialDelegationSnapshotService::class);
        $firstCapture = $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-27 08:00:00', 'UTC'));
        $first = $service->bootstrapHistoricalAssignments();
        $second = $service->bootstrapHistoricalAssignments();

        $this->assertArrayNotHasKey('bootstrap', $firstCapture);
        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['already_present']);
        $bootstrap = CommercialDelegationSnapshot::query()->where('source', CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP)->firstOrFail();
        $observed = CommercialDelegationSnapshot::query()->where('source', CommercialDelegationSnapshotService::SOURCE_OBSERVED)->firstOrFail();
        $this->assertSame('2026-03-31 22:00:00', $bootstrap->observed_from->toDateTimeString());
        $this->assertSame('2026-08-27 08:00:00', $bootstrap->observed_until->toDateTimeString());
        $this->assertNull($bootstrap->open_marker);
        $this->assertSame('2026-08-27 08:00:00', $observed->observed_from->toDateTimeString());
        $this->assertNull($observed->observed_until);
    }

    public function test_no_bootstrapea_sin_dimensiones_o_con_historia_contradictoria(): void
    {
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-missing', 'delegation' => null, 'zone' => null,
            'observed_from' => '2026-08-01 00:00:00', 'observed_until' => '2026-08-02 00:00:00',
            'open_marker' => null, 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-missing', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-02 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-conflict', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-05-01 00:00:00', 'observed_until' => '2026-06-01 00:00:00', 'source' => 'manual_evidence',
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-conflict', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-01 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $result = app(CommercialDelegationSnapshotService::class)->bootstrapHistoricalAssignments();

        $this->assertContains('005-missing', $result['missing_dimensions']);
        $this->assertContains('005-conflict', $result['conflicting_history']);
        $this->assertDatabaseMissing('commercial_delegation_snapshots', [
            'salesforce_user_id' => '005-conflict',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        $this->assertDatabaseHas('operational_alerts', [
            'type' => 'commercial_bootstrap_conflict',
            'severity' => 'low',
            'technical_identifier' => '005-conflict:business_bootstrap_2026_04',
        ]);
    }

    public function test_reintento_del_mismo_estado_no_duplica_alerta(): void
    {
        $user = SalesforceUser::query()->create([
            'salesforce_id' => '005-alert', 'name' => 'Alert', 'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE', 'is_active' => true,
        ]);
        $service = app(CommercialDelegationSnapshotService::class);
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'));
        $user->update(['user_delegation' => 'HR MOTOR MURCIA']);
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-02 00:00:00', 'UTC'));
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-02 00:00:00', 'UTC'));

        $this->assertDatabaseCount('operational_alerts', 1);
        $alert = OperationalAlert::query()->firstOrFail();
        $this->assertSame(1, $alert->occurrences);
        $this->assertSame('005-alert', $alert->context['salesforce_user_id']);
        $this->assertSame('Alicante', $alert->context['previous_delegation']);
        $this->assertSame('Murcia', $alert->context['new_delegation']);
    }

    public function test_reejecutar_bootstrap_no_retroatribuye_comercial_fuera_de_la_cohorte_inicial(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/Madrid'));
        foreach ([
            ['005-ana', 'Ana', 'HR MOTOR ALICANTE'],
            ['005-juan', 'Juan', 'HR MOTOR MURCIA'],
        ] as [$id, $name, $delegation]) {
            SalesforceUser::query()->create([
                'salesforce_id' => $id, 'name' => $name, 'profile_name' => 'Compra/Venta',
                'user_delegation' => $delegation, 'is_active' => true,
            ]);
        }
        $service = app(CommercialDelegationSnapshotService::class);
        $service->captureCurrentUsers(CarbonImmutable::parse('2026-08-27 08:00:00', 'UTC'));
        $initialBootstrap = $service->bootstrapHistoricalAssignments();

        SalesforceUser::query()->create([
            'salesforce_id' => '005-pedro', 'name' => 'Pedro', 'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR MURCIA', 'is_active' => true,
        ]);
        // Medianoche local del 1 de octubre: desde esta primera observación el mes sí es certificable.
        $capture = $service->captureCurrentUsers(CarbonImmutable::parse('2026-09-30 22:00:00', 'UTC'));
        $octoberRerun = $service->bootstrapHistoricalAssignments();

        $this->assertSame(2, $initialBootstrap['created']);
        $this->assertArrayNotHasKey('bootstrap', $capture);
        $this->assertSame(0, $octoberRerun['created']);
        $this->assertSame(2, $octoberRerun['already_present']);
        $this->assertSame(['005-pedro'], $octoberRerun['not_initial_cohort']);
        $this->assertDatabaseMissing('commercial_delegation_snapshots', [
            'salesforce_user_id' => '005-pedro',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        $this->assertDatabaseHas('commercial_delegation_snapshots', [
            'salesforce_user_id' => '005-pedro',
            'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
            'observed_from' => '2026-09-30 22:00:00',
        ]);

        $context = app(CommercialPerformanceMonthlyRosterService::class)
            ->context(collect([
                CarbonImmutable::parse('2026-04-01', 'Europe/Madrid'),
                CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'),
                CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'),
            ]));
        $this->assertArrayNotHasKey('005-pedro', $context['assignments']['2026-04'] ?? []);
        $this->assertArrayNotHasKey('005-pedro', $context['assignments']['2026-09'] ?? []);
        $this->assertSame('Murcia', $context['assignments']['2026-10']['005-pedro']['delegation']);
    }
}

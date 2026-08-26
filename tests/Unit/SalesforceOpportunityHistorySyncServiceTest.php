<?php

namespace Tests\Unit;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunityHistorySyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceOpportunityHistorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_persiste_transicion_demostrable_a_cerrada_perdida_con_reserva_previa(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-verified',
            'created_date' => '2026-07-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Cerrada Perdida',
            'owner_id' => '005-owner',
            'owner_name' => 'Owner',
            'reservation' => true,
            'reservation_date' => '2026-07-10',
            'close_date' => '2026-09-13',
            'salesforce_last_modified_at' => '2026-08-25 12:00:00',
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-no-previous',
            'created_date' => '2026-07-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Cerrada Perdida',
            'reservation' => true,
            'reservation_date' => '2026-07-11',
        ]);

        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                if (str_contains($soql, 'OpportunityId IN')) {
                    return [
                        ['Id' => '0Jh-1', 'OpportunityId' => '006-verified', 'StageName' => 'Reserva', 'CreatedDate' => '2026-08-01T10:00:00.000+0000'],
                        ['Id' => '0Jh-2', 'OpportunityId' => '006-verified', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000'],
                        ['Id' => '0Jh-3', 'OpportunityId' => '006-verified', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-21T10:00:00.000+0000'],
                        ['Id' => '0Jh-4', 'OpportunityId' => '006-no-previous', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-22T10:00:00.000+0000'],
                    ];
                }

                return [
                    ['Id' => '0Jh-2', 'OpportunityId' => '006-verified', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000'],
                    ['Id' => '0Jh-3', 'OpportunityId' => '006-verified', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-21T10:00:00.000+0000'],
                    ['Id' => '0Jh-4', 'OpportunityId' => '006-no-previous', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-22T10:00:00.000+0000'],
                ];
            }
        };

        $result = (new SalesforceOpportunityHistorySyncService($client))->sync(
            CarbonImmutable::parse('2026-08-19', 'UTC'),
            CarbonImmutable::parse('2026-08-23', 'UTC'),
        );

        $this->assertSame(3, $result['candidates']);
        $this->assertSame(2, $result['saved']);
        $this->assertSame(1, $result['verified_cancellations']);
        $this->assertSame(1, $result['non_transitions']);
        $this->assertSame(1, $result['unverifiable']);
        $this->assertSame(1, $result['unresolved_dependencies']);
        $this->assertSame(1, $result['covered_intervals']);
        $this->assertDatabaseHas('salesforce_opportunity_stage_transitions', [
            'salesforce_history_id' => '0Jh-2',
            'opportunity_salesforce_id' => '006-verified',
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00',
            'reservation_date' => '2026-07-10',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => true,
            'quality_status' => 'valid',
        ]);
        $this->assertSame(1, SalesforceOpportunityHistorySyncInterval::query()->count());
        $this->assertDatabaseMissing('salesforce_opportunity_stage_transitions', ['salesforce_history_id' => '0Jh-3']);
        $this->assertDatabaseHas('salesforce_opportunity_stage_transitions', [
            'salesforce_history_id' => '0Jh-4',
            'previous_stage' => null,
            'is_reservation_cancellation' => false,
            'quality_status' => 'previous_stage_not_demonstrated',
        ]);
        $this->assertDatabaseHas('salesforce_opportunity_history_sync_intervals', [
            'unresolved_dependencies' => 1,
            'is_kpi_certified' => false,
        ]);
        $this->assertStringNotContainsString('CloseDate', implode('\n', $client->queries));
        $this->assertStringNotContainsString('LastModifiedDate', implode('\n', $client->queries));
    }

    public function test_reserva_posterior_a_transicion_se_persiste_como_incidencia_no_contable(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-invalid-chronology', 'created_date' => '2026-08-01',
            'record_type_name' => 'Venta', 'stage_name' => 'Cerrada Perdida',
            'owner_id' => '005-owner', 'owner_name' => 'Owner',
            'reservation' => true, 'reservation_date' => '2026-08-25',
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (str_contains($soql, 'OpportunityId IN')) {
                    return [
                        ['Id' => '0Jh-before', 'OpportunityId' => '006-invalid-chronology', 'StageName' => 'Reserva', 'CreatedDate' => '2026-08-19T10:00:00.000+0000'],
                        ['Id' => '0Jh-invalid', 'OpportunityId' => '006-invalid-chronology', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000'],
                    ];
                }

                return [[
                    'Id' => '0Jh-invalid', 'OpportunityId' => '006-invalid-chronology',
                    'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000',
                ]];
            }
        };

        $result = (new SalesforceOpportunityHistorySyncService($client))->sync(
            CarbonImmutable::parse('2026-08-20', 'UTC'),
            CarbonImmutable::parse('2026-08-21', 'UTC'),
        );

        $this->assertSame(1, $result['saved']);
        $this->assertSame(0, $result['verified_cancellations']);
        $this->assertDatabaseHas('salesforce_opportunity_stage_transitions', [
            'salesforce_history_id' => '0Jh-invalid',
            'is_reservation_cancellation' => false,
            'quality_status' => 'reservation_after_transition',
            'reservation_date' => '2026-08-25',
        ]);
    }

    public function test_serializa_null_y_bloquea_cobertura_si_la_opportunity_anterior_no_esta_local(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-no-reservation', 'created_date' => '2026-07-01',
            'record_type_name' => 'Venta', 'stage_name' => 'Cerrada Perdida',
            'reservation' => false, 'reservation_date' => null,
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (str_contains($soql, 'OpportunityId IN')) {
                    return [
                        ['Id' => '0Jh-missing-before', 'OpportunityId' => '006-reserved-in-july', 'StageName' => 'Reserva', 'CreatedDate' => '2026-07-15T10:00:00.000+0000'],
                        ['Id' => '0Jh-missing', 'OpportunityId' => '006-reserved-in-july', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000'],
                        ['Id' => '0Jh-no-res-before', 'OpportunityId' => '006-no-reservation', 'StageName' => 'Negociación', 'CreatedDate' => '2026-08-10T10:00:00.000+0000'],
                        ['Id' => '0Jh-no-res', 'OpportunityId' => '006-no-reservation', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-21T10:00:00.000+0000'],
                    ];
                }

                return [
                    ['Id' => '0Jh-missing', 'OpportunityId' => '006-reserved-in-july', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-20T10:00:00.000+0000'],
                    ['Id' => '0Jh-no-res', 'OpportunityId' => '006-no-reservation', 'StageName' => 'Cerrada Perdida', 'CreatedDate' => '2026-08-21T10:00:00.000+0000'],
                ];
            }
        };

        $result = (new SalesforceOpportunityHistorySyncService($client))->sync(
            CarbonImmutable::parse('2026-08-19', 'UTC'),
            CarbonImmutable::parse('2026-08-22', 'UTC'),
        );

        $this->assertSame(2, $result['saved']);
        $this->assertSame(1, $result['unresolved_dependencies']);
        $missing = SalesforceOpportunityStageTransition::query()->where('salesforce_history_id', '0Jh-missing')->firstOrFail();
        $notDemonstrated = SalesforceOpportunityStageTransition::query()->where('salesforce_history_id', '0Jh-no-res')->firstOrFail();
        $this->assertSame('opportunity_not_local', $missing->quality_status);
        $this->assertNull($missing->reservation_date);
        $this->assertSame('reservation_not_demonstrated', $notDemonstrated->quality_status);
        $this->assertNull($notDemonstrated->reservation_date);
        $this->assertDatabaseHas('salesforce_opportunity_history_sync_intervals', [
            'unresolved_dependencies' => 1,
            'is_kpi_certified' => false,
        ]);
    }

    public function test_el_sync_limita_intervalos_al_cutoff_real_y_no_certifica_un_rango_futuro(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Europe/Madrid'));
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };
        $service = new SalesforceOpportunityHistorySyncService($client);

        $result = $service->sync(
            CarbonImmutable::parse('2026-08-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'),
        );

        $this->assertSame('2026-08-26T08:00:00+00:00', $result['observation_cutoff_at']);
        $this->assertSame('2026-08-26T08:00:00+00:00', $result['effective_end_at']);
        $this->assertSame('2026-08-26 08:00:00', SalesforceOpportunityHistorySyncInterval::query()->max('range_end'));
        $this->assertStringContainsString('CreatedDate < 2026-08-26T08:00:00Z', end($client->queries));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];
        $this->assertSame('2026-08-26T08:00:00+00:00', $coverage['source_cutoff_at']);
        $this->assertSame('2026-08-26T08:00:00+00:00', $coverage['certified_until']);

        $queriesBeforeFutureRange = count($client->queries);
        $future = $service->sync(
            CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'),
        );
        $this->assertSame(0, $future['covered_intervals']);
        $this->assertSame($queriesBeforeFutureRange, count($client->queries));
    }
}

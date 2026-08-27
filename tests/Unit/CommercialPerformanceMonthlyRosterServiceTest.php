<?php

namespace Tests\Unit;

use App\Models\CommercialDelegationSnapshot;
use App\Models\SalesforceUser;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceMonthlyRosterService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialPerformanceMonthlyRosterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_y_observacion_son_evaluables_con_procedencia_distinta(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-roster', 'name' => 'Comercial', 'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE', 'is_active' => true,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-roster', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-03-31 22:00:00', 'observed_until' => '2026-07-31 22:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-roster', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $context = app(CommercialPerformanceMonthlyRosterService::class)->context(collect([
            CarbonImmutable::parse('2026-04-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-08-01', 'Europe/Madrid'),
        ]));

        $this->assertSame('bootstrap_approved', $context['assignments']['2026-04']['005-roster']['delegation_status']);
        $this->assertSame('observed', $context['assignments']['2026-08']['005-roster']['delegation_status']);
        $this->assertTrue($context['assignments']['2026-04']['005-roster']['ranking_eligible']);
    }

    public function test_cambio_dentro_del_mes_y_hueco_quedan_no_certificables(): void
    {
        foreach (['005-change', '005-gap'] as $id) {
            SalesforceUser::query()->create([
                'salesforce_id' => $id, 'name' => $id, 'profile_name' => 'Compra/Venta',
                'user_delegation' => 'HR MOTOR ALICANTE', 'is_active' => true,
            ]);
        }
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-05-31 22:00:00', 'observed_until' => '2026-06-15 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-06-15 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-gap', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-06-10 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $context = app(CommercialPerformanceMonthlyRosterService::class)->context(collect([
            CarbonImmutable::parse('2026-06-01', 'Europe/Madrid'),
        ]));

        $this->assertArrayNotHasKey('005-change', $context['assignments']['2026-06'] ?? []);
        $this->assertSame('organisation_change_within_month', $context['assessments']['2026-06']['005-change']['reason']);
        $this->assertSame('incomplete_history', $context['assessments']['2026-06']['005-gap']['reason']);
    }
}

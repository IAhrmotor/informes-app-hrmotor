<?php

namespace Tests\Unit;

use App\Models\CommercialDelegationSnapshot;
use App\Models\SalesforceUser;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
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
        $this->assertSame('Alicante', $rows[0]->delegation);
        $this->assertSame('2026-08-01 00:00:00', $rows[0]->observed_until->toDateTimeString());
        $this->assertSame('Murcia', $rows[1]->delegation);
        $this->assertSame('2026-08-20 00:00:00', $rows[1]->observed_until->toDateTimeString());
        $this->assertNull($rows[0]->open_marker);
        $this->assertNull($rows[1]->open_marker);
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
}

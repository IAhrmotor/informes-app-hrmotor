<?php

namespace Tests\Feature;

use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\AreaRestrictedCommissionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaRestrictedCommissionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtra_comerciales_y_delegaciones_por_zona_e_incluye_al_usuario_actual(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-NORTE',
            'name' => 'Comercial Norte',
            'email' => 'norte@hrmotor.com',
            'user_delegation' => 'HR MOTOR BILBAO',
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => '005-SUR',
            'name' => 'Comercial Sur',
            'email' => 'sur@hrmotor.com',
            'user_delegation' => 'HR MOTOR MALAGA',
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => '005-PROPIO',
            'name' => 'Manager sin delegacion',
            'email' => 'manager@hrmotor.com',
            'user_delegation' => null,
        ]);

        $payload = app(AreaRestrictedCommissionScope::class)->commercialDashboard([
            'warnings' => [],
            'diagnostics' => ['opportunities_total' => 99, 'reviews_count' => 99],
            'summary_rows' => [
                ['commercial_id' => '005-NORTE', 'operations_count' => 3, 'reviews_count' => 1],
                ['commercial_id' => '005-SUR', 'operations_count' => 7, 'reviews_count' => 2],
                ['commercial_id' => '005-PROPIO', 'operations_count' => 1, 'reviews_count' => 0],
            ],
            'delegation_rows' => [
                ['delegation_name' => 'Bilbao', 'deliveries_count' => 4, 'reviews_count' => 1],
                ['delegation_name' => 'Malaga', 'deliveries_count' => 8, 'reviews_count' => 2],
            ],
        ], 'Zona Norte', 'manager@hrmotor.com');

        $this->assertSame(['005-NORTE', '005-PROPIO'], collect($payload['summary_rows'])->pluck('commercial_id')->all());
        $this->assertSame(['Bilbao'], collect($payload['delegation_rows'])->pluck('delegation_name')->all());
        $this->assertSame(4, $payload['diagnostics']['opportunities_total']);
        $this->assertSame(1, $payload['diagnostics']['reviews_count']);
    }
}

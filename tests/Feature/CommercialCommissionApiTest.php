<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialCommissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_api_requiere_basic_auth(): void
    {
        $this->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertUnauthorized();
    }

    public function test_api_devuelve_comision_final_del_mes_en_curso_y_mes_anterior_cerrado(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-29 12:00:00'));
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        config()->set('services.commissions_api.user', 'api-user');
        config()->set('services.commissions_api.password', 'api-secret');

        SalesforceUser::create([
            'salesforce_id' => '005-API',
            'name' => 'Comercial API',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        foreach ([
            ['id' => 'API-MAY-1', 'date' => '2026-05-10'],
            ['id' => 'API-MAY-2', 'date' => '2026-05-12'],
            ['id' => 'API-JUN-1', 'date' => '2026-06-10'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-API',
                'owner_name' => 'Comercial API',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => $row['date'],
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 1000,
                'opo_div_descuento' => 100,
                'gestion_de_venta' => false,
                'vehicle_plate' => $row['id'],
                'vehicle_entry_date' => '2026-01-01',
            ]);
        }

        $auth = 'Basic '.base64_encode('api-user:api-secret');
        $service = app(CommercialCommissionDashboardService::class);
        $current = $service->finalCommissionForCommercial('005-API', '2026-06');
        $previous = $service->finalCommissionForCommercial('005-API', '2026-05');

        $this->withHeaders(['Authorization' => $auth])
            ->getJson('/api/comisiones_comercial?salesforce_id=005-API')
            ->assertOk()
            ->assertJson([
                'commercial_id' => '005-API',
                'current_month' => [
                    'month' => '2026-06',
                    'final_commission' => $current['final_commission'],
                ],
                'previous_closed_month' => [
                    'month' => '2026-05',
                    'final_commission' => $previous['final_commission'],
                ],
            ]);
    }
}

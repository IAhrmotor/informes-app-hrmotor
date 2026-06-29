<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallCenterCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_center_se_muestra_como_pestana_dentro_de_comisiones_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceUser::query()->create([
            'salesforce_id' => '005-CC-1',
            'name' => 'Comercial Demo',
            'is_active' => true,
            'profile_name' => 'Compra/Venta',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'CC-TAB-1',
            'name' => 'Venta demo',
            'created_date' => '2026-05-05 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_id' => '005-CC-1',
            'owner_name' => 'Comercial Demo',
            'owner_is_active' => true,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-05',
            'gestion_de_venta' => false,
            'vehicle_plate' => '1234ABC',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $adminSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => 'admin@hrmotor.com',
        ];
        $directorSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => 'director@hrmotor.com',
        ];
        $areaManagerSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_AREA_MANAGER,
            'report_user_email' => 'area@hrmotor.com',
        ];
        $viewerSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_VIEWER,
            'report_user_email' => 'viewer@hrmotor.com',
        ];

        $this->withSession($adminSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($directorSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($directorSession)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Detalle por comercial')
            ->assertSee('Delegaciones')
            ->assertSee('Call Center');

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($areaManagerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');

        $this->withSession($viewerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');
    }

    public function test_dashboard_calcula_bloques_de_call_center_y_marca_comisiones_vacias(): void
    {
        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-PURCHASE-1',
            'name' => 'Tasacion Jose Mari',
            'record_type_name' => 'Tasación',
            'owner_name' => 'Owner Uno',
            'account_name' => 'Cuenta Compra 1',
            'cv_signed_date' => '2026-05-05',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Jose Mari',
                'Comisi_n_Captador__c' => 5,
                'Fecha_captador__c' => '2026-05-01',
                'OPO_BUS_Vehiculo_a_tasar__r' => ['Name' => '1111AAA Opel Corsa'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-PURCHASE-2',
            'name' => 'Tasacion Coches.net',
            'record_type_name' => 'Tasación',
            'owner_name' => 'Owner Dos',
            'account_name' => 'Cuenta Compra 2',
            'cv_signed_date' => '2026-05-06',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Coches.net',
                'Comisi_n_Captador__c' => 5,
                'Captador_2__c' => 'German Olsen',
                'Fecha_captado_2__c' => '2026-05-02',
                'OPO_BUS_Vehiculo_a_tasar__r' => ['Name' => '2222BBB Peugeot 208'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-SALE-1',
            'name' => 'Venta Vanesa',
            'record_type_name' => 'Venta',
            'owner_name' => 'Owner Tres',
            'account_name' => 'Cuenta Venta 1',
            'cv_signed_date' => '2026-05-07',
            'gestion_de_venta' => false,
            'opportunity_source_raw' => 'WEB',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '3333CCC Mazda 3'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-CHANGE-1',
            'name' => 'Cambio Aimar',
            'record_type_name' => 'Cambio',
            'owner_name' => 'Owner Cuatro',
            'account_name' => 'Cuenta Cambio 1',
            'cv_signed_date' => '2026-05-08',
            'gestion_de_venta' => false,
            'opportunity_source_raw' => 'WEB',
            'raw_payload' => [
                'Captador__c' => 'Aimar',
                'Comisi_n_Captador__c' => 5,
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '4444DDD Seat Leon'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-FACILITEA-1',
            'name' => 'FACILITEA 123456 5555EEE',
            'record_type_name' => 'Venta',
            'owner_name' => 'Vanessa Sanjuan',
            'owner_delegation' => 'ONLINE',
            'account_name' => 'Cuenta Facilitea',
            'cv_signed_date' => '2026-05-09',
            'gestion_de_venta' => false,
            'stage_name' => 'Contrato',
            'opportunity_source_raw' => 'FACILITEA',
            'shared_delivery_name' => 'Costa Plamenov',
            'raw_payload' => [
                'Fuente_de_Origen__c' => 'FACILITEA',
                'OPO_FEC_Fecha_entrega__c' => '2026-05-12',
                'Delegacion_del_propietario__c' => 'CAPTADOR U ONLINE',
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '5555EEE Renault Clio'],
                'Facturado_Facilitea__c' => true,
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-MISSING-1',
            'name' => 'Tasacion sin comision',
            'record_type_name' => 'Tasación',
            'owner_name' => 'Owner Cinco',
            'account_name' => 'Cuenta Compra 3',
            'cv_signed_date' => '2026-05-10',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Miriam Gonzalez',
                'Comisi_n_Captador__c' => null,
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-EXCLUDED-1',
            'name' => 'Facilitea gestion venta',
            'record_type_name' => 'Venta',
            'owner_name' => 'Vanessa Sanjuan',
            'account_name' => 'Cuenta Excluida',
            'cv_signed_date' => '2026-05-11',
            'gestion_de_venta' => true,
            'stage_name' => 'Contrato',
            'opportunity_source_raw' => 'FACILITEA',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $payload = app(CallCenterCommissionDashboardService::class)->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertSame(7, $payload['diagnostics']['monthly_opportunities']);
        $this->assertSame(3, $payload['diagnostics']['purchases_count']);
        $this->assertSame(1, $payload['diagnostics']['sales_count']);
        $this->assertSame(1, $payload['diagnostics']['changes_count']);
        $this->assertSame(1, $payload['diagnostics']['german_negotiations_count']);
        $this->assertSame(1, $payload['diagnostics']['facilitea_count']);
        $this->assertSame(1, $payload['diagnostics']['missing_commission_count']);

        $rows = collect($payload['summary_rows']);

        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Jose Mari')['purchase_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Coches.net')['purchase_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Vanesa')['sales_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Aimar')['changes_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'German Olsen')['german_negotiation_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Vanessa Sanjuan')['facilitea_commission']);
        $this->assertEquals(0.0, $rows->firstWhere('agent_name', 'Miriam Gonzalez')['automatic_total']);
        $this->assertStringContainsString('Comisión Captador', collect($payload['warnings'])->implode(' | '));
        $this->assertStringContainsString('Captador 2, Captador 3 y Captador 4', collect($payload['warnings'])->implode(' | '));
    }

    private function createCallCenterOpportunity(array $attributes): void
    {
        SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => 'CC-DEFAULT',
            'name' => 'Opportunity',
            'created_date' => '2026-05-01 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_name' => 'Owner',
            'owner_delegation' => 'Online',
            'account_name' => 'Cuenta',
            'opportunity_source_raw' => 'WEB',
            'shared_delivery_name' => null,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-01',
            'gestion_de_venta' => false,
            'raw_payload' => [],
        ], $attributes));
    }
}

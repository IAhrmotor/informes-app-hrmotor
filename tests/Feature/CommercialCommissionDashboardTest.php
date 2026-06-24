<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceReview;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_tab_y_puede_entrar_a_comisiones_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $adminSession = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => 'admin@hrmotor.com',
        ];

        $this->withSession($adminSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/comisiones-comerciales', false);

        $this->withSession($adminSession)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones Comerciales')
            ->assertSee('Mes cerrado')
            ->assertSee('Campos candidatos de rentabilidad');
    }

    public function test_director_y_area_manager_no_ven_la_tab_ni_pueden_entrar(): void
    {
        config()->set('services.informes_auth.enabled', true);

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

        $this->withSession($directorSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-comerciales', false);

        $this->withSession($directorSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-comerciales', false);

        $this->withSession($areaManagerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');
    }

    public function test_dashboard_calcula_resumen_real_de_comisiones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'SALE-'.$index,
                'name' => 'Venta '.$index,
                'owner_id' => '005-A',
                'owner_name' => 'Comercial A',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-1'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 1000,
                'garantia_total' => 1000,
                'opo_div_descuento' => 100,
                'gestion_de_venta' => false,
                'vehicle_plate' => $index === 1 ? '1111AAA' : 'PLATE-'.$index,
                'vehicle_days_in_stock' => $index === 1 ? 160 : 40,
                'vehicle_entry_date' => '2025-10-01',
                'shared_delivery_id' => $index === 1 ? '005-B' : null,
                'shared_delivery_name' => $index === 1 ? 'Comercial B' : null,
            ]);
        }

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-MANAGED-1',
            'name' => 'Venta gestionada',
            'owner_id' => '005-A',
            'owner_name' => 'Comercial A',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-18',
            'opo_for_importe_total' => 10000,
            'importe_financiado' => 5000,
            'beneficio_financiacion_comercial' => 1000,
            'garantia_total' => 1000,
            'opo_div_descuento' => 100,
            'gestion_de_venta' => true,
            'vehicle_plate' => 'MANAGED-1',
            'vehicle_days_in_stock' => 20,
            'vehicle_entry_date' => '2025-12-01',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-1',
            'name' => 'Compra previa',
            'owner_id' => '005-A',
            'owner_name' => 'Comercial A',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '1111AAA',
            'informe_rentabilidad' => 1000,
        ]);

        foreach (range(1, 4) as $index) {
            SalesforceReview::create([
                'salesforce_id' => 'REV-'.$index,
                'created_date' => '2026-05-2'.$index.' 10:00:00',
                'owner_id' => '005-review-'.$index,
                'owner_name' => 'Review '.$index,
                'opportunity_salesforce_id' => 'SALE-'.$index,
                'opportunity_name' => 'Venta '.$index,
                'opportunity_owner_id' => '005-A',
                'opportunity_owner_name' => 'Comercial A',
                'opportunity_record_type_name' => 'Venta',
                'opportunity_cv_signed_date' => '2026-05-2'.$index,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertTrue($payload['diagnostics']['sale_management_filter_applied']);
        $this->assertCount(2, $payload['summary_rows']);

        $commercialA = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-A');
        $commercialB = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-B');

        $this->assertSame(7, $commercialA['deliveries_count']);
        $this->assertSame(7, $commercialA['operations_count']);
        $this->assertEquals(420.0, $commercialA['sales_amount']);
        $this->assertEquals(18.0, $commercialA['purchases_amount']);
        $this->assertEquals(35.0, $commercialA['discount_penalty_amount']);
        $this->assertEquals(10.0, $commercialA['stock_150_amount']);
        $this->assertEquals(413.0, $commercialA['prima_total']);
        $this->assertEquals(80.0, $commercialA['delivery_bracket_percent']);
        $this->assertEquals(330.4, $commercialA['prima_adjusted']);
        $this->assertEquals(57.14, $commercialA['reviews_percentage']);
        $this->assertEquals(50.0, $commercialA['financing_percentage']);
        $this->assertEquals(210.0, $commercialA['financing_product_amount']);
        $this->assertEquals(420.0, $commercialA['guarantee_product_amount']);
        $this->assertEquals(960.4, $commercialA['final_commission']);
        $this->assertCount(1, $commercialA['details']['purchases']);

        $this->assertSame(0, $commercialB['deliveries_count']);
        $this->assertEquals(30.0, $commercialB['shared_amount']);
        $this->assertEquals(0.0, $commercialB['prima_adjusted']);
        $this->assertEquals(0.0, $commercialB['final_commission']);
    }

    public function test_dashboard_aplica_penalizacion_de_resenas_excluyente_y_financiacion_incluye_tasaciones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DELIVERY-'.$index,
                'name' => 'Entrega '.$index,
                'owner_id' => '005-C',
                'owner_name' => 'Comercial C',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => $index === 7 ? 'Cambio' : 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-0'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'garantia_total' => 350,
                'gestion_de_venta' => false,
            ]);
        }

        foreach (range(1, 3) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'APPRAISAL-'.$index,
                'name' => 'Tasacion '.$index,
                'owner_id' => '005-C',
                'owner_name' => 'Comercial C',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Tasacion',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-1'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 0,
                'garantia_total' => 350,
                'gestion_de_venta' => false,
            ]);
        }

        foreach ([
            ['id' => 'REV-C-1', 'opportunity_id' => 'DELIVERY-1', 'opportunity_name' => 'Entrega 1', 'date' => '2026-06-20 10:00:00'],
            ['id' => 'REV-C-2', 'opportunity_id' => 'DELIVERY-1', 'opportunity_name' => 'Entrega 1', 'date' => '2026-06-20 11:00:00'],
            ['id' => 'REV-C-3', 'opportunity_id' => 'DELIVERY-2', 'opportunity_name' => 'Entrega 2', 'date' => '2026-06-21 10:00:00'],
            ['id' => 'REV-C-4', 'opportunity_id' => 'APPRAISAL-1', 'opportunity_name' => 'Tasacion 1', 'date' => '2026-06-22 10:00:00'],
        ] as $index => $review) {
            SalesforceReview::create([
                'salesforce_id' => $review['id'],
                'created_date' => $review['date'],
                'owner_id' => '005-review-c-'.$index,
                'owner_name' => 'Review C '.$index,
                'opportunity_salesforce_id' => $review['opportunity_id'],
                'opportunity_name' => $review['opportunity_name'],
                'opportunity_owner_id' => '005-C',
                'opportunity_owner_name' => 'Comercial C',
                'opportunity_record_type_name' => str_contains($review['opportunity_id'], 'APPRAISAL') ? 'Tasacion' : 'Venta',
                'opportunity_cv_signed_date' => '2026-06-20',
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $commercial = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-C');

        $this->assertSame(7, $commercial['deliveries_count']);
        $this->assertSame(10, $commercial['operations_count']);
        $this->assertEquals(420.0, $commercial['prima_total']);
        $this->assertEquals(336.0, $commercial['prima_adjusted']);
        $this->assertEquals(4, $commercial['reviews_count']);
        $this->assertEquals(40.0, $commercial['reviews_percentage']);
        $this->assertEquals(33.6, $commercial['reviews_penalty']);
        $this->assertEquals(35000.0, $commercial['financed_amount']);
        $this->assertEquals(100000.0, $commercial['total_vehicle_amount']);
        $this->assertEquals(35.0, $commercial['financing_percentage']);
        $this->assertEquals(33.6, $commercial['financing_penalty']);
        $this->assertEquals(67.2, $commercial['total_penalties']);
        $this->assertEquals(268.8, $commercial['prima_after_penalties']);
        $this->assertCount(4, $commercial['details']['reviews']);
    }

    public function test_dashboard_no_deja_prima_neta_negativa_ni_penalizaciones_negativas(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'NEG-'.$index,
                'name' => 'Operacion negativa '.$index,
                'owner_id' => '005-N',
                'owner_name' => 'Comercial N',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-07-0'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 2000,
                'opo_div_descuento' => 2000,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-07');
        $commercial = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-N');

        $this->assertLessThan(0, $commercial['prima_adjusted']);
        $this->assertEquals(0.0, $commercial['reviews_penalty']);
        $this->assertEquals(0.0, $commercial['financing_penalty']);
        $this->assertEquals(0.0, $commercial['guarantee_penalty']);
        $this->assertEquals(0.0, $commercial['prima_after_penalties']);
        $this->assertEquals(0.0, $commercial['final_commission']);
    }

    public function test_dashboard_asigna_la_compra_al_propietario_de_la_compra_y_no_al_vendedor_posterior(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-T1',
            'name' => 'Tasacion de entrada',
            'owner_id' => '005-T',
            'owner_name' => 'Tasador Uno',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '4444AAA',
            'informe_rentabilidad' => 1000,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-S1',
            'name' => 'Venta posterior',
            'owner_id' => '005-S',
            'owner_name' => 'Comercial Venta',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-05',
            'opo_for_importe_total' => 12000,
            'importe_financiado' => 6000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '4444AAA',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-08');

        $tasador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-T');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-S');

        $this->assertNotNull($tasador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $tasador['purchases_amount']);
        $this->assertCount(1, $tasador['details']['purchases']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
        $this->assertSame('Tasacion de entrada', $tasador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Venta posterior', $tasador['details']['purchases'][0]['sale_opportunity_name']);
    }

    public function test_dashboard_detecta_compras_con_tasacion_acentuada_y_match_por_vehicle_interest(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-ACCENT-1',
            'name' => 'Tasacion acentuada',
            'owner_id' => '005-T2',
            'owner_name' => 'Tasador Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasación',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'gestion_de_venta' => false,
            'vehicle_interest_id' => 'VEH-100',
            'vehicle_plate' => null,
            'informe_rentabilidad' => 1000,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-ACCENT-1',
            'name' => 'Venta con vehiculo enlazado',
            'owner_id' => '005-S2',
            'owner_name' => 'Comercial Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 15000,
            'importe_financiado' => 7000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => 'VEH-100',
            'vehicle_plate' => '9999BBB',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $tasador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-T2');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-S2');

        $this->assertNotNull($tasador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $tasador['purchases_amount']);
        $this->assertCount(1, $tasador['details']['purchases']);
        $this->assertSame('Tasacion acentuada', $tasador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Venta con vehiculo enlazado', $tasador['details']['purchases'][0]['sale_opportunity_name']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_no_pierde_compra_historica_si_el_owner_original_ya_no_esta_activo(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-INACTIVE-1',
            'name' => 'Compra historica inactiva',
            'owner_id' => '005-I1',
            'owner_name' => 'Comercial Inactivo',
            'owner_is_active' => false,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-10',
            'gestion_de_venta' => false,
            'vehicle_plate' => '7777CCC',
            'informe_rentabilidad' => 1000,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-INACTIVE-1',
            'name' => 'Venta de compra historica',
            'owner_id' => '005-V1',
            'owner_name' => 'Comercial Activo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-12',
            'opo_for_importe_total' => 16000,
            'importe_financiado' => 8000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '7777CCC',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-I1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-V1');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }
}

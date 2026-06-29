<?php

namespace Tests\Feature;

use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceReview;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommercialCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://app.hrmotor.com/api/internal/google-reviews/count*' => Http::response([
                'month' => '06-26',
                'location' => 'HR Motor || Test',
                'reviews_count' => 0,
                'average_rating' => null,
            ], 200),
        ]);
    }

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
            ->assertSee('Diagnostico de datos base');
    }

    public function test_director_ve_tab_y_puede_entrar_a_comisiones_comerciales_sin_ser_admin(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $session = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => 'director@hrmotor.com',
        ];

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/comisiones-comerciales', false);

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones Comerciales')
            ->assertDontSee('Diagnostico de datos base');
    }

    public function test_area_manager_y_viewer_no_autorizados_no_ven_la_tab_ni_pueden_entrar(): void
    {
        config()->set('services.informes_auth.enabled', true);

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

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-comerciales', false);

        $this->withSession($areaManagerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');

        $this->withSession($viewerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-comerciales', false);

        $this->withSession($viewerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');
    }

    public function test_dashboard_calcula_resumen_real_de_comisiones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceUser::create([
            'salesforce_id' => '005-A',
            'name' => 'Comercial A',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-B',
            'name' => 'Comercial B',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-Z',
            'name' => 'Tasador Sin Actividad',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

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
                'vehicle_days_in_stock' => $index === 1 ? 10 : 160,
                'vehicle_entry_date' => $index === 1 ? '2025-10-01' : '2026-03-01',
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
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-02',
            'vehicle_buyer_id' => '005-A',
            'vehicle_buyer_name' => 'Comercial A',
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
        $this->assertCount(1, $payload['summary_rows']);

        $commercialA = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-A');
        $commercialB = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-B');
        $commercialZ = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-Z');

        $this->assertSame(7, $commercialA['deliveries_count']);
        $this->assertSame(7, $commercialA['operations_count']);
        $this->assertEquals(390.0, $commercialA['sales_amount']);
        $this->assertEquals(52.2, $commercialA['purchases_amount']);
        $this->assertEquals(35.0, $commercialA['discount_penalty_amount']);
        $this->assertEquals(10.0, $commercialA['stock_150_amount']);
        $this->assertEquals(417.2, $commercialA['prima_total']);
        $this->assertEquals(100.0, $commercialA['delivery_bracket_percent']);
        $this->assertEquals(417.2, $commercialA['prima_adjusted']);
        $this->assertEquals(57.14, $commercialA['reviews_percentage']);
        $this->assertEquals(50.0, $commercialA['financing_percentage']);
        $this->assertEquals(210.0, $commercialA['financing_product_amount']);
        $this->assertEquals(420.0, $commercialA['guarantee_product_amount']);
        $this->assertEquals(1047.2, $commercialA['final_commission']);
        $this->assertCount(1, $commercialA['details']['purchases']);

        $this->assertNull($commercialB);
        $this->assertNull($commercialZ);
    }

    public function test_dashboard_aplica_penalizacion_de_resenas_excluyente_y_financiacion_incluye_tasaciones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-C', 'Comercial C');

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
        $this->assertEquals(420.0, $commercial['prima_adjusted']);
        $this->assertEquals(4, $commercial['reviews_count']);
        $this->assertEquals(40.0, $commercial['reviews_percentage']);
        $this->assertEquals(42.0, $commercial['reviews_penalty']);
        $this->assertEquals(35000.0, $commercial['financed_amount']);
        $this->assertEquals(100000.0, $commercial['total_vehicle_amount']);
        $this->assertEquals(35.0, $commercial['financing_percentage']);
        $this->assertEquals(42.0, $commercial['financing_penalty']);
        $this->assertEquals(84.0, $commercial['total_penalties']);
        $this->assertEquals(336.0, $commercial['prima_after_penalties']);
        $this->assertCount(4, $commercial['details']['reviews']);
    }

    public function test_dashboard_aplica_tramo_por_perfil_y_partner_community_mantiene_tramo_normal(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-JT', 'Jefe Tienda', true, 'Compra/Venta');
        $this->createCommercialUser('005-PC', 'Partner Community', true, 'Comerciales Partner Community');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'JT-SALE-'.$index,
                'name' => 'Venta JT '.$index,
                'owner_id' => '005-JT',
                'owner_name' => 'Jefe Tienda',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-08-0'.$index,
                'opo_for_importe_total' => 10000,
                'gestion_de_venta' => false,
            ]);

            SalesforceOpportunity::create([
                'salesforce_id' => 'PC-SALE-'.$index,
                'name' => 'Venta PC '.$index,
                'owner_id' => '005-PC',
                'owner_name' => 'Partner Community',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-08-0'.$index,
                'opo_for_importe_total' => 10000,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-08');

        $jefeTienda = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-JT');
        $partnerCommunity = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-PC');

        $this->assertNotNull($jefeTienda);
        $this->assertNotNull($partnerCommunity);
        $this->assertEquals(420.0, $jefeTienda['prima_total']);
        $this->assertEquals(100.0, $jefeTienda['delivery_bracket_percent']);
        $this->assertEquals(420.0, $jefeTienda['prima_adjusted']);
        $this->assertEquals(420.0, $partnerCommunity['prima_total']);
        $this->assertEquals(80.0, $partnerCommunity['delivery_bracket_percent']);
        $this->assertEquals(336.0, $partnerCommunity['prima_adjusted']);
    }

    public function test_dashboard_no_deja_prima_neta_negativa_ni_penalizaciones_negativas(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-N', 'Comercial N');

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
        $this->createCommercialUser('005-T', 'Tasador Uno');
        $this->createCommercialUser('005-S', 'Comercial Venta');

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
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-15',
            'vehicle_buyer_id' => '005-T',
            'vehicle_buyer_name' => 'Tasador Uno',
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
        $this->assertSame('Tasador Uno', $tasador['details']['purchases'][0]['purchase_owner_name']);
        $this->assertSame('Tasacion de entrada', $tasador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Venta posterior', $tasador['details']['purchases'][0]['sale_opportunity_name']);
    }

    public function test_dashboard_detecta_compras_con_tasacion_acentuada_y_match_por_vehicle_interest(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-T2', 'Tasador Dos');
        $this->createCommercialUser('005-S2', 'Comercial Dos');

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
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-15',
            'vehicle_buyer_id' => '005-T2',
            'vehicle_buyer_name' => 'Tasador Dos',
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
        $this->createCommercialUser('005-I1', 'Comercial Inactivo', false);
        $this->createCommercialUser('005-V1', 'Comercial Activo');

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
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Cambio',
            'vehicle_purchase_date' => '2026-05-10',
            'vehicle_buyer_id' => '005-I1',
            'vehicle_buyer_name' => 'Comercial Inactivo',
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

    public function test_dashboard_normaliza_matriculas_con_espacios_para_liquidar_compras(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-P1', 'Josue Fernandez');
        $this->createCommercialUser('005-P2', 'Leonardo Gonzalez');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-PLATE-1',
            'name' => 'Compra con espacio en matricula',
            'owner_id' => '005-P1',
            'owner_name' => 'Josue Fernandez',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-10',
            'gestion_de_venta' => false,
            'vehicle_plate' => '9978 MBZ',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-10',
            'vehicle_buyer_id' => '005-P1',
            'vehicle_buyer_name' => 'Josue Fernandez',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-PLATE-1',
            'name' => 'Venta posterior sin espacio',
            'owner_id' => '005-P2',
            'owner_name' => 'Leonardo Gonzalez',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 15000,
            'importe_financiado' => 7000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '9978MBZ',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-P1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-P2');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertSame('Josue Fernandez', $comprador['commercial_name']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_liquida_compra_desde_product2_aunque_no_exista_oportunidad_historica_local(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SD1', 'Vendedor Directo');
        $this->createCommercialUser('005-BD1', 'Comprador Product2');

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-1',
            'name' => 'Venta con comprador Product2',
            'owner_id' => '005-SD1',
            'owner_name' => 'Vendedor Directo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-1',
            'vehicle_plate' => '1234ABC',
            'vehicle_buyer_id' => '005-BD1',
            'vehicle_buyer_name' => 'Comprador Product2',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-20',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-BD1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-SD1');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(106.2, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertSame('Sin oportunidad historica local', $comprador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Product2', $comprador['details']['purchases'][0]['purchase_record_type_name']);
        $this->assertSame('product2_sale_vehicle', $comprador['details']['purchases'][0]['source']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_excluye_compras_con_fecha_compra_product2_igual_o_anterior_al_primero_de_mayo_de_2026(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-OLD-BUY', 'Comprador Antiguo');
        $this->createCommercialUser('005-OLD-SELL', 'Vendedor Antiguo');
        $this->createCommercialUser('005-OLD-DIRECT', 'Comprador Directo Antiguo');
        $this->createCommercialUser('005-OLD-DIRECT-SELL', 'Vendedor Directo Antiguo');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-OLD-1',
            'name' => 'Compra historica antigua',
            'owner_id' => '005-OLD-BUY',
            'owner_name' => 'Comprador Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '5555OLD',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-01',
            'vehicle_buyer_id' => '005-OLD-BUY',
            'vehicle_buyer_name' => 'Comprador Antiguo',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-OLD-1',
            'name' => 'Venta posterior antigua',
            'owner_id' => '005-OLD-SELL',
            'owner_name' => 'Vendedor Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 14000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '5555OLD',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-OLD-1',
            'name' => 'Venta Product2 antigua',
            'owner_id' => '005-OLD-DIRECT-SELL',
            'owner_name' => 'Vendedor Directo Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-12',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-OLD-1',
            'vehicle_plate' => '8888OLD',
            'vehicle_buyer_id' => '005-OLD-DIRECT',
            'vehicle_buyer_name' => 'Comprador Directo Antiguo',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-04-30',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $historicalBuyer = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-BUY');
        $directBuyer = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-DIRECT');
        $historicalSeller = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-SELL');
        $directSeller = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-DIRECT-SELL');

        $this->assertNull($historicalBuyer);
        $this->assertNull($directBuyer);
        $this->assertNotNull($historicalSeller);
        $this->assertNotNull($directSeller);
        $this->assertEquals(0.0, $historicalSeller['purchases_amount']);
        $this->assertEquals(0.0, $directSeller['purchases_amount']);
    }

    public function test_dashboard_excluye_compras_con_procedencia_fuera_de_cambio_y_compra_directa(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SD2', 'Vendedor Dos');
        $this->createCommercialUser('005-BD2', 'Comprador Excluido');

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-2',
            'name' => 'Venta con procedencia excluida',
            'owner_id' => '005-SD2',
            'owner_name' => 'Vendedor Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-11',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-2',
            'vehicle_plate' => '4321CBA',
            'vehicle_buyer_id' => '005-BD2',
            'vehicle_buyer_name' => 'Comprador Excluido',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Subasta',
            'vehicle_purchase_date' => '2026-05-20',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-BD2');

        $this->assertNull($comprador);
    }

    public function test_dashboard_excluye_usuarios_sin_perfiles_comerciales_permitidos(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceUser::create([
            'salesforce_id' => '005-OK',
            'name' => 'Comercial Permitido',
            'profile_name' => 'Comerciales Partner Community',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-NO',
            'name' => 'Usuario No Comercial',
            'profile_name' => 'System Administrator',
            'is_active' => true,
        ]);

        foreach ([
            ['id' => 'SALE-OK-1', 'owner_id' => '005-OK', 'owner_name' => 'Comercial Permitido', 'plate' => 'AAA111'],
            ['id' => 'SALE-NO-1', 'owner_id' => '005-NO', 'owner_name' => 'Usuario No Comercial', 'plate' => 'BBB222'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => $row['owner_id'],
                'owner_name' => $row['owner_name'],
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 3000,
                'gestion_de_venta' => false,
                'vehicle_plate' => $row['plate'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $this->assertNotNull(collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OK'));
        $this->assertNull(collect($payload['summary_rows'])->firstWhere('commercial_id', '005-NO'));
    }

    public function test_dashboard_calcula_cuadro_delegaciones_con_meta_y_bonus_de_calidad(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'hr-motor-alicante' => [
                        'label' => 'HR MOTOR ALICANTE',
                        'target_deliveries' => 35,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-D1', 'Delegacion Uno');

        foreach (range(1, 30) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-ALICANTE-'.$index,
                'name' => 'Venta Alicante '.$index,
                'owner_id' => '005-D1',
                'owner_name' => 'Delegacion Uno',
                'owner_is_active' => true,
                'owner_delegation' => 'HR MOTOR BARCELONA',
                'delivery_store' => 'HR MOTOR ALICANTE',
                'stage_name' => 'Contrato',
                'record_type_name' => $index === 30 ? 'Cambio' : 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-'.str_pad((string) min($index, 28), 2, '0', STR_PAD_LEFT),
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'opo_div_descuento' => 0,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 800,
                'garantia_total' => 200,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Alicante');

        $this->assertNotNull($delegation);
        $this->assertSame(35, $delegation['target_deliveries']);
        $this->assertSame(30, $delegation['deliveries_count']);
        $this->assertEquals(85.71, $delegation['objective_percentage']);
        $this->assertEquals(0.35, $delegation['objective_commission_percent']);
        $this->assertEquals(90000.0, $delegation['rentability_total']);
        $this->assertEquals(3000.0, $delegation['average_rentability']);
        $this->assertEquals(3010.5, $delegation['prima_final']);
        $this->assertSame(0, $delegation['reviews_count']);
        $this->assertNull($delegation['reviews_average_rating']);
        $this->assertEquals(0.0, $delegation['reviews_commission_amount']);
        $this->assertEquals(16.0, $delegation['financing_profitability_percentage']);
        $this->assertEquals(50.0, $delegation['financed_amount_percentage']);
        $this->assertEquals(301.05, $delegation['financed_amount_bonus_amount']);
        $this->assertEquals(331.16, $delegation['profitability_bonus_amount']);
        $this->assertEquals(3642.71, $delegation['total_commission']);
    }

    public function test_dashboard_excluye_delegaciones_general_y_call_fontellas(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-D2', 'Delegacion Dos');

        foreach ([
            ['id' => 'DEL-GENERAL-1', 'delegation' => 'General'],
            ['id' => 'DEL-CALL-1', 'delegation' => 'Call Fontellas'],
            ['id' => 'DEL-VALID-1', 'delegation' => 'HR MOTOR ALICANTE'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D2',
                'owner_name' => 'Delegacion Dos',
                'owner_is_active' => true,
                'owner_delegation' => $row['delegation'],
                'delivery_store' => $row['delegation'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegationNames = collect($payload['delegation_rows'])->pluck('delegation_name');

        $this->assertTrue($delegationNames->contains('Alicante'));
        $this->assertFalse($delegationNames->contains('General'));
        $this->assertFalse($delegationNames->contains('Call Fontellas'));
        $this->assertFalse($delegationNames->contains('HR MOTOR ALICANTE'));
    }

    public function test_dashboard_cuadro_delegaciones_audita_entregas_sin_filtrar_por_owner_activo_ni_gestion_de_venta(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-D3', 'Delegacion Tres');

        foreach ([
            ['id' => 'DEL-BILBAO-1', 'delivery_store' => 'Bilbao', 'owner_is_active' => true, 'gestion_de_venta' => false],
            ['id' => 'DEL-BILBAO-2', 'delivery_store' => 'HR MOTOR BILBAO', 'owner_is_active' => false, 'gestion_de_venta' => false],
            ['id' => 'DEL-BILBAO-3', 'delivery_store' => 'Bilbao', 'owner_is_active' => true, 'gestion_de_venta' => true],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D3',
                'owner_name' => 'Delegacion Tres',
                'owner_is_active' => $row['owner_is_active'],
                'owner_delegation' => 'HR MOTOR BILBAO',
                'delivery_store' => $row['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => $row['gestion_de_venta'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Bilbao');

        $this->assertNotNull($delegation);
        $this->assertSame(3, $delegation['deliveries_count']);
        $this->assertNull(collect($payload['delegation_rows'])->firstWhere('delegation_name', 'HR MOTOR BILBAO'));
    }

    public function test_dashboard_normaliza_alias_delegaciones_y_hace_match_con_meta_para_evitar_prima_final_en_cero(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'mallorca' => [
                        'label' => 'Mallorca',
                        'target_deliveries' => 1,
                    ],
                    'san-boi' => [
                        'label' => 'San Boi',
                        'target_deliveries' => 1,
                    ],
                    'villarreal-almassora' => [
                        'label' => 'Villarreal/Almassora',
                        'target_deliveries' => 1,
                    ],
                    'malga' => [
                        'label' => 'Malga',
                        'target_deliveries' => 1,
                    ],
                    'llica-de-vall' => [
                        'label' => 'Lliçà De Vall',
                        'target_deliveries' => 99,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-D4', 'Delegacion Cuatro');

        foreach ([
            ['id' => 'DEL-PALMA-1', 'delivery_store' => 'Palma'],
            ['id' => 'DEL-SANT-BOI-1', 'delivery_store' => 'Sant_Boi'],
            ['id' => 'DEL-VILLAREAL-1', 'delivery_store' => 'Villareal/Almasora'],
            ['id' => 'DEL-MALAGA-1', 'delivery_store' => 'Malaga'],
            ['id' => 'DEL-LLICA-1', 'delivery_store' => 'Lliçà De Vall'],
            ['id' => 'DEL-ALCALA-1', 'delivery_store' => 'Alcalá de Guadaira'],
            ['id' => 'DEL-ALCALA-2', 'delivery_store' => 'Alcala De Guadaira'],
            ['id' => 'DEL-CASTELLON-1', 'delivery_store' => 'Castellón'],
            ['id' => 'DEL-CASTELLON-2', 'delivery_store' => 'Castellon'],
            ['id' => 'DEL-DH-1', 'delivery_store' => 'Dos Hermanas'],
            ['id' => 'DEL-DH-2', 'delivery_store' => 'Dos hermanas'],
            ['id' => 'DEL-TORREJON-1', 'delivery_store' => 'Torrejón'],
            ['id' => 'DEL-TORREJON-2', 'delivery_store' => 'Torrejon'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D4',
                'owner_name' => 'Delegacion Cuatro',
                'owner_is_active' => true,
                'owner_delegation' => $row['delivery_store'],
                'delivery_store' => $row['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $rows = collect($payload['delegation_rows']);

        $this->assertSame(1, $rows->where('delegation_name', 'Palma')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Sant Boi')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Villareal')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Malaga')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Alcalá de Guadaira')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Castellón')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Dos Hermanas')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Torrejón')->count());
        $this->assertFalse($rows->pluck('delegation_name')->contains('Mallorca'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('San Boi'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Sant_Boi'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Villareal/Almasora'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Malga'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Lliçà De Vall'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Alcala De Guadaira'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Castellon'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Dos hermanas'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Torrejon'));

        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Palma')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Sant Boi')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Villareal')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Malaga')['target_deliveries']);
        $this->assertGreaterThan(0, $rows->firstWhere('delegation_name', 'Palma')['prima_final']);
    }

    public function test_dashboard_aplica_comision_de_resenas_solo_si_cumple_objetivo_y_supera_el_50_por_ciento(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app()->instance(
            CommercialCommissionDelegationReviewsService::class,
            new class(app(CommercialCommissionFormulaConfigService::class)) extends CommercialCommissionDelegationReviewsService {
                public function forMonthAndDelegations(\Carbon\CarbonImmutable $month, \Illuminate\Support\Collection $delegationLabels): array
                {
                    return [
                        'Zaragoza' => ['reviews_count' => 23, 'average_rating' => 4.6],
                        'Bilbao' => ['reviews_count' => 10, 'average_rating' => 3.6],
                    ];
                }
            }
        );

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'zaragoza' => [
                        'label' => 'Zaragoza',
                        'target_deliveries' => 20,
                    ],
                    'bilbao' => [
                        'label' => 'Bilbao',
                        'target_deliveries' => 20,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-R1', 'Delegacion Reviews');

        foreach (range(1, 27) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-ZARAGOZA-'.$index,
                'name' => 'Venta Zaragoza '.$index,
                'owner_id' => '005-R1',
                'owner_name' => 'Delegacion Reviews',
                'owner_is_active' => true,
                'owner_delegation' => 'Zaragoza',
                'delivery_store' => 'Zaragoza',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        foreach (range(1, 20) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-BILBAO-R-'.$index,
                'name' => 'Venta Bilbao '.$index,
                'owner_id' => '005-R1',
                'owner_name' => 'Delegacion Reviews',
                'owner_is_active' => true,
                'owner_delegation' => 'Bilbao',
                'delivery_store' => 'Bilbao',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $zaragoza = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Zaragoza');
        $bilbao = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Bilbao');

        $this->assertNotNull($zaragoza);
        $this->assertSame(23, $zaragoza['reviews_count']);
        $this->assertEquals(4.6, $zaragoza['reviews_average_rating']);
        $this->assertEquals(200.0, $zaragoza['reviews_commission_amount']);
        $this->assertEquals(2815.6, $zaragoza['prima_final']);

        $this->assertNotNull($bilbao);
        $this->assertSame(10, $bilbao['reviews_count']);
        $this->assertEquals(3.6, $bilbao['reviews_average_rating']);
        $this->assertEquals(0.0, $bilbao['reviews_commission_amount']);
    }

    private function createCommercialUser(string $id, string $name, bool $isActive = true, string $profile = 'Compra/Venta'): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => $profile,
            'is_active' => $isActive,
        ]);
    }
}

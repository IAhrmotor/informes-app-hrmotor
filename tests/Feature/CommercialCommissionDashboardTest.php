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
        config()->set('commercial_commissions.sale_management_field', null);

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
                'vehicle_plate' => $index === 1 ? '1111AAA' : 'PLATE-'.$index,
                'vehicle_days_in_stock' => $index === 1 ? 160 : 40,
                'vehicle_entry_date' => '2025-10-01',
                'shared_delivery_id' => $index === 1 ? '005-B' : null,
                'shared_delivery_name' => $index === 1 ? 'Comercial B' : null,
            ]);
        }

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
}

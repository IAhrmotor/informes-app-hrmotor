<?php

namespace Tests\Feature;

use App\Models\SalesforceActivity;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialReportBuilder;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceLeadActivitySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCommercialReportBuilderDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_genera_payload_con_datos_sin_salir_a_cero(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00', 'UTC');

        SalesforceUser::create([
            'salesforce_id' => '005-owner',
            'name' => 'Comercial Demo',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceLead::create([
            'salesforce_id' => '00Q1',
            'name' => 'Lead Convertido',
            'created_date' => $now->subDays(3),
            'status' => 'Convertido',
            'owner_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'persona_que_trabajo_id' => '005-owner',
            'persona_que_trabajo_name' => 'Comercial Demo',
            'fecha_asignacion' => $now->subDays(3)->addMinutes(5),
            'portal_text' => 'Web',
        ]);

        SalesforceLead::create([
            'salesforce_id' => '00Q2',
            'name' => 'Lead Descartado',
            'created_date' => $now->subDays(2),
            'status' => 'Descartado',
            'owner_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'propietario_descarte_id' => '005-owner',
            'propietario_descarte_name' => 'Comercial Demo',
            'portal_text' => 'Meta',
        ]);

        SalesforceLead::create([
            'salesforce_id' => '00Q3',
            'name' => 'Lead Potencial',
            'created_date' => $now->subDay(),
            'status' => 'Potencial',
            'owner_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'portal_text' => 'Google Maps',
        ]);

        SalesforceActivity::create([
            'salesforce_id' => '00T1',
            'lead_salesforce_id' => '00Q1',
            'activity_kind' => 'Task',
            'owner_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'created_by_id' => '005-owner',
            'created_by_name' => 'Comercial Demo',
            'created_date' => $now->subDays(3)->addMinutes(30),
            'activity_date' => $now->subDays(3)->toDateString(),
            'subject' => 'Primera llamada',
        ]);

        app(SalesforceLeadActivitySummaryService::class)->recalculate(['00Q1', '00Q2', '00Q3']);

        $payload = app(MonthlyCommercialReportBuilder::class)->build(30, $now);

        $this->assertSame(3, $payload['resumen_global']['leads_totales']);
        $this->assertSame(1, $payload['resumen_global']['leads_convertidos']);
        $this->assertSame(1, $payload['resumen_global']['leads_descartados']);
        $this->assertSame(1, $payload['resumen_global']['leads_potenciales']);
        $this->assertSame(1, $payload['resumen_global']['potenciales_sin_seguimiento_mayor_3_dias']);
    }
}

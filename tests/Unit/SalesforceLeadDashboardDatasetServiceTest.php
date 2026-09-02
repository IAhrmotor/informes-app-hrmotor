<?php

namespace Tests\Unit;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceLeadDashboardDatasetServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesforceLeadDashboardDatasetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesforceLeadDashboardDatasetService::class);
    }

    public function test_resuelve_canal_llamada_si_medio_nuevo_es_llamada(): void
    {
        $lead = $this->service->decorateLead(['status' => 'Potencial', 'medio_nuevo' => 'Llamada']);

        $this->assertSame('Llamada', $lead['canal']);
        $this->assertTrue($lead['is_llamada']);
    }

    public function test_resuelve_canal_formulario_si_medio_nuevo_no_es_llamada(): void
    {
        $lead = $this->service->decorateLead(['status' => 'Potencial', 'medio_nuevo' => 'Email']);

        $this->assertSame('Formulario', $lead['canal']);
        $this->assertTrue($lead['is_formulario']);
    }

    public function test_raw_new_classification_overrides_legacy_materialization_and_keeps_other_channels_out_of_formulario(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'source_origin_new' => 'Meta',
            'channel_new' => 'Whatsapp',
            'medium_origin_new' => 'Paid Social',
            'resolved_channel' => 'Formulario',
            'resolved_portal' => 'Coches.net',
            'portal_resolution_source' => 'Portal_Text__c',
        ]);

        $this->assertSame('Meta', $lead['portal']);
        $this->assertSame('Fuente_origen__c', $lead['portal_resolution_source']);
        $this->assertSame('Whatsapp', $lead['canal']);
        $this->assertFalse($lead['is_llamada']);
        $this->assertFalse($lead['is_formulario']);
        $this->assertSame('Paid Social', $lead['medio_efectivo']);
    }

    public function test_portal_de_llamada_usa_fuente_nuevo(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'portal_text' => 'Web',
        ]);

        $this->assertSame('Google Maps', $lead['portal']);
        $this->assertSame('Fuente_Nuevo__c', $lead['portal_resolution_source']);
    }

    public function test_portal_de_formulario_usa_portal_text_y_fallback_fuente_origen(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'fuente_origen' => 'Meta',
        ]);

        $fallback = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Formulario',
            'fuente_origen' => 'Meta',
        ]);

        $this->assertSame('Web', $lead['portal']);
        $this->assertSame('Portal_Text__c', $lead['portal_resolution_source']);
        $this->assertSame('Meta', $fallback['portal']);
        $this->assertSame('LEA_SEL_Fuente_Origen__c', $fallback['portal_resolution_source']);
    }

    public function test_coche_nuevo_de_llamada_procede_de_fuente_nuevo_aunque_portal_text_sea_coches_net(): void
    {
        $lead = $this->service->decorateLead([
            'status' => 'Potencial',
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Coches.net Coche Nuevo',
            'portal_text' => 'Coches.net',
            'fuente_origen' => 'Coches.net Coche Nuevo',
        ]);

        $this->assertSame('Coches.net Coche Nuevo', $lead['portal']);
        $this->assertSame('Fuente_Nuevo__c', $lead['portal_resolution_source']);
    }

    public function test_auditoria_por_id_incluye_campos_brutos_resueltos_y_ausencias_locales(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-AUDIT',
            'name' => 'Auditado',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => ' Tasación ',
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'portal_text' => 'Coches.net',
            'fuente_origen' => 'Coches.net',
            'is_deleted' => false,
        ]);

        $items = $this->service->leadAudit(['00Q-AUDIT', '00Q-MISSING'])['items'];

        $this->assertSame('tasacion', $items[0]['record_type_normalized']);
        $this->assertSame('Google Maps', $items[0]['portal_resolved']);
        $this->assertSame('Fuente_Nuevo__c', $items[0]['portal_resolution_source']);
        $this->assertSame('Coches.net', $items[0]['portal_text_raw']);
        $this->assertSame('active_at_last_sync', $items[0]['salesforce_state']);
        $this->assertFalse($items[1]['exists_local']);
        $this->assertSame('not_synchronized', $items[1]['salesforce_state']);
    }

    public function test_auditoria_explica_el_fallback_contextual_de_delegacion_en_exposicion(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-AUDIT-EXPO',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Exposición',
            'owner_id' => '005-audit-expo',
            'is_deleted' => false,
        ]);
        SalesforceUser::create([
            'salesforce_id' => '005-audit-expo',
            'name' => 'Usuario sintético',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR VALENCIA',
            'is_active' => true,
        ]);

        $item = $this->service->leadAudit(['00Q-AUDIT-EXPO'])['items'][0];

        $this->assertNull(data_get($item, 'delegation_resolution.effective_value'));
        $this->assertSame('Valencia', $item['delegation_effective_normalized']);
        $this->assertSame('salesforce_users.user_delegation', $item['delegation_effective_source']);
    }

    public function test_potencial_sin_trabajar_si_no_tiene_actividad_o_si_ultima_es_mayor_3_dias(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00');

        $withoutActivity = $this->service->decorateLead(['status' => 'Potencial'], ['total_actividades' => 0], $now);
        $oldActivity = $this->service->decorateLead(['status' => 'Potencial'], [
            'total_actividades' => 1,
            'fecha_ultima_actividad' => '2026-05-01 12:00:00',
        ], $now);

        $this->assertTrue($withoutActivity['is_potencial_sin_trabajar']);
        $this->assertTrue($oldActivity['is_potencial_sin_trabajar']);
    }

    public function test_convertido_y_descartado_no_cuentan_como_sin_trabajar_sin_actividad(): void
    {
        $converted = $this->service->decorateLead(['status' => 'Convertido'], ['total_actividades' => 0]);
        $discarded = $this->service->decorateLead(['status' => 'Descartado'], ['total_actividades' => 0]);

        $this->assertFalse($converted['is_potencial_sin_trabajar']);
        $this->assertFalse($discarded['is_potencial_sin_trabajar']);
    }

    public function test_gestionado_si_convertido_descartado_o_potencial_con_actividad_reciente(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00');

        $converted = $this->service->decorateLead(['status' => 'Convertido'], [], $now);
        $discarded = $this->service->decorateLead(['status' => 'Descartado'], [], $now);
        $potential = $this->service->decorateLead(['status' => 'Potencial'], [
            'total_actividades' => 1,
            'fecha_ultima_actividad' => '2026-05-12 12:00:00',
        ], $now);

        $this->assertTrue($converted['is_gestionado']);
        $this->assertTrue($discarded['is_gestionado']);
        $this->assertTrue($potential['is_gestionado']);
    }
}

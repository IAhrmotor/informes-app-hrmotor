<?php

namespace Tests\Unit;

use App\Models\MasterPortal;
use App\Services\Reports\Leads\LeadNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadNormalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadNormalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LeadNormalizationService::class);

        MasterPortal::create([
            'portal_original' => 'Coches.net',
            'portal_group' => 'Coches.net',
            'is_active' => true,
        ]);

        MasterPortal::create([
            'portal_original' => 'Web',
            'portal_group' => 'Web',
            'is_active' => true,
        ]);

        MasterPortal::create([
            'portal_original' => 'Exposición',
            'portal_group' => 'Exposición',
            'is_active' => true,
        ]);
    }

    public function test_detecta_llamada_usando_medio_nuevo(): void
    {
        $lead = [
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Coches.net',
            'lea_sel_medio_origen' => 'Anuncio',
            'delegacion_encargada_text' => 'Madrid',
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('Llamada', $result['channel_direction']);
        $this->assertSame('Coches.net', $result['portal_original']);
        $this->assertSame('Coches.net', $result['portal_group']);
    }

    public function test_todo_lo_que_no_es_llamada_es_formulario(): void
    {
        $lead = [
            'medio_nuevo' => 'Formulario web',
            'portal' => 'Web',
            'remitente_lead' => 'leads@hrmotor.com',
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('Formulario', $result['channel_direction']);
        $this->assertSame('Web', $result['portal_original']);
    }

    public function test_formulario_usa_prioridad_de_portal(): void
    {
        $lead = [
            'medio_nuevo' => null,
            'portal' => null,
            'lea_sel_fuente_origen' => 'Web',
            'fuente_nuevo' => 'Coches.net',
            'remitente_lead' => 'leadssantboi@hrmotor.com',
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('Formulario', $result['channel_direction']);
        $this->assertSame('Web', $result['portal_original']);
    }

    public function test_exposicion_se_identifica_por_portal(): void
    {
        $lead = [
            'medio_nuevo' => null,
            'portal' => 'Exposición',
            'remitente_lead' => 'expo@hrmotor.com',
            'owner_name' => 'Comercial Prueba',
        ];

        $result = $this->service->normalize($lead);

        $this->assertTrue($result['is_exposition']);
        $this->assertSame('Exposición', $result['portal_group']);
    }

    public function test_convertido_se_identifica_por_status(): void
    {
        $lead = [
            'status' => 'Convertido',
            'medio_nuevo' => null,
            'portal' => 'Web',
            'remitente_lead' => 'leads@hrmotor.com',
        ];

        $result = $this->service->normalize($lead);

        $this->assertTrue($result['is_converted']);
    }

    public function test_formulario_sin_remitente_lead_genera_incidencia(): void
    {
        $lead = [
            'medio_nuevo' => null,
            'portal' => 'Web',
            'remitente_lead' => null,
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('warning', $result['data_quality_status']);
        $this->assertSame('Formulario sin Remitente Lead', $result['data_quality_issue']);
    }

    public function test_llamada_sin_delegacion_genera_incidencia(): void
    {
        $lead = [
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Coches.net',
            'delegacion_encargada_text' => null,
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('warning', $result['data_quality_status']);
        $this->assertSame('Llamada sin delegación', $result['data_quality_issue']);
    }

    public function test_portal_sin_grupo_genera_incidencia(): void
    {
        $lead = [
            'medio_nuevo' => null,
            'portal' => 'Portal desconocido',
            'remitente_lead' => 'test@hrmotor.com',
        ];

        $result = $this->service->normalize($lead);

        $this->assertSame('warning', $result['data_quality_status']);
        $this->assertSame('Portal sin grupo portal', $result['data_quality_issue']);
    }
}
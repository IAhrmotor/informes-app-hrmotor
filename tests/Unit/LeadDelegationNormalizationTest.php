<?php

namespace Tests\Unit;

use App\Models\MasterCallDelegationMapping;
use App\Models\MasterDelegation;
use App\Models\MasterFormSenderMapping;
use App\Models\MasterPortal;
use App\Services\Reports\Leads\LeadNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDelegationNormalizationTest extends TestCase
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
            'portal_original' => 'Wallapop',
            'portal_group' => 'Wallapop',
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

        MasterDelegation::create([
            'delegation_name' => 'HR MOTOR ZARAGOZA',
            'commercial_group' => 'Zaragoza',
            'is_active' => true,
        ]);

        MasterFormSenderMapping::create([
            'portal_original' => 'Wallapop',
            'portal_value' => 'Zaragoza',
            'sender_email' => 'leadszaragoza@hrmotor.com',
            'type' => 'Delegación',
            'delegation_name' => 'HR MOTOR ZARAGOZA',
            'commercial_group' => 'Zaragoza',
            'status' => 'active',
        ]);

        MasterCallDelegationMapping::create([
            'portal_original' => 'Coches.net',
            'received_value' => 'Madrid',
            'type' => 'Grupo',
            'delegation_name' => null,
            'commercial_group' => 'Madrid',
            'status' => 'active',
        ]);

        MasterCallDelegationMapping::create([
            'portal_original' => 'Web',
            'received_value' => 'Zaragoza',
            'type' => 'Delegación',
            'delegation_name' => 'HR MOTOR ZARAGOZA',
            'commercial_group' => 'Zaragoza',
            'status' => 'active',
        ]);
    }

    public function test_formulario_con_remitente_mapeado_resuelve_delegacion_y_grupo(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Formulario',
            'portal' => 'Wallapop',
            'remitente_lead' => 'leadszaragoza@hrmotor.com',
            'portal_value' => 'Zaragoza',
            'status' => 'Nuevo',
        ]);

        $this->assertSame('Formulario', $result['channel_direction']);
        $this->assertSame('Wallapop', $result['portal_original']);
        $this->assertSame('HR MOTOR ZARAGOZA', $result['delegation_name']);
        $this->assertSame('Zaragoza', $result['commercial_group']);
        $this->assertSame('ok', $result['data_quality_status']);
    }

    public function test_llamada_con_valor_mapeado_a_grupo_resuelve_grupo_sin_delegacion_real(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Coches.net',
            'delegacion_encargada_text' => 'Madrid',
            'status' => 'Nuevo',
        ]);

        $this->assertSame('Llamada', $result['channel_direction']);
        $this->assertNull($result['delegation_name']);
        $this->assertSame('Madrid', $result['commercial_group']);
        $this->assertSame('ok', $result['data_quality_status']);
    }

    public function test_llamada_con_valor_mapeado_a_delegacion_resuelve_delegacion_real(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Web',
            'delegacion_encargada_text' => 'Zaragoza',
            'status' => 'Nuevo',
        ]);

        $this->assertSame('HR MOTOR ZARAGOZA', $result['delegation_name']);
        $this->assertSame('Zaragoza', $result['commercial_group']);
        $this->assertSame('ok', $result['data_quality_status']);
    }

    public function test_formulario_con_remitente_no_mapeado_genera_incidencia(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Formulario',
            'portal' => 'Wallapop',
            'remitente_lead' => 'desconocido@hrmotor.com',
            'status' => 'Nuevo',
        ]);

        $this->assertSame('warning', $result['data_quality_status']);
        $this->assertSame('Remitente Lead no mapeado', $result['data_quality_issue']);
    }

    public function test_formulario_sin_remitente_usa_delegacion_fallback_si_existe(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Formulario',
            'portal' => 'Web',
            'remitente_lead' => null,
            'delegacion_encargada_bueno' => 'HR MOTOR ZARAGOZA',
            'status' => 'Nuevo',
        ]);

        $this->assertSame('HR MOTOR ZARAGOZA', $result['delegation_name']);
        $this->assertSame('Zaragoza', $result['commercial_group']);
        $this->assertSame('warning', $result['data_quality_status']);
        $this->assertSame('Formulario sin Remitente Lead', $result['data_quality_issue']);
    }

    public function test_exposicion_puede_usar_delegacion_del_propietario(): void
    {
        $result = $this->service->normalize([
            'medio_nuevo' => 'Formulario',
            'portal' => 'Exposición',
            'remitente_lead' => null,
            'owner_name' => 'Comercial Demo',
            'owner_delegation' => 'HR MOTOR ZARAGOZA',
            'status' => 'Nuevo',
        ]);

        $this->assertTrue($result['is_exposition']);
        $this->assertSame('HR MOTOR ZARAGOZA', $result['delegation_name']);
        $this->assertSame('Zaragoza', $result['commercial_group']);
    }
}
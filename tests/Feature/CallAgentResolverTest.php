<?php

namespace Tests\Feature;

use App\Models\CallAgentMapping;
use App\Models\SalesforceUser;
use App\Services\Reports\Calls\CallAgentResolver;
use Database\Seeders\CallAgentMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallAgentResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CallAgentMappingsSeeder::class);
    }

    public function test_resuelve_por_user_id_nombre_acentos_y_codigo_agente(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-laura',
            'name' => 'Laura Hernandez',
            'profile_name' => 'Standard User',
            'user_delegation' => null,
            'is_active' => true,
        ]);
        CallAgentMapping::create([
            'salesforce_user_id' => '005-laura',
            'agent_code' => null,
            'user_name' => 'Laura Hernandez',
            'normalized_name' => app(CallAgentResolver::class)->normalizeName('Laura Hernandez'),
            'team_type' => 'customer_service',
            'active' => true,
        ]);

        $resolver = app(CallAgentResolver::class);

        $byUserId = $resolver->resolve(['id' => '005-laura', 'name' => 'Laura Hernandez', 'profile_name' => 'Standard User'], [], 'commercial_direct');
        $this->assertSame('customer_service', $byUserId['operational_team']);

        $byName = $resolver->resolve(['id' => '005-owner', 'name' => 'Owner', 'profile_name' => 'Standard User'], [
            'destination_agent_name' => 'Laura Hernández',
        ], 'portal');
        $this->assertSame('customer_service', $byName['operational_team']);

        $byCode = $resolver->resolve(['id' => '005-owner', 'name' => 'Owner', 'profile_name' => 'Standard User'], [
            'destination_agent_code' => 'AG1',
            'destination_agent_name' => 'Vanesa Germán',
        ], 'portal');
        $this->assertSame('contact_center', $byCode['operational_team']);
        $this->assertSame('Vanesa German', $byCode['operational_user_name']);
    }

    public function test_resuelve_sistema_y_comerciales_por_owner(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-commercial',
            'name' => 'Comercial Uno',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALCOBENDAS',
            'is_active' => true,
        ]);

        $resolver = app(CallAgentResolver::class);

        $system = $resolver->resolve(['id' => '005-system', 'name' => 'Platform Integration User', 'profile_name' => 'System Administrator'], [], 'commercial_direct');
        $this->assertSame('system', $system['operational_team']);

        $commercial = $resolver->resolve(['id' => '005-commercial', 'name' => 'Comercial Uno', 'profile_name' => 'Compra/Venta'], [], 'commercial_direct');
        $this->assertSame('commercial', $commercial['operational_team']);
        $this->assertSame('Alcobendas', $commercial['delegation']);
        $this->assertSame('Zona Sur y Centro', $commercial['zone']);
    }

    public function test_resuelve_tasadores_y_casos_especiales_de_atencion_al_cliente(): void
    {
        $resolver = app(CallAgentResolver::class);

        $appraiser = $resolver->resolve(['id' => '005-appraiser', 'name' => 'Tasador Uno', 'profile_name' => 'Standard User'], [], 'commercial_direct');
        $this->assertSame('appraiser', $appraiser['operational_team']);

        foreach (['Vanessa SanJuan', 'Vanessa San Juan', 'Vanesa SanJuan', 'Vanesa San Juan', 'Callcenter Fontellas', 'Call Center Fontellas'] as $name) {
            $resolved = $resolver->resolve(['id' => '005-special-'.$name, 'name' => $name, 'profile_name' => 'Standard User'], [], 'commercial_direct');
            $this->assertSame('customer_service', $resolved['operational_team']);
        }
    }
}

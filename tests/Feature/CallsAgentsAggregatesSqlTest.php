<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsAgentsAggregatesSqlTest extends TestCase
{
    use CreatesCallDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_agents_agrupa_por_usuario_equipo_y_excluye_sistema(): void
    {
        $this->callRow('commercial', ['operational_user_name' => 'Comercial Uno', 'operational_team' => 'commercial']);
        $this->callRow('commercial-unclassified', [
            'operational_user_name' => 'Jhon Frehiman Castro Espinosa',
            'operational_team' => 'commercial',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('palomo-old-commercial', [
            'operational_user_name' => 'Jose Palomo Casas',
            'operational_team' => 'commercial',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('customer', ['operational_user_name' => 'Carolina Gayarre', 'operational_team' => 'customer_service']);
        $this->callRow('appraiser', ['operational_user_name' => 'Tasador Uno', 'operational_team' => 'unclassified']);
        $this->callRow('system', [
            'operational_user_name' => 'Platform Integration User',
            'operational_team' => 'system',
            'owner_profile_name' => 'System Administrator',
        ]);

        foreach (['Vanesa German', "Vanesa Germ\u{00E1}n", "AG1 - Vanesa Germ\u{00E1}n"] as $index => $name) {
            $this->callRow('vanesa-'.$index, [
                'operational_user_name' => $name,
                'destination_agent_name' => $name,
                'operational_team' => 'contact_center',
                'adjusted_duration_seconds' => 30,
            ]);
        }

        foreach (['Yuleidis Garcia', "Yuleidis Garc\u{00ED}a", "AG23 - Yuleidis Garc\u{00ED}a"] as $index => $name) {
            $this->callRow('yuleidis-'.$index, [
                'operational_user_name' => $name,
                'destination_agent_name' => $name,
                'operational_team' => 'contact_center',
                'adjusted_duration_seconds' => 60,
            ]);
        }

        $payload = $this->getJson('/informes/llamadas/data/agents')->assertOk()->json();
        $contactCenter = collect($payload['contact_center']);

        $this->assertSame('Comercial Uno', $payload['commercials'][0]['user_name']);
        $this->assertCount(1, $payload['commercials']);
        $this->assertSame('Carolina Gayarre', $payload['customer_service'][0]['user_name']);
        $this->assertSame('Tasador Uno', $payload['appraisers'][0]['user_name']);
        $this->assertEmpty(collect($payload['agents'])->where('user_name', 'Platform Integration User')->all());
        $this->assertEmpty(collect($payload['agents'])->where('user_name', 'Jhon Frehiman Castro Espinosa')->all());
        $this->assertCount(3, $contactCenter);
        $this->assertSame('Jose Ignacio Palomo', $contactCenter->firstWhere('user_name', 'Jose Ignacio Palomo')['user_name']);
        $this->assertSame('Contact Center', $contactCenter->firstWhere('user_name', 'Jose Ignacio Palomo')['delegation']);
        $this->assertSame(3, $contactCenter->firstWhere('user_name', 'Vanesa German')['total_calls']);
        $this->assertSame(3, $contactCenter->firstWhere('user_name', 'Yuleidis Garcia')['total_calls']);
    }
}

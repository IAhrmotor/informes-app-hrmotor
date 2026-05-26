<?php

namespace Tests\Feature;

use App\Services\Reports\Calls\CallClassificationRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsDelegationsAggregatesSqlTest extends TestCase
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

    public function test_delegations_agrupa_zonas_operativas_y_excluye_sistema(): void
    {
        $this->callRow('commercial', [
            'operational_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
        ]);
        $this->callRow('customer-service', [
            'operational_user_name' => 'Carolina Gayarre',
            'operational_team' => 'customer_service',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('contact-center', [
            'operational_user_name' => 'Vanesa German',
            'operational_team' => 'contact_center',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
        ]);
        $this->callRow('system', [
            'operational_user_name' => 'Carlos Torres',
            'operational_team' => 'system',
        ]);

        $payload = $this->getJson('/informes/llamadas/data/delegations')->assertOk()->json();
        $zones = collect($payload['zones'])->pluck('zone');
        $delegations = collect($payload['delegations'])->pluck('delegation');

        $this->assertTrue($zones->contains('Zona Sur y Centro'));
        $this->assertTrue($zones->contains(CallClassificationRules::CUSTOMER_SERVICE_LABEL));
        $this->assertTrue($zones->contains('Contact Center'));
        $this->assertTrue($delegations->contains('Alcobendas'));
        $this->assertTrue($delegations->contains(CallClassificationRules::CUSTOMER_SERVICE_LABEL));
        $this->assertTrue($delegations->contains('Contact Center'));
        $this->assertFalse($zones->contains('Sistema / Sin agente'));
    }
}

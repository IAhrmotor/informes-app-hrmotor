<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallDashboardEndpointTest extends TestCase
{
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

    public function test_dashboard_y_endpoints_responden_con_datos(): void
    {
        $this->callRow(['salesforce_id' => '00T-1']);

        $this->get('/informes/llamadas')->assertOk()->assertSee('Llamadas');
        $this->getJson('/informes/llamadas/data/summary')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('kpis.total_calls', 1);
        $this->getJson('/informes/llamadas/data/agents')->assertOk()->assertJsonPath('agents.0.total_calls', 1);
        $this->getJson('/informes/llamadas/data/delegations')->assertOk()->assertJsonPath('zones.0.total_calls', 1);
        $this->getJson('/informes/llamadas/data/portals')->assertOk()->assertJsonPath('items.0.portal', 'Web');
    }

    private function callRow(array $overrides = []): void
    {
        SalesforceCall::create(array_merge([
            'salesforce_id' => '00T-base',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Comercial Owner',
            'operational_user_name' => 'Comercial Owner',
            'operational_team' => 'commercial',
            'owner_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
            'adjusted_duration_seconds' => 40,
        ], $overrides));
    }
}

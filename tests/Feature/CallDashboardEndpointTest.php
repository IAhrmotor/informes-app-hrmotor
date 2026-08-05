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

    public function test_equipos_incluyen_fila_sin_equipo_y_concilian_con_atendidas(): void
    {
        $this->callRow(['salesforce_id' => '00T-operational']);
        $this->callRow([
            'salesforce_id' => '00T-unassigned',
            'operational_team' => 'system',
            'owner_team' => 'system',
        ]);

        $summary = $this->getJson('/informes/llamadas/data/summary')->assertOk()->json('kpis');
        $teams = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('teams'));

        $this->assertSame(2, $summary['answered']);
        $this->assertSame(2, $teams->sum('answered'));
        $this->assertSame(1, $teams->firstWhere('team_label', 'Sin equipo')['answered']);
    }

    public function test_auditoria_identifica_task_fuera_del_universo_por_call_object(): void
    {
        $this->callRow([
            'salesforce_id' => '00T-included',
            'call_object' => 'a-call-object',
            'included_in_dashboard' => true,
            'classification_rule_version' => '2026-08-04.1',
        ]);
        $this->callRow([
            'salesforce_id' => '00T-excluded',
            'call_object' => null,
            'included_in_dashboard' => false,
            'dashboard_exclusion_reason' => 'missing_call_object',
        ]);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1);
        $items = collect($this->getJson('/informes/llamadas/data/audit')->assertOk()->json('items'));

        $this->assertCount(2, $items);
        $this->assertSame('included', $items->firstWhere('task_id', '00T-included')['inclusion_exclusion_reason']);
        $this->assertSame('missing_call_object', $items->firstWhere('task_id', '00T-excluded')['inclusion_exclusion_reason']);
        $this->get('/informes/llamadas/export/audit.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
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
            'portales_raw' => 'Web',
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

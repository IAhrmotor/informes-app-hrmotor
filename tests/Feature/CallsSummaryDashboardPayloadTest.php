<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsSummaryDashboardPayloadTest extends TestCase
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

    public function test_summary_returns_dashboard_kpis_charts_and_rankings(): void
    {
        $this->callRow('direct', [
            'portales_raw' => null,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
        ]);
        $this->callRow('portal', [
            'portales_raw' => 'Coches.net',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'operational_team' => 'contact_center',
        ]);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 2)
            ->assertJsonPath('kpis.answered_calls', 2)
            ->assertJsonPath('kpis.lost_calls', 0)
            ->assertJsonPath('kpis.commercial_direct_calls', 1)
            ->assertJsonPath('kpis.portal_calls', 1)
            ->assertJsonPath('kpis.direct_answered', 1)
            ->assertJsonStructure([
                'kpis' => [
                    'total_calls',
                    'answered_calls',
                    'lost_calls',
                    'attention_rate',
                    'commercial_direct_calls',
                    'portal_calls',
                    'avg_talk_seconds',
                    'overflow_count',
                    'direct_answered',
                    'direct_lost',
                    'commercial_direct_answered',
                    'commercial_direct_lost',
                    'portal_answered',
                    'portal_lost',
                ],
                'charts' => [
                    'answered_vs_lost',
                    'direct_vs_portal',
                    'answered_by_team',
                    'daily_evolution',
                ],
                'rankings' => [
                    'top_portals_by_calls',
                    'top_portals_by_lost',
                    'top_agents_by_answered',
                    'top_teams_by_answered',
                    'top_overflows_by_portal',
                ],
                'insights',
            ]);
    }
}

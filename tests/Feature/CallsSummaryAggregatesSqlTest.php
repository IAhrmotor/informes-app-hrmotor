<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsSummaryAggregatesSqlTest extends TestCase
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

    public function test_summary_calcula_kpis_con_agregados_sql(): void
    {
        $this->callRow('direct-answered', [
            'call_origin' => 'commercial_direct',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
            'operational_team' => 'commercial',
            'adjusted_duration_seconds' => 30,
        ]);
        $this->callRow('direct-lost', [
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
            'call_status' => 'not_answered',
            'is_answered' => false,
            'is_lost' => true,
            'direction' => 'outbound',
            'operational_team' => 'customer_service',
        ]);
        $this->callRow('portal-answered', [
            'portales_raw' => 'Web',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
            'operational_team' => 'contact_center',
            'adjusted_duration_seconds' => 90,
        ]);
        $this->callRow('portal-lost', [
            'portales_raw' => 'Google Maps',
            'call_origin' => 'portal',
            'portal_resolved' => 'Google Maps',
            'call_status' => 'not_answered',
            'is_answered' => false,
            'is_lost' => true,
            'direction' => 'outbound',
            'operational_team' => 'appraiser',
        ]);

        $kpis = $this->getJson('/informes/llamadas/data/summary')->assertOk()->json('kpis');

        $this->assertSame(4, $kpis['total_calls']);
        $this->assertSame(2, $kpis['answered_calls']);
        $this->assertSame(2, $kpis['lost_calls']);
        $this->assertSame(2, $kpis['commercial_direct_calls']);
        $this->assertSame(1, $kpis['commercial_direct_answered']);
        $this->assertSame(1, $kpis['commercial_direct_lost']);
        $this->assertSame(2, $kpis['portal_calls']);
        $this->assertSame(1, $kpis['portal_answered']);
        $this->assertSame(1, $kpis['portal_lost']);
        $this->assertSame(2, $kpis['inbound_calls']);
        $this->assertSame(2, $kpis['outbound_calls']);
        $this->assertEquals(60.0, $kpis['avg_talk_seconds']);
        $this->assertSame(1, $kpis['answered_by_commercial']);
        $this->assertSame(1, $kpis['answered_by_contact_center']);
    }
}

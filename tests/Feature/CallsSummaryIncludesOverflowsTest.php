<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsSummaryIncludesOverflowsTest extends TestCase
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

    public function test_summary_returns_overflow_count_and_percentage(): void
    {
        $this->callRow('overflow', [
            'portales_raw' => 'Coches.net',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'operational_team' => 'contact_center',
            'is_overflow' => true,
        ]);
        $this->callRow('portal-commercial', [
            'portales_raw' => 'Coches.net',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'operational_team' => 'commercial',
            'is_overflow' => false,
        ]);
        $this->callRow('web-support', [
            'portales_raw' => 'Web',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
            'operational_team' => 'customer_service',
            'poll_value' => '3',
            'is_overflow' => false,
        ]);

        $kpis = $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.overflow_count', 1)
            ->assertJsonPath('kpis.overflow_denominator', 2)
            ->json('kpis');

        $this->assertEquals(50.0, $kpis['overflow_pct']);
    }
}

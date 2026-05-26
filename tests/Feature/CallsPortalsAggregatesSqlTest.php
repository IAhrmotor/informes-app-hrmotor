<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsPortalsAggregatesSqlTest extends TestCase
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

    public function test_portals_agrupa_solo_call_origin_portal(): void
    {
        $this->callRow('direct', [
            'portales_raw' => null,
            'call_origin' => 'commercial_direct',
            'portal_resolved' => 'Comercial directo',
        ]);
        $this->callRow('legacy-switchboard', [
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
        ]);
        $this->callRow('web-1', [
            'portales_raw' => 'Web',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
        ]);
        $this->callRow('web-2', [
            'portales_raw' => 'Web',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
            'call_status' => 'not_answered',
            'is_answered' => false,
            'is_lost' => true,
        ]);
        $this->callRow('maps', [
            'portales_raw' => 'Google Maps',
            'call_origin' => 'portal',
            'portal_resolved' => 'Google Maps',
        ]);

        $rows = collect($this->getJson('/informes/llamadas/data/portals')->assertOk()->json('items'));

        $this->assertEqualsCanonicalizing(['Web', 'Google Maps'], $rows->pluck('portal')->all());
        $this->assertSame(2, $rows->firstWhere('portal', 'Web')['total_calls']);
        $this->assertSame(1, $rows->firstWhere('portal', 'Web')['not_answered']);
        $this->assertFalse($rows->pluck('portal')->contains('Comercial directo'));
        $this->assertFalse($rows->pluck('portal')->contains('Llamada directa'));
    }
}

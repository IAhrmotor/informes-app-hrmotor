<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\TestCase;

class CallsSummaryPerformanceQueryTest extends TestCase
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

    public function test_summary_usa_consultas_agregadas_y_no_hidrata_llamadas(): void
    {
        $this->insertCallRows(250);

        $retrievedModels = 0;
        $queries = [];

        SalesforceCall::retrieved(static function () use (&$retrievedModels): void {
            $retrievedModels++;
        });

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 250);

        $this->assertSame(0, $retrievedModels);
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql) => str_contains(strtolower($sql), 'select *') && str_contains($sql, 'salesforce_calls')
        ));
    }
}

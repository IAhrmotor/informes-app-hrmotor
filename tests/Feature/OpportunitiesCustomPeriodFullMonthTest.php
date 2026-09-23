<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class OpportunitiesCustomPeriodFullMonthTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_custom_period_includes_complete_month_until_exclusive_next_day(): void
    {
        $this->opportunityRow('006-april-start', [
            'created_date' => '2026-04-01 00:00:00',
        ]);
        $this->opportunityRow('006-april-end', [
            'created_date' => '2026-04-30 23:59:59',
        ]);
        $this->opportunityRow('006-may-start', [
            'created_date' => '2026-05-01 00:00:00',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-04-01',
            'current_end' => '2026-04-30',
            'comparison_start' => '2026-03-01',
            'comparison_end' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertJsonPath('periodo_actual.inicio', '2026-04-01')
            ->assertJsonPath('periodo_actual.fin', '2026-04-30')
            ->assertJsonPath('periodo_actual.technical.start_inclusive', '2026-04-01T00:00:00+00:00')
            ->assertJsonPath('periodo_actual.technical.end_exclusive', '2026-05-01T00:00:00+00:00')
            ->assertJsonPath('periodo_actual.technical.semantics', '[start,end)')
            ->assertJsonPath('periodo_actual.technical.timezone', 'UTC')
            ->assertJsonPath('periodo_comparado.technical.end_exclusive', '2026-04-01T00:00:00+00:00')
            ->assertJsonPath('kpis.oportunidades_totales', 2);
    }

    public function test_presets_publican_limites_exclusivos_sin_end_of_day_falso(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:34:56');

        $currentMonth = $this->getJson('/informes/reservas-ventas/data/summary?period=current_month')
            ->assertOk()
            ->assertJsonPath('periodo_actual.inicio', '2026-09-01')
            ->assertJsonPath('periodo_actual.fin', '2026-09-23')
            ->assertJsonPath('periodo_actual.technical.end_exclusive', '2026-09-24T00:00:00+00:00')
            ->assertJsonPath('periodo_comparado.inicio', '2026-08-01')
            ->assertJsonPath('periodo_comparado.fin', '2026-08-23')
            ->assertJsonPath('periodo_comparado.technical.end_exclusive', '2026-08-24T00:00:00+00:00')
            ->json();
        $this->assertStringNotContainsString('23:59:59', data_get($currentMonth, 'periodo_comparado.technical.end_exclusive'));

        $this->getJson('/informes/reservas-ventas/data/summary?period=previous_month')
            ->assertOk()
            ->assertJsonPath('periodo_actual.technical.start_inclusive', '2026-08-01T00:00:00+00:00')
            ->assertJsonPath('periodo_actual.technical.end_exclusive', '2026-09-01T00:00:00+00:00')
            ->assertJsonPath('periodo_comparado.technical.end_exclusive', '2026-08-01T00:00:00+00:00');

        $this->getJson('/informes/reservas-ventas/data/summary?period=last_30_days')
            ->assertOk()
            ->assertJsonPath('periodo_actual.technical.start_inclusive', '2026-08-24T12:34:56+00:00')
            ->assertJsonPath('periodo_actual.technical.end_exclusive', '2026-09-23T12:34:56+00:00')
            ->assertJsonPath('periodo_comparado.technical.end_exclusive', '2026-08-24T12:34:56+00:00');
    }

    public function test_mes_actual_limita_el_tramo_comparado_al_final_del_mes_anterior(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 12:34:56');

        $this->getJson('/informes/reservas-ventas/data/summary?period=current_month')
            ->assertOk()
            ->assertJsonPath('periodo_actual.technical.start_inclusive', '2026-03-01T00:00:00+00:00')
            ->assertJsonPath('periodo_actual.technical.end_exclusive', '2026-04-01T00:00:00+00:00')
            ->assertJsonPath('periodo_comparado.inicio', '2026-02-01')
            ->assertJsonPath('periodo_comparado.fin', '2026-02-28')
            ->assertJsonPath('periodo_comparado.technical.start_inclusive', '2026-02-01T00:00:00+00:00')
            ->assertJsonPath('periodo_comparado.technical.end_exclusive', '2026-03-01T00:00:00+00:00');
    }

    public function test_last_30_days_reutiliza_cache_aunque_avance_el_reloj_unos_segundos(): void
    {
        CarbonImmutable::setTestNow('2026-09-23 12:34:56');
        $first = $this->getJson('/informes/reservas-ventas/data/summary?period=last_30_days')
            ->assertOk()
            ->json();

        CarbonImmutable::setTestNow('2026-09-23 12:35:01');
        $second = $this->getJson('/informes/reservas-ventas/data/summary?period=last_30_days')
            ->assertOk()
            ->json();

        $this->assertSame('2026-09-23 12:34:56', data_get($first, 'dataset_generated_at'));
        $this->assertSame(data_get($first, 'dataset_generated_at'), data_get($second, 'dataset_generated_at'));
        $this->assertSame(
            data_get($first, 'periodo_actual.technical'),
            data_get($second, 'periodo_actual.technical'),
        );
        $this->assertSame('2026-09-23T12:34:56+00:00', data_get($second, 'periodo_actual.technical.end_exclusive'));
    }
}

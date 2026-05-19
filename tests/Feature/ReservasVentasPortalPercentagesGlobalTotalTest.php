<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasPortalPercentagesGlobalTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_portales_calculan_porcentajes_sobre_total_global_filtrado(): void
    {
        $this->opportunities('006-coches-reserva', 20, [
            'portal_resolved' => 'Coches.net',
            'stage_name' => 'Reserva',
            'reservation' => true,
        ]);
        $this->opportunities('006-coches-caida', 10, [
            'portal_resolved' => 'Coches.net',
            'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunities('006-coches-cv', 5, [
            'portal_resolved' => 'Coches.net',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'cv_signed' => true,
        ]);
        $this->opportunities('006-coches-neutra', 65, ['portal_resolved' => 'Coches.net']);
        $this->opportunities('006-web-neutra', 900, ['portal_resolved' => 'Web']);

        $row = collect($this->getJson('/informes/reservas-ventas/data/portals?'.$this->query())->json('items'))
            ->firstWhere('portal', 'Coches.net');

        $this->assertSame(100, $row['oportunidades_totales']);
        $this->assertSame(20, $row['reservas_vivas']);
        $this->assertSame(10, $row['oportunidades_caidas']);
        $this->assertSame(5, $row['cv_firmados']);
        $this->assertSame(2.0, (float) $row['reservas_vivas_pct']);
        $this->assertSame(1.0, (float) $row['oportunidades_caidas_pct']);
        $this->assertSame(0.5, (float) $row['cv_firmados_pct']);
        $this->assertNotSame(20.0, (float) $row['reservas_vivas_pct']);
        $this->assertNotSame(10.0, (float) $row['oportunidades_caidas_pct']);
        $this->assertNotSame(5.0, (float) $row['cv_firmados_pct']);
    }

    private function opportunities(string $prefix, int $count, array $attributes = []): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $id = "$prefix-$i";
            $rows[] = array_merge([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'stage_name' => 'Abierta',
                'record_type_name' => 'Venta',
                'owner_id' => '005-owner',
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_resolution_source' => 'opportunity',
                'reservation' => false,
                'cv_signed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $attributes);
        }

        SalesforceOpportunity::query()->insert($rows);
    }

    private function query(): string
    {
        return http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]);
    }
}

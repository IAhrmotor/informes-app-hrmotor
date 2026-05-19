<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasOtherPercentagesStillByRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_comerciales_delegaciones_y_zonas_siguen_calculando_porcentajes_sobre_su_fila(): void
    {
        $this->opportunities('006-alc-reserva', 5, [
            'owner_id' => '005-alc',
            'owner_name' => 'Comercial Alcobendas',
            'owner_delegation' => 'Alcobendas',
            'stage_name' => 'Reserva',
            'reservation' => true,
        ]);
        $this->opportunities('006-alc-caida', 2, [
            'owner_id' => '005-alc',
            'owner_name' => 'Comercial Alcobendas',
            'owner_delegation' => 'Alcobendas',
            'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunities('006-alc-cv', 1, [
            'owner_id' => '005-alc',
            'owner_name' => 'Comercial Alcobendas',
            'owner_delegation' => 'Alcobendas',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'cv_signed' => true,
        ]);
        $this->opportunities('006-alc-neutra', 2, [
            'owner_id' => '005-alc',
            'owner_name' => 'Comercial Alcobendas',
            'owner_delegation' => 'Alcobendas',
        ]);
        $this->opportunities('006-bilbao-neutra', 90, [
            'owner_id' => '005-bilbao',
            'owner_name' => 'Comercial Bilbao',
            'owner_delegation' => 'Bilbao',
        ]);

        $response = $this->getJson('/informes/reservas-ventas/data/commercials?'.$this->query());

        $zone = collect($response->json('zones'))->firstWhere('zone', 'Zona Sur y Centro');
        $delegation = collect($response->json('delegations'))->firstWhere('commercial_delegation', 'Alcobendas');
        $commercial = collect($response->json('commercials'))->firstWhere('comercial', 'Comercial Alcobendas');

        foreach ([$zone, $delegation, $commercial] as $row) {
            $this->assertSame(10, $row['oportunidades_totales']);
            $this->assertSame(5, $row['reservas_vivas']);
            $this->assertSame(2, $row['oportunidades_caidas']);
            $this->assertSame(1, $row['cv_firmados']);
            $this->assertSame(50.0, (float) $row['reservas_vivas_pct']);
            $this->assertSame(20.0, (float) $row['oportunidades_caidas_pct']);
            $this->assertSame(10.0, (float) $row['cv_firmados_pct']);
        }
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

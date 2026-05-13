<?php

namespace Tests\Unit;

use App\Services\Reports\MonthlyCommercial\MonthlyCommercialLeadEnricher;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MonthlyCommercialLeadEnricherTest extends TestCase
{
    private MonthlyCommercialLeadEnricher $enricher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enricher = new MonthlyCommercialLeadEnricher();
    }

    public function test_potencial_usa_owner_id_como_responsable(): void
    {
        $result = $this->enricher->enrich([
            'salesforce_id' => '00Q1',
            'status' => 'Potencial',
            'owner_id' => '005-owner',
            'owner_name' => 'Owner Demo',
        ], null, [], CarbonImmutable::parse('2026-05-13'));

        $this->assertSame('005-owner', $result['responsable_id']);
        $this->assertSame('Owner Demo', $result['responsable_nombre']);
    }

    public function test_convertido_usa_persona_que_trabajo_si_existe(): void
    {
        $result = $this->enricher->enrich([
            'salesforce_id' => '00Q1',
            'status' => 'Convertido',
            'owner_id' => '005-owner',
            'persona_que_trabajo_id' => '005-worker',
            'persona_que_trabajo_name' => 'Worker Demo',
        ]);

        $this->assertSame('005-worker', $result['responsable_id']);
        $this->assertSame('Worker Demo', $result['responsable_nombre']);
    }

    public function test_descartado_usa_propietario_cuando_se_descarto_si_existe(): void
    {
        $result = $this->enricher->enrich([
            'salesforce_id' => '00Q1',
            'status' => 'Descartado',
            'owner_id' => '005-owner',
            'propietario_descarte_id' => '005-discard',
            'propietario_descarte_name' => 'Discard Demo',
        ]);

        $this->assertSame('005-discard', $result['responsable_id']);
        $this->assertSame('Discard Demo', $result['responsable_nombre']);
    }

    public function test_potencial_sin_ninguna_task_event_queda_marcado(): void
    {
        $result = $this->enricher->enrich([
            'salesforce_id' => '00Q1',
            'status' => 'Potencial',
            'owner_id' => '005-owner',
        ]);

        $this->assertFalse($result['tiene_task_event_registrada']);
        $this->assertTrue($result['potencial_sin_ninguna_task_event']);
        $this->assertTrue($result['potencial_sin_seguimiento_mayor_3_dias']);
    }
}

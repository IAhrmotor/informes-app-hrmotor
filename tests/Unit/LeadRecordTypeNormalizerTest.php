<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use PHPUnit\Framework\TestCase;

class LeadRecordTypeNormalizerTest extends TestCase
{
    public function test_normaliza_tildes_mayusculas_espacios_y_alias_controlados(): void
    {
        $normalizer = new LeadRecordTypeNormalizer;

        $this->assertSame('tasacion', $normalizer->normalize(' Tasación '));
        $this->assertSame('tasacion', $normalizer->normalize('TASACION'));
        $this->assertSame('venta_con_cambio', $normalizer->normalize('Venta   con cambio'));
        $this->assertSame('lead', $normalizer->normalize(' Lead '));
        $this->assertSame('ayvens', $normalizer->normalize('AYVENS'));
        $this->assertNull($normalizer->normalize('Tipo no permitido'));
    }

    public function test_venta_no_incluye_lead_ni_ayvens_hasta_confirmacion_funcional(): void
    {
        $normalizer = new LeadRecordTypeNormalizer;

        $this->assertSame(['venta', 'venta_con_cambio'], $normalizer->ventaFilterTypes());
    }
}

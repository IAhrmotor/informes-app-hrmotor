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
        $this->assertSame('venta', $normalizer->normalize(' Lead '));
        $this->assertSame('venta', $normalizer->normalize('AYVENS'));
        $this->assertSame('exposicion', $normalizer->normalize(' Exposición '));
        $this->assertNull($normalizer->normalize('Tipo no permitido'));
    }

    public function test_venta_incluye_las_claves_historicas_hasta_el_reproceso(): void
    {
        $normalizer = new LeadRecordTypeNormalizer;

        $this->assertSame(['venta', 'venta_con_cambio', 'lead', 'ayvens'], $normalizer->ventaFilterTypes());
    }
}

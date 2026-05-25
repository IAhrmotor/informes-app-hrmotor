<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallPortalNormalizer;
use Tests\TestCase;

class CallPortalNormalizerTest extends TestCase
{
    public function test_normaliza_portales_de_llamadas(): void
    {
        $normalizer = app(CallPortalNormalizer::class);

        $cases = [
            [null, 'Comercial directo', 'commercial_direct'],
            ['Llamada directa', 'Comercial directo', 'commercial_direct'],
            ['Web Alcobendas', 'Web', 'portal'],
            ['Web Rivas', 'Web', 'portal'],
            ['Web San Sebastian', 'Web', 'portal'],
            ['Google Maps Gijon', 'Google Maps', 'portal'],
            ['Google Maps Malaga', 'Google Maps', 'portal'],
            ['Coches.net Madrid', 'Coches.net', 'portal'],
            ['CochesNet Valencia', 'Coches.net', 'portal'],
            ['Coches.com Alicante', 'Coches.com', 'portal'],
            ['Cochescom general', 'Coches.com', 'portal'],
            ['Autocasion Madrid', 'Autocasion', 'portal'],
            ['Wallapop Barcelona', 'Wallapop', 'portal'],
            ['ATENCION AL CLIENTE', 'Atencion al cliente', 'portal'],
            ['valor raro', 'Sin clasificar', 'portal'],
        ];

        foreach ($cases as [$raw, $portal, $origin]) {
            $normalized = $normalizer->normalize($raw);
            $this->assertSame($portal, $normalized['portal']);
            $this->assertSame($origin, $normalized['origin']);
        }
    }
}

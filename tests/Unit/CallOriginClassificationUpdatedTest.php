<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallPortalNormalizer;
use Tests\TestCase;

class CallOriginClassificationUpdatedTest extends TestCase
{
    public function test_llamada_directa_es_comercial_directo_y_nunca_switchboard(): void
    {
        $normalizer = app(CallPortalNormalizer::class);

        $cases = [
            [null, 'commercial_direct', 'Comercial directo'],
            ['Llamada directa', 'commercial_direct', 'Comercial directo'],
            ['Web Pamplona', 'portal', 'Web'],
            ['Google Maps Gijón', 'portal', 'Google Maps'],
        ];

        foreach ($cases as [$raw, $origin, $portal]) {
            $result = $normalizer->normalize($raw);

            $this->assertSame($origin, $result['origin']);
            $this->assertSame($portal, $result['portal']);
            $this->assertNotSame('switchboard', $result['origin']);
        }
    }
}

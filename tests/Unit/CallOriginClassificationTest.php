<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallPortalNormalizer;
use Tests\TestCase;

class CallOriginClassificationTest extends TestCase
{
    public function test_clasifica_origen_desde_portales(): void
    {
        $normalizer = app(CallPortalNormalizer::class);

        $this->assertSame('commercial_direct', $normalizer->normalize(null)['origin']);
        $this->assertSame('commercial_direct', $normalizer->normalize('Llamada directa')['origin']);
        $this->assertSame('portal', $normalizer->normalize('Web Alcobendas')['origin']);
        $this->assertSame('portal', $normalizer->normalize('Google Maps Gijon')['origin']);
    }
}

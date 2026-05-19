<?php

namespace Tests\Unit;

use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use Tests\TestCase;

class OpportunityPortalNormalizerTest extends TestCase
{
    public function test_normaliza_portales_oficiales(): void
    {
        $normalizer = app(OpportunityPortalNormalizer::class);

        foreach ([
            ['Coches Net Málaga', 'Coches.net'],
            ['COCHES.NET', 'Coches.net'],
            ['Coches Com', 'Coches.com'],
            ['Autocasión', 'Autocasion'],
            ['Sum Auto', 'Sumauto'],
            ['Mil anuncios', 'Milanuncios'],
            ['1000 anuncios', 'Milanuncios'],
            ['Facebook', 'Meta'],
            ['Instagram Ads', 'Meta'],
            ['Google', 'Web'],
            ['Google Maps', 'Google Maps'],
            ['Maps', 'Google Maps'],
            ['Buscador', 'Web'],
            ['Directo', 'Web'],
            ['(direct)', 'Web'],
            ['Chatbot', 'Web'],
            ['WhatsApp', 'Web'],
            ['YouTube', 'Web'],
            ['bing.com', 'Web'],
            ['Facilitea', 'Facilitea'],
            ['Marketing Cloud', 'Marketing Cloud'],
            ['Motor', 'Motor.es'],
            ['HR Motor', 'Sin clasificar'],
            ['Auto Scout', 'Autoscout'],
            ['Scout', 'Autoscout'],
            ['Captación comercial', 'Captacion'],
            ['Atención al cliente', 'Atencion al cliente'],
            ['Autopilot', 'Autopilot'],
            ['Formulario', 'Formulario'],
            ['Formulario web', 'Web'],
            ['-', 'Sin clasificar'],
            ['', 'Sin clasificar'],
            [null, 'Sin clasificar'],
        ] as [$raw, $expected]) {
            $result = $normalizer->normalize($raw);

            $this->assertSame($expected, $result['portal'], (string) $raw);
        }
    }

    public function test_marca_portales_no_concluyentes_desde_opportunity(): void
    {
        $normalizer = app(OpportunityPortalNormalizer::class);

        foreach (['Exposición', '3CX', 'Llamada directa', 'Direct', 'Directo', '(direct)', 'Buscador', 'Chatbot', 'Chat', null, '-'] as $raw) {
            $this->assertFalse($normalizer->normalize($raw)['is_conclusive'], (string) $raw);
        }

        $this->assertTrue($normalizer->normalize('Coches Net Alicante')['is_conclusive']);
    }
}

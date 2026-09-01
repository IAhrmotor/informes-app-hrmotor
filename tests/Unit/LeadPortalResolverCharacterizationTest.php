<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadPortalResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeadPortalResolverCharacterizationTest extends TestCase
{
    #[DataProvider('channels')]
    public function test_canal_actual_depende_solo_de_medio_nuevo(mixed $raw, string $expected): void
    {
        $this->assertSame($expected, (new LeadPortalResolver)->channel($raw));
    }

    public static function channels(): array
    {
        return [
            'llamada exacta' => ['Llamada', 'Llamada'],
            'llamada normalizada' => ['  LLÁMADA  ', 'Llamada'],
            'formulario' => ['Formulario', 'Formulario'],
            'vacio' => ['', 'Formulario'],
            'whitespace' => ['   ', 'Formulario'],
            'null' => [null, 'Formulario'],
            'desconocido' => ['WhatsApp', 'Formulario'],
        ];
    }

    public function test_llamada_prioriza_fuente_nuevo_y_conserva_el_api_name_como_source(): void
    {
        $result = (new LeadPortalResolver)->resolve([
            'medio_nuevo' => 'llamada',
            'fuente_nuevo' => 'Fuente nueva',
            'portal_text' => 'Portal texto',
            'fuente_origen' => 'Fuente legacy',
        ]);

        $this->assertSame([
            'channel' => 'Llamada',
            'portal' => 'Fuente nueva',
            'source' => 'Fuente_Nuevo__c',
        ], $result);
    }

    public function test_llamada_hace_fallback_portal_text_y_despues_fuente_legacy(): void
    {
        $resolver = new LeadPortalResolver;

        $portalText = $resolver->resolve([
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => '   ',
            'portal_text' => '  Portal texto  ',
            'fuente_origen' => 'Fuente legacy',
        ]);
        $legacy = $resolver->resolve([
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => null,
            'portal_text' => '',
            'fuente_origen' => 'Fuente legacy',
        ]);

        $this->assertSame('Portal texto', $portalText['portal']);
        $this->assertSame('Portal_Text__c', $portalText['source']);
        $this->assertSame('Fuente legacy', $legacy['portal']);
        $this->assertSame('LEA_SEL_Fuente_Origen__c', $legacy['source']);
    }

    public function test_formulario_prioriza_portal_text_fuente_legacy_y_fuente_nuevo(): void
    {
        $resolver = new LeadPortalResolver;

        $portalText = $resolver->resolve([
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Portal texto',
            'fuente_origen' => 'Fuente legacy',
            'fuente_nuevo' => 'Fuente nueva',
        ]);
        $legacy = $resolver->resolve([
            'medio_nuevo' => null,
            'portal_text' => ' ',
            'fuente_origen' => 'Fuente legacy',
            'fuente_nuevo' => 'Fuente nueva',
        ]);
        $new = $resolver->resolve([
            'medio_nuevo' => 'desconocido',
            'portal_text' => null,
            'fuente_origen' => '',
            'fuente_nuevo' => 'Fuente nueva',
        ]);

        $this->assertSame(['channel' => 'Formulario', 'portal' => 'Portal texto', 'source' => 'Portal_Text__c'], $portalText);
        $this->assertSame(['channel' => 'Formulario', 'portal' => 'Fuente legacy', 'source' => 'LEA_SEL_Fuente_Origen__c'], $legacy);
        $this->assertSame(['channel' => 'Formulario', 'portal' => 'Fuente nueva', 'source' => 'Fuente_Nuevo__c'], $new);
    }

    public function test_sin_candidatos_devuelve_sin_clasificar_y_source_fallback(): void
    {
        $result = (new LeadPortalResolver)->resolve([
            'medio_nuevo' => null,
            'fuente_nuevo' => null,
            'portal_text' => '',
            'fuente_origen' => '   ',
        ]);

        $this->assertSame([
            'channel' => 'Formulario',
            'portal' => 'Sin clasificar',
            'source' => 'fallback',
        ], $result);
    }
}

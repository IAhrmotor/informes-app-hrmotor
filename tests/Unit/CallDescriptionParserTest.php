<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallDescriptionParser;
use Tests\TestCase;

class CallDescriptionParserTest extends TestCase
{
    public function test_extrae_campos_de_descripcion_3cx(): void
    {
        $parsed = app(CallDescriptionParser::class)->parse(<<<'TEXT'
Resultado: ANSWERED
Tipo: Entrante a fijo
Telefono cliente: +34600000000
Telefono fijo llamado: +34910000000
Comercial destino: AG1 - Vanesa German
Cola: C29901
Duracion de la llamada: 80 segundos
UID llamada: uid-1
PUID llamada: puid-1
Inicio: 2026-03-26T12:59:01.000Z
Fin: 2026-03-26T13:00:21.000Z
Evento: END
TEXT);

        $this->assertSame('ANSWERED', $parsed['result_raw']);
        $this->assertSame('Entrante a fijo', $parsed['type_raw']);
        $this->assertSame('+34600000000', $parsed['client_phone']);
        $this->assertSame('+34910000000', $parsed['fixed_phone']);
        $this->assertSame('AG1', $parsed['destination_agent_code']);
        $this->assertSame('Vanesa German', $parsed['destination_agent_name']);
        $this->assertSame('C29901', $parsed['queue_raw']);
        $this->assertSame(80, $parsed['parsed_duration_seconds']);
        $this->assertSame('uid-1', $parsed['uid_raw']);
        $this->assertSame('puid-1', $parsed['puid_raw']);
        $this->assertSame('END', $parsed['event_raw']);
        $this->assertNotNull($parsed['call_started_at']);
        $this->assertNotNull($parsed['call_ended_at']);
    }

    public function test_parsea_resultados_no_atendidos_y_destinos_invalidos(): void
    {
        foreach (['ABANDONED', 'FAILED', 'BUSY', 'NO ANSWER'] as $result) {
            $parsed = app(CallDescriptionParser::class)->parse("Resultado: {$result}\nComercial destino: +34601895003");

            $this->assertSame($result, $parsed['result_raw']);
            $this->assertNull($parsed['destination_agent_code']);
            $this->assertNull($parsed['destination_agent_name']);
        }

        $parsed = app(CallDescriptionParser::class)->parse("Resultado: \nComercial destino: +");
        $this->assertNull($parsed['result_raw']);
        $this->assertSame('+', $parsed['destination_raw']);
    }

    public function test_parsea_ringover_e_infiere_answered(): void
    {
        $parsed = app(CallDescriptionParser::class)->parse(<<<'TEXT'
Respondido por Carolina Gayarre atencion al cliente
Duracion de la llamada: 00:00:46
Grabacion de la llamada...
TEXT);

        $this->assertSame('ANSWERED', $parsed['result_raw']);
        $this->assertSame('Carolina Gayarre', $parsed['destination_agent_name']);
        $this->assertSame(46, $parsed['parsed_duration_seconds']);
    }
}

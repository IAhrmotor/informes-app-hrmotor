<?php

namespace Tests\Unit;

use App\Services\Reports\Calls\CallDescriptionParser;
use Tests\TestCase;

class CallDescriptionParserPollTest extends TestCase
{
    public function test_extracts_poll_value_from_supported_description_labels(): void
    {
        $parser = app(CallDescriptionParser::class);

        $this->assertSame('1', $parser->parse("poll: 1")['poll_value']);
        $this->assertSame('2', $parser->parse("Poll: 2")['poll_value']);
        $this->assertSame('1', $parser->parse("teclado: 1")['poll_value']);
        $this->assertSame('', $parser->parse("Teclado:")['poll_value']);
        $this->assertSame('3', $parser->parse("opcion: 3")['poll_value']);
        $this->assertSame('1', $parser->parse("opción: 1")['poll_value']);
        $this->assertNull($parser->parse("Resultado: ANSWERED")['poll_value']);
    }
}

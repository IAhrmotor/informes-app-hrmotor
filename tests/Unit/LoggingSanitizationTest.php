<?php

namespace Tests\Unit;

use App\Logging\SanitizeLogRecords;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LoggingSanitizationTest extends TestCase
{
    public function test_procesador_redacta_authorization_password_cookies_y_tokens(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('sanitization-test', [$handler]);
        (new SanitizeLogRecords)($logger);

        $logger->warning(
            'Authorization: Bearer synthetic-access-token Cookie=session=synthetic-session',
            [
                'password' => 'synthetic-password',
                'nested' => ['refresh_token' => 'synthetic-refresh-token'],
            ],
        );

        $record = $handler->getRecords()[0];
        $serialized = json_encode([$record->message, $record->context]);

        foreach ([
            'synthetic-access-token',
            'synthetic-session',
            'synthetic-password',
            'synthetic-refresh-token',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $this->assertStringContainsString('[redacted]', $serialized);
    }

    public function test_procesador_sanitiza_throwables_y_elimina_argumentos_de_la_traza(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('sanitization-test', [$handler]);
        (new SanitizeLogRecords)($logger);

        $logger->error('Fallo remoto.', [
            'exception' => new RuntimeException('Authorization: Bearer synthetic-exception-secret'),
        ]);

        $serialized = json_encode($handler->getRecords()[0]->context, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('synthetic-exception-secret', $serialized);
        $this->assertStringNotContainsString('"args"', $serialized);
        $this->assertStringContainsString(RuntimeException::class, $serialized);
        $this->assertStringContainsString('[redacted]', $serialized);
    }

    public function test_procesador_acepta_el_wrapper_de_logger_entregado_por_laravel(): void
    {
        $handler = new TestHandler(Level::Debug);
        $monolog = new Logger('laravel-wrapper-test', [$handler]);
        (new SanitizeLogRecords)(new IlluminateLogger($monolog));

        $monolog->warning('Authorization: Bearer synthetic-wrapper-token');

        $this->assertStringNotContainsString('synthetic-wrapper-token', $handler->getRecords()[0]->message);
        $this->assertStringContainsString('[redacted]', $handler->getRecords()[0]->message);
    }
}

<?php

namespace App\Logging;

use App\Support\IntegrationErrorSanitizer;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;

class SanitizeLogRecords
{
    public function __invoke(IlluminateLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof IlluminateLogger ? $logger->getLogger() : $logger;
        $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            message: IntegrationErrorSanitizer::sanitizeMessage($record->message, 10000),
            context: IntegrationErrorSanitizer::sanitizeContext($record->context),
        ));
    }
}

<?php

namespace App\Logging;

use App\Support\IntegrationErrorSanitizer;
use Monolog\Logger;
use Monolog\LogRecord;

class SanitizeLogRecords
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            message: IntegrationErrorSanitizer::sanitizeMessage($record->message, 10000),
            context: IntegrationErrorSanitizer::sanitizeContext($record->context),
        ));
    }
}

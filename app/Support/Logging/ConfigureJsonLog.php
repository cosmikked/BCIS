<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * "tap" class for the json channel: JSON lines plus the redaction processor.
 */
final class ConfigureJsonLog
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonLineFormatter);
            }

            // Redact at the handler so records from every code path are covered.
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new RedactSensitiveData);
            }
        }
    }
}

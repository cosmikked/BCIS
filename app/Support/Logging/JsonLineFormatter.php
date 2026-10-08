<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\RequestContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line, with request_id as a top-level field (NFR-06).
 */
final class JsonLineFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, true);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        return [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.vP'),
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'request_id' => RequestContext::requestId(),
            'message' => $record->message,
            'context' => $normalized['context'] ?? new \stdClass,
            'extra' => $normalized['extra'] ?? new \stdClass,
        ];
    }
}

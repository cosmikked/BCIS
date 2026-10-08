<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Holds the id of the request being served so log lines can carry it (NFR-06).
 * Outside a web request (artisan, queue worker) the id is "cli".
 */
final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ?? 'cli';
    }

    public static function setRequestId(?string $id): void
    {
        self::$requestId = $id;
    }
}

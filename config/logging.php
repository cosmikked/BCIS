<?php

declare(strict_types=1);

use App\Support\Logging\ConfigureJsonLog;
use Monolog\Handler\NullHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | "json" is the BCIS channel: one JSON object per line in
    | storage/logs/bcis-YYYY-MM-DD.log, rotated daily, with a request_id on every
    | line and secrets redacted (NFR-06, INV-24). Technical detail goes here,
    | never to the user (NFR-07).
    |
    */

    'default' => env('LOG_CHANNEL', 'json'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'json' => [
            'driver' => 'daily',
            'path' => storage_path('logs/bcis.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 30),
            'tap' => [ConfigureJsonLog::class],
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/bcis-emergency.log'),
        ],

    ],

];

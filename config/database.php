<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | BCIS is PostgreSQL only (PRD section 3). No other driver is configured,
    | not even for tests. A test asserts this (tests/Feature/ConfigTest.php).
    |
    */

    'default' => 'pgsql',

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | DB_USERNAME is the limited runtime role (bcis_app) when the web app runs.
    | Migrations need the owner role (bcis_owner): override DB_USERNAME and
    | DB_PASSWORD for that command only. See README and scripts/db-setup.sql.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'bcis'),
            'username' => env('DB_USERNAME', 'bcis_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // The server stamps every timestamp in Asia/Manila (INV-22).
            'timezone' => 'Asia/Manila',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];

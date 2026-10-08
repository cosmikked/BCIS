<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes the database. Refuse to run against anything
        // that is not a dedicated test database, so dev data is never lost.
        $database = (string) config('database.connections.pgsql.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against '{$database}': the database name must end in _test.");
        }
    }
}

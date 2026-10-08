<?php

declare(strict_types=1);

use Tests\TestCase;

// Feature tests boot the application. Individual files opt into
// RefreshDatabase when they need PostgreSQL (the bcis_test database).
pest()->extend(TestCase::class)->in('Feature', 'Acceptance', 'Concurrency');

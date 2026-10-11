<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\ServicePlan;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BillingData;
use Tests\Support\Users;

// AT-11 (the "two processes at once" half), FR-BIL-003, INV-09, INV-11.
//
// This is a real concurrency test: separate operating system processes, each with
// its own database connection, run `php artisan bcis:billing-generate` against the
// same PostgreSQL database. It cannot use RefreshDatabase, because that wraps a test
// in one transaction the other processes would never see. So it commits real rows
// and rebuilds the (disposable) bcis_test database before and after.

beforeEach(function (): void {
    // migrate:fresh DROPS EVERY TABLE. Never let it near the development database.
    $database = DB::connection()->getDatabaseName();
    if ($database !== 'bcis_test') {
        throw new RuntimeException("Refusing to run: the concurrency test rebuilds its database and this is '{$database}', not 'bcis_test'.");
    }

    Artisan::call('migrate:fresh');
});

afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    // Leave no committed rows behind for the transaction-wrapped tests that may run next.
    if (DB::connection()->getDatabaseName() === 'bcis_test') {
        Artisan::call('migrate:fresh');
    }
});

/** @return list<Process> */
function startGenerators(int $count, string $period): array
{
    $processes = [];

    for ($i = 0; $i < $count; $i++) {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'bcis:billing-generate', $period, '--user=owner', '--no-ansi'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => 'bcis_test',
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'LOG_CHANNEL' => 'null',
                'ARGON_MEMORY' => '1024',
                'ARGON_TIME' => '1',
            ],
        );
        $process->setTimeout(180);
        $process->start();
        $processes[] = $process;
    }

    return $processes;
}

/**
 * Blocks until every process is stuck waiting for a lock, or fails after $seconds.
 * A process that ends early (a boot error, a bad user) is reported with its output.
 *
 * @param  list<Process>  $running
 */
function waitForBlockedSessions(array $running, int $seconds = 60): void
{
    $count = count($running);
    $deadline = microtime(true) + $seconds;

    do {
        foreach ($running as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('A generator ended before the race started:
'.$process->getOutput().$process->getErrorOutput());
            }
        }

        // pg_stat_activity is frozen for the length of a transaction, and the test is inside one
        // (it holds the lock), so ask for a fresh view each time.
        DB::select('SELECT pg_stat_clear_snapshot()');

        $blocked = (int) DB::scalar(
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'"
        );

        if ($blocked >= $count) {
            return;
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    $sessions = DB::select('SELECT pid, state, wait_event_type, wait_event, left(query, 120) AS query FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid()');

    throw new RuntimeException("Only {$blocked} of {$count} processes reached the lock within {$seconds} seconds. Sessions: ".json_encode($sessions).' Output: '.json_encode(array_map(fn ($p) => [$p->getOutput(), $p->getErrorOutput(), $p->isRunning()], $running)));
}

it('AT-11: two or more processes generating the same month at the same moment bill every account exactly once', function (int $processes): void {
    $this->seed(RolePermissionSeeder::class);
    $owner = Users::make('owner', RoleCode::Owner);
    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);

    $accounts = 15;
    foreach (range(1, $accounts) as $i) {
        // Billing started long ago, so the current month is billable whatever today is.
        BillingData::activeAccount($owner, ['billing_start_date' => '2020-01-01'], $plan);
    }

    $period = CarbonImmutable::today()->format('Y-m');

    // The starting gun. While this transaction holds the lock, every process that tries to
    // create the month's cycle row waits at its very first billing statement. When all of
    // them are waiting, releasing the lock lets them race for real.
    DB::beginTransaction();
    DB::statement('LOCK TABLE billing_cycles IN ACCESS EXCLUSIVE MODE');

    try {
        $running = startGenerators($processes, $period);
        waitForBlockedSessions($running);
    } finally {
        DB::commit();
    }

    $outputs = [];
    foreach ($running as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, "A generator failed:\n".$process->getOutput().$process->getErrorOutput());
        $outputs[] = trim($process->getOutput());
    }

    $created = [];
    $alreadyBilled = [];
    foreach ($outputs as $output) {
        expect($output)->toMatch('/created=\d+ already_billed=\d+/');
        preg_match('/created=(\d+) already_billed=(\d+)/', $output, $m);
        $created[] = (int) $m[1];
        $alreadyBilled[] = (int) $m[2];
    }
    sort($created);

    // Exactly one process created the month; every other one found it already billed.
    expect($created)->toBe([...array_fill(0, $processes - 1, 0), $accounts])
        ->and(array_sum($created))->toBe($accounts);

    // The database agrees: one cycle, one non-void MONTHLY invoice per account and period.
    $perAccount = Invoice::query()
        ->where('invoice_type', InvoiceType::Monthly->value)
        ->where('status', '<>', 'VOID')
        ->selectRaw('service_account_id, billing_period, COUNT(*) AS n')
        ->groupBy('service_account_id', 'billing_period')
        ->get();

    expect(BillingCycle::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe($accounts)
        ->and($perAccount)->toHaveCount($accounts)
        ->and($perAccount->pluck('n')->map(fn ($n) => (int) $n)->unique()->all())->toBe([1])
        // Each process audited its run, whether or not it created anything.
        ->and(AuditLog::query()->where('action', AuditAction::BillingCycleGenerated->value)->count())->toBe($processes);
})->with([
    'two processes' => [2],
    'four processes' => [4],
]);

<?php

declare(strict_types=1);

use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\SubscriberAddress;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

// FR-SUB-001, FR-SUB-004, FR-SUB-008, FR-SUB-011, INV-10, INV-19, INV-21:
// the database itself, not only the application code, enforces these rules.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = Users::make('schema.tester');
});

/** Runs in a savepoint so PostgreSQL's aborted-transaction state does not leak. */
function dbRejects(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('ships the three service types with the schema', function (): void {
    expect(DB::table('service_types')->orderBy('code')->pluck('code')->all())
        ->toBe(['CABLE', 'COMBO', 'INTERNET']);
});

it('rejects a duplicate subscriber account number in the database (FR-SUB-001)', function (): void {
    Subscriber::factory()->create(['account_no' => 'SUB-000001']);

    dbRejects(fn () => Subscriber::factory()->create(['account_no' => 'SUB-000001']));
});

it('rejects a duplicate service account number in the database (FR-SUB-007)', function (): void {
    ServiceAccount::factory()->create(['account_no' => 'SVC-000001']);

    dbRejects(fn () => ServiceAccount::factory()->create(['account_no' => 'SVC-000001']));
});

it('rejects an unknown status or an impossible due day (FR-SUB-004)', function (): void {
    $subscriber = Subscriber::factory()->create();
    $account = ServiceAccount::factory()->create();

    dbRejects(fn () => DB::statement("UPDATE subscribers SET status = 'DELETED' WHERE id = ?", [$subscriber->id]));
    dbRejects(fn () => DB::statement('UPDATE subscribers SET billing_due_day = 32 WHERE id = ?', [$subscriber->id]));
    dbRejects(fn () => DB::statement("UPDATE service_accounts SET status = 'GONE' WHERE id = ?", [$account->id]));
    dbRejects(fn () => DB::statement('UPDATE service_accounts SET billing_due_day = 0 WHERE id = ?', [$account->id]));
    dbRejects(fn () => DB::statement('UPDATE service_accounts SET current_rate_centavos = 0 WHERE id = ?', [$account->id]));
});

it('keeps foreign keys restrictive so referenced rows cannot be deleted (INV-19)', function (): void {
    $account = ServiceAccount::factory()->create();
    makeEvent($account); // history now references the account

    dbRejects(fn () => DB::table('service_accounts')->where('id', $account->id)->delete());
    dbRejects(fn () => DB::table('subscribers')->where('id', $account->subscriber_id)->delete());
    dbRejects(fn () => DB::table('subscriber_addresses')->where('id', $account->service_address_id)->delete());
    dbRejects(fn () => DB::table('service_plans')->where('id', $account->plan_id)->delete());
});

it('takes account numbers from PostgreSQL sequences (INV-10)', function (): void {
    $first = (int) DB::selectOne("SELECT nextval('subscriber_no_seq') AS n")->n;
    $second = (int) DB::selectOne("SELECT nextval('subscriber_no_seq') AS n")->n;
    $service = (int) DB::selectOne("SELECT nextval('service_account_no_seq') AS n")->n;

    expect($second)->toBe($first + 1)->and($service)->toBeGreaterThan(0);
});

function makeEvent(?ServiceAccount $account = null): ServiceEvent
{
    $account ??= ServiceAccount::factory()->create();

    return ServiceEvent::create([
        'service_account_id' => $account->id,
        'event_type' => ServiceEventType::Created,
        'from_status' => null,
        'to_status' => ServiceStatus::Pending,
        'actor_user_id' => $account->created_by,
        'notes' => 'Created',
    ])->refresh();
}

describe('service_events is append-only (FR-SUB-008, INV-21)', function (): void {
    it('rejects UPDATE, DELETE and TRUNCATE in the database, even for the table owner', function (): void {
        makeEvent();

        foreach (['UPDATE service_events SET notes = \'x\'', 'DELETE FROM service_events', 'TRUNCATE service_events'] as $sql) {
            expect(fn () => DB::transaction(fn () => DB::statement($sql)))
                ->toThrow(QueryException::class, 'append-only');
        }

        expect(DB::table('service_events')->count())->toBe(1);
    });

    it('refuses updates and deletes at the model level', function (): void {
        $event = makeEvent();

        expect(fn () => $event->update(['notes' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class);
    });

    it('stamps occurred_at on the server', function (): void {
        expect(makeEvent()->occurred_at)->not->toBeNull();
    });

    it('rejects an unknown event type', function (): void {
        $account = ServiceAccount::factory()->create();

        dbRejects(fn () => DB::table('service_events')->insert([
            'service_account_id' => $account->id, 'event_type' => 'DELETED', 'actor_user_id' => $account->created_by,
        ]));
    });

    it('has no UPDATE, DELETE or TRUNCATE privilege for the runtime role', function (): void {
        if (DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'bcis_app'") === null) {
            $this->markTestSkipped('bcis_app is not provisioned (run scripts/db-setup.sql).');
        }

        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            $row = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', ['bcis_app', 'service_events', $privilege]);
            expect($row->allowed)->toBeFalse();
        }
    });
});

describe('collector_assignments (FR-SUB-011)', function (): void {
    it('needs exactly one target: an area or a service account', function (): void {
        $collector = Collector::factory()->create();
        $area = CollectionArea::factory()->create();
        $account = ServiceAccount::factory()->create();
        $base = ['collector_id' => $collector->id, 'effective_from' => '2026-10-01', 'assigned_by' => $this->user->id];

        dbRejects(fn () => DB::table('collector_assignments')->insert($base));
        dbRejects(fn () => DB::table('collector_assignments')->insert($base + [
            'collection_area_id' => $area->id, 'service_account_id' => $account->id,
        ]));

        DB::table('collector_assignments')->insert($base + ['service_account_id' => $account->id]);
        expect(DB::table('collector_assignments')->count())->toBe(1);
    });

    it('allows only one open assignment per service account but keeps closed history', function (): void {
        $collector = Collector::factory()->create();
        $account = ServiceAccount::factory()->create();
        $row = fn (string $from, ?string $to) => [
            'collector_id' => $collector->id, 'service_account_id' => $account->id,
            'effective_from' => $from, 'effective_to' => $to, 'assigned_by' => $this->user->id,
        ];

        DB::table('collector_assignments')->insert($row('2026-09-01', '2026-09-30'));
        DB::table('collector_assignments')->insert($row('2026-10-01', null));

        dbRejects(fn () => DB::table('collector_assignments')->insert($row('2026-10-15', null)));
        dbRejects(fn () => DB::table('collector_assignments')->insert($row('2026-08-10', '2026-08-01')));

        expect(DB::table('collector_assignments')->count())->toBe(2);
    });

    it('allows only one open assignment per area', function (): void {
        $collector = Collector::factory()->create();
        $area = CollectionArea::factory()->create();
        $row = ['collector_id' => $collector->id, 'collection_area_id' => $area->id, 'effective_from' => '2026-10-01', 'assigned_by' => $this->user->id];

        DB::table('collector_assignments')->insert($row);

        dbRejects(fn () => DB::table('collector_assignments')->insert($row));
    });
});

it('rejects a plan with a zero price or a negative installation fee (INV-01)', function (): void {
    $plan = ServicePlan::factory()->create();

    dbRejects(fn () => DB::statement('UPDATE service_plans SET price_centavos = 0 WHERE id = ?', [$plan->id]));
    dbRejects(fn () => DB::statement('UPDATE service_plans SET installation_fee_centavos = -1 WHERE id = ?', [$plan->id]));
});

it('stores a service address on the subscriber', function (): void {
    $address = SubscriberAddress::factory()->create();

    expect($address->subscriber)->toBeInstanceOf(Subscriber::class);
});

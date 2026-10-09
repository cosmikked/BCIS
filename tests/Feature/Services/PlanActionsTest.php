<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\CreatePlan;
use App\Domain\Services\Actions\SetPlanActive;
use App\Domain\Services\Actions\UpdatePlan;
use App\Models\AuditLog;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-005, FR-SUB-006, INV-01, INV-20, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
});

function planInput(string $type = 'INTERNET', array $overrides = []): array
{
    return [...[
        'code' => 'int-test',
        'name' => 'Internet Test',
        'service_type_id' => ServiceType::query()->where('code', $type)->value('id'),
        'price' => '999.00',
        'installation_fee' => '500.00',
        'speed_mbps' => 20,
        'channel_count' => null,
        'description' => null,
    ], ...$overrides];
}

it('creates a plan with money in centavos and an audit row (FR-SUB-005)', function (): void {
    $plan = app(CreatePlan::class)->handle($this->admin, planInput());

    expect($plan->code)->toBe('INT-TEST')
        ->and($plan->price_centavos->centavos)->toBe(99900)
        ->and($plan->installation_fee_centavos->centavos)->toBe(50000)
        ->and($plan->is_active)->toBeTrue();

    $audit = AuditLog::query()->where('action', 'plan.created')->sole();
    expect($audit->entity_id)->toBe($plan->id)->and($audit->actor_user_id)->toBe($this->admin->id);
});

it('requires the fields each plan type needs', function (string $type, array $overrides, string $errorKey): void {
    expect(fn () => app(CreatePlan::class)->handle($this->admin, planInput($type, $overrides)))
        ->toThrow(ValidationException::class);

    try {
        app(CreatePlan::class)->handle($this->admin, planInput($type, $overrides));
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($errorKey);
    }

    expect(ServicePlan::query()->count())->toBe(0);
})->with([
    'internet without speed' => ['INTERNET', ['speed_mbps' => null], 'speed_mbps'],
    'cable without channels' => ['CABLE', ['speed_mbps' => null, 'channel_count' => null], 'channel_count'],
    'combo without channels' => ['COMBO', ['speed_mbps' => 20, 'channel_count' => null], 'channel_count'],
    'combo without speed' => ['COMBO', ['speed_mbps' => null, 'channel_count' => 100], 'speed_mbps'],
]);

it('drops a field the plan type does not use', function (): void {
    $plan = app(CreatePlan::class)->handle($this->admin, planInput('CABLE', ['code' => 'cab-x', 'speed_mbps' => 50, 'channel_count' => 80]));

    expect($plan->speed_mbps)->toBeNull()->and($plan->channel_count)->toBe(80);
});

it('rejects prices that are zero, negative or have more than two decimals (INV-01)', function (string $price): void {
    expect(fn () => app(CreatePlan::class)->handle($this->admin, planInput('INTERNET', ['price' => $price])))
        ->toThrow(ValidationException::class);
})->with(['0', '0.00', '-5', '999.999', 'abc', '']);

it('rejects a duplicate plan code', function (): void {
    app(CreatePlan::class)->handle($this->admin, planInput());

    expect(fn () => app(CreatePlan::class)->handle($this->admin, planInput('INTERNET', ['code' => 'INT-TEST'])))
        ->toThrow(ValidationException::class);
});

it('leaves existing service account rates unchanged when the plan price changes (FR-SUB-006, INV-20)', function (): void {
    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $account = SubscriberData::account($this->admin, plan: $plan);
    expect($account->current_rate_centavos->centavos)->toBe(99900);

    app(UpdatePlan::class)->handle($this->admin, $plan->id, [
        'name' => $plan->name, 'price' => '1,199.00', 'installation_fee' => '500.00', 'speed_mbps' => 20,
    ], 'Annual price review');

    expect($plan->refresh()->price_centavos->centavos)->toBe(119900)
        ->and($account->refresh()->current_rate_centavos->centavos)->toBe(99900);

    // A new account copies the NEW price.
    $second = SubscriberData::account($this->admin, plan: $plan);
    expect($second->current_rate_centavos->centavos)->toBe(119900);

    $audit = AuditLog::query()->where('action', 'plan.updated')->sole();
    expect($audit->reason)->toBe('Annual price review')
        ->and($audit->old_values['price_centavos'])->toBe(99900)
        ->and($audit->new_values['price_centavos'])->toBe(119900);
});

it('does not let an update change the code or service type', function (): void {
    $plan = app(CreatePlan::class)->handle($this->admin, planInput());
    $cableId = ServiceType::query()->where('code', 'CABLE')->value('id');

    app(UpdatePlan::class)->handle($this->admin, $plan->id, [
        'code' => 'HACKED', 'service_type_id' => $cableId, 'name' => 'Renamed', 'price' => '999.00', 'speed_mbps' => 20,
    ], 'Rename');

    expect($plan->refresh()->code)->toBe('INT-TEST')
        ->and($plan->service_type_id)->toBe(ServiceType::query()->where('code', 'INTERNET')->value('id'))
        ->and($plan->name)->toBe('Renamed');
});

it('requires a reason to update or deactivate a plan', function (): void {
    $plan = ServicePlan::factory()->create();

    expect(fn () => app(UpdatePlan::class)->handle($this->admin, $plan->id, ['name' => 'X1', 'price' => '999', 'speed_mbps' => 20], ''))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SetPlanActive::class)->handle($this->admin, $plan->id, false, ' '))
        ->toThrow(ValidationException::class);
});

it('stops an inactive plan being chosen for a new service account (FR-SUB-005)', function (): void {
    $plan = ServicePlan::factory()->create();
    $existing = SubscriberData::account($this->admin, plan: $plan);

    app(SetPlanActive::class)->handle($this->admin, $plan->id, false, 'Retired');

    expect(fn () => SubscriberData::account($this->admin, plan: $plan))->toThrow(ValidationException::class);
    // The account that already uses it keeps working and keeps its rate.
    expect($existing->refresh()->plan_id)->toBe($plan->id)->and($existing->current_rate_centavos->centavos)->toBe(99900);

    app(SetPlanActive::class)->handle($this->admin, $plan->id, true, 'Back on sale');
    expect(SubscriberData::account($this->admin, plan: $plan)->plan_id)->toBe($plan->id);

    expect(AuditLog::query()->whereIn('action', ['plan.deactivated', 'plan.activated'])->count())->toBe(2);
});

it('refuses a plan that is already in the requested state', function (): void {
    $plan = ServicePlan::factory()->create();

    expect(fn () => app(SetPlanActive::class)->handle($this->admin, $plan->id, true, 'No change'))
        ->toThrow(ValidationException::class);
});

it('refuses plan changes to roles without plan.manage (AT-10)', function (RoleCode $role): void {
    $user = Users::make('someone', $role);
    $plan = ServicePlan::factory()->create();

    expect(fn () => app(CreatePlan::class)->handle($user, planInput()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdatePlan::class)->handle($user, $plan->id, ['name' => 'Xx', 'price' => '1', 'speed_mbps' => 1], 'reason'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetPlanActive::class)->handle($user, $plan->id, false, 'reason'))->toThrow(AuthorizationException::class);

    expect($plan->refresh()->is_active)->toBeTrue();
})->with([
    'cashier' => RoleCode::Cashier,
    'supervisor' => RoleCode::CollectionSupervisor,
    'auditor' => RoleCode::Auditor,
    'technician' => RoleCode::Technician,
    'viewer' => RoleCode::Viewer,
]);

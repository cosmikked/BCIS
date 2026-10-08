<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

// FR-SEC-001, FR-SEC-004, INV-24. Passwords below are throwaway test values.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    config([
        'bcis.seed.owner_username' => 'owner',
        'bcis.seed.owner_full_name' => 'Test Owner',
        'bcis.seed.owner_initial_password' => 'owner-test-secret',
        'bcis.seed.demo_password' => 'demo-test-secret',
    ]);
});

it('creates the Owner from configuration with a hashed, temporary password', function (): void {
    $this->seed(OwnerSeeder::class);

    $owner = User::query()->where('username', 'owner')->firstOrFail();

    expect($owner->password_hash)->toStartWith('$argon2id$')->not->toContain('owner-test-secret')
        ->and(Hash::check('owner-test-secret', $owner->password_hash))->toBeTrue()
        ->and($owner->must_change_password)->toBeTrue()
        ->and($owner->is_active)->toBeTrue()
        ->and($owner->hasPermission('user.manage'))->toBeTrue()
        ->and($owner->hasPermission('backup.restore'))->toBeTrue();
});

it('does not reset a real Owner password when seeded again', function (): void {
    $this->seed(OwnerSeeder::class);
    $owner = User::query()->where('username', 'owner')->firstOrFail();
    $owner->forceFill(['password_hash' => Hash::make('changed-by-owner'), 'must_change_password' => false])->save();

    $this->seed(OwnerSeeder::class);

    $owner->refresh();
    expect(Hash::check('changed-by-owner', $owner->password_hash))->toBeTrue()
        ->and($owner->must_change_password)->toBeFalse()
        ->and(User::query()->count())->toBe(1);
});

it('refuses to seed the Owner without credentials in the environment', function (): void {
    config(['bcis.seed.owner_initial_password' => null]);

    expect(fn () => $this->seed(OwnerSeeder::class))->toThrow(RuntimeException::class, 'OWNER_INITIAL_PASSWORD');
});

it('creates one demo user per non-Owner role with the demo password', function (): void {
    $this->seed(DemoUserSeeder::class);

    expect(User::query()->count())->toBe(6);

    $cashier = User::query()->where('username', 'demo.cashier')->firstOrFail();
    expect(Hash::check('demo-test-secret', $cashier->password_hash))->toBeTrue()
        ->and($cashier->hasPermission('payment.create'))->toBeTrue()
        ->and($cashier->hasPermission('user.manage'))->toBeFalse();

    $technician = User::query()->where('username', 'demo.technician')->firstOrFail();
    expect($technician->hasPermission('payment.view'))->toBeFalse()
        ->and($technician->hasPermission('billing.view'))->toBeFalse();
});

it('creates no demo users in production', function (): void {
    $this->app['env'] = 'production';

    // Called directly: db:seed itself asks for confirmation in production.
    (new DemoUserSeeder)->run();

    expect(User::query()->count())->toBe(0);
});

it('refuses to seed demo users without SEED_DEMO_PASSWORD', function (): void {
    config(['bcis.seed.demo_password' => '']);

    expect(fn () => $this->seed(DemoUserSeeder::class))->toThrow(RuntimeException::class, 'SEED_DEMO_PASSWORD');
});

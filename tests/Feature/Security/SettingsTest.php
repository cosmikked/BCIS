<?php

declare(strict_types=1);

use App\Domain\Security\Actions\AttemptLogin;
use App\Domain\Security\Actions\UpdateSetting;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\RoleCode;
use App\Domain\Security\SettingsRepository;
use App\Livewire\Admin\Settings;
use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingChange;
use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-013, FR-SEC-010, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function settingRefused(Closure $call, string $part): void
{
    try {
        $call();
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($part);

        return;
    }

    throw new RuntimeException('Expected the setting change to be refused.');
}

it('reads the default until a value is saved', function (): void {
    expect(app(SettingsRepository::class)->int('security.max_login_attempts'))->toBe(5)
        ->and(app(SettingsRepository::class)->int('security.lockout_minutes'))->toBe(15)
        ->and(app(SettingsRepository::class)->int('security.idle_lock_minutes'))->toBe(15);
});

it('saves a value and keeps old value, new value, who and when (FR-SEC-013)', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    app(UpdateSetting::class)->handle($owner, 'security.lockout_minutes', '30', 'Longer lockouts after the audit');

    expect(ApplicationSetting::query()->findOrFail('security.lockout_minutes')->value)->toBe(30)
        ->and(app(SettingsRepository::class)->int('security.lockout_minutes'))->toBe(30);

    $change = ApplicationSettingChange::query()->sole();
    expect($change->key)->toBe('security.lockout_minutes')
        ->and($change->old_value)->toBe(15)
        ->and($change->new_value)->toBe(30)
        ->and($change->changed_by)->toBe($owner->id)
        ->and($change->changed_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'settings.changed')->sole();
    expect($audit->reason)->toBe('Longer lockouts after the audit')
        ->and($audit->actor_user_id)->toBe($owner->id)
        ->and($audit->old_values)->toBe(['security.lockout_minutes' => 15])
        ->and($audit->new_values)->toBe(['security.lockout_minutes' => 30]);
});

it('records every later change as another history row', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    app(UpdateSetting::class)->handle($owner, 'security.idle_lock_minutes', 10, 'First change');
    app(UpdateSetting::class)->handle($owner, 'security.idle_lock_minutes', 20, 'Second change');

    $history = ApplicationSettingChange::query()->orderBy('id')->get();
    expect($history->pluck('old_value')->all())->toBe([15, 10])
        ->and($history->pluck('new_value')->all())->toBe([10, 20])
        ->and(ApplicationSetting::query()->count())->toBe(1);
});

it('rejects unknown keys, bad numbers, out-of-range values, no-ops and a missing reason', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $update = app(UpdateSetting::class);

    settingRefused(fn () => $update->handle($owner, 'app.debug', '1', 'Because'), 'cannot be changed');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', 'abc', 'Because'), 'whole number');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', '2.5', 'Because'), 'whole number');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', '-1', 'Because'), 'whole number');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', 1, 'Because'), 'from 3 to 20');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', 21, 'Because'), 'from 3 to 20');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', 5, 'Because'), 'already the current value');
    settingRefused(fn () => $update->handle($owner, 'security.max_login_attempts', 6, ' '), 'reason');

    expect(ApplicationSetting::query()->count())->toBe(0)
        ->and(ApplicationSettingChange::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'settings.changed')->count())->toBe(0);
});

it('changes how many failed sign-ins lock an account', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    Users::make('maria');
    app(UpdateSetting::class)->handle($owner, 'security.max_login_attempts', 3, 'Stricter sign-in');

    foreach (range(1, 2) as $i) {
        expect(fn () => app(AttemptLogin::class)->handle('maria', 'wrong-guess-'.$i, '127.0.0.1'))->toThrow(LoginFailed::class, 'Incorrect username or password.');
    }

    expect(fn () => app(AttemptLogin::class)->handle('maria', 'wrong-guess-3', '127.0.0.1'))->toThrow(LoginFailed::class, 'Too many failed attempts');
});

it('refuses everyone without settings.manage and changes nothing (AT-10)', function (): void {
    foreach ([RoleCode::Administrator, RoleCode::Cashier, RoleCode::Auditor] as $role) {
        $user = Users::make('u.'.strtolower($role->value), $role);

        expect(fn () => app(UpdateSetting::class)->handle($user, 'security.lockout_minutes', 60, 'Because'))->toThrow(AuthorizationException::class);
        $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
        Livewire::actingAs($user)->test(Settings::class)->assertForbidden();
    }

    expect(ApplicationSetting::query()->count())->toBe(0)
        ->and(ApplicationSettingChange::query()->count())->toBe(0);
});

it('shows the screen with current values and the history', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    app(UpdateSetting::class)->handle($owner, 'security.lockout_minutes', 45, 'Policy review');

    $this->actingAs($owner)->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('Lockout length')
        ->assertSee('security.lockout_minutes')
        ->assertSee('Change history');

    Livewire::actingAs($owner)->test(Settings::class)
        ->assertSee('45')
        ->assertSee('Test owner'); // the history names who made the change
});

it('changes a setting from the screen', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    Livewire::actingAs($owner)->test(Settings::class)
        ->call('select', 'security.idle_lock_minutes')
        ->assertSet('value', '15')
        ->set('value', '25')
        ->set('reason', 'Cashiers complained')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Setting saved.');

    expect(app(SettingsRepository::class)->int('security.idle_lock_minutes'))->toBe(25);
});

it('shows validation errors on the screen and keeps the old value', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    Livewire::actingAs($owner)->test(Settings::class)
        ->call('select', 'security.idle_lock_minutes')
        ->set('value', '9999')
        ->set('reason', 'Too long')
        ->call('save')
        ->assertHasErrors('value');

    expect(ApplicationSetting::query()->count())->toBe(0);
});

it('rejects a tampered setting key on the screen', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $component = Livewire::actingAs($owner)->test(Settings::class)->call('select', 'security.idle_lock_minutes');

    expect(fn () => $component->set('selectedKey', 'security.max_login_attempts'))->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::actingAs($owner)->test(Settings::class)->call('select', 'app.debug')->assertNotFound();
});

it('stops a page left open once the permission is gone, even on a plain refresh', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $page = Livewire::actingAs($owner)->test(Settings::class);

    Auth::setUser(Users::make('cashier', RoleCode::Cashier));

    $page->call('$refresh')->assertForbidden();
});

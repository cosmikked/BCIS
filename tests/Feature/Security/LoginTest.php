<?php

declare(strict_types=1);

use App\Livewire\Security\Login;
use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-001, FR-SEC-002, FR-SEC-010, INV-24

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function attemptLogin(string $username, string $password)
{
    return Livewire::test(Login::class)->set('username', $username)->set('password', $password)->call('login');
}

it('shows the sign-in page to guests and sends guests away from the app', function (): void {
    $this->get('/login')->assertOk()->assertSee('Sign in');
    $this->get('/')->assertRedirect(route('login'));
});

it('signs a user in, records it and starts a fresh session', function (): void {
    $user = Users::make('maria');
    $user->forceFill(['failed_attempts' => 2])->save();

    attemptLogin('maria', Users::PASSWORD)->assertHasNoErrors()->assertRedirect(route('home'));

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);

    $user->refresh();
    expect($user->failed_attempts)->toBe(0)
        ->and($user->last_login_at)->not->toBeNull();

    $row = AuditLog::query()->where('action', 'auth.login')->sole();
    expect($row->actor_user_id)->toBe($user->id)
        ->and(json_encode($row->new_values))->not->toContain(Users::PASSWORD);
});

it('treats the username case-insensitively', function (): void {
    Users::make('Maria');

    attemptLogin('MARIA', Users::PASSWORD)->assertRedirect(route('home'));
});

it('gives the same generic error for a wrong password, an unknown user and an inactive user', function (): void {
    Users::make('wrongpw');
    Users::make('gone', active: false);

    $messages = [
        attemptLogin('wrongpw', 'not-the-password')->errors()->get('form'),
        attemptLogin('nobody-here', 'whatever-password')->errors()->get('form'),
        attemptLogin('gone', Users::PASSWORD)->errors()->get('form'),
    ];

    expect($messages[0])->toBe(['Incorrect username or password.'])
        ->and($messages[1])->toBe($messages[0])
        ->and($messages[2])->toBe($messages[0])
        ->and(Auth::check())->toBeFalse();
});

it('clears the password field after a failed attempt', function (): void {
    Users::make('maria');

    attemptLogin('maria', 'wrong-password-here')->assertSet('password', '');
});

it('audits failures without storing the typed username', function (): void {
    $user = Users::make('maria');

    attemptLogin('maria', 'wrong-password-here');
    attemptLogin('typed-a-password-by-mistake', 'whatever-password');

    $known = AuditLog::query()->where('action', 'auth.login_failed')->where('actor_user_id', $user->id)->sole();
    $unknown = AuditLog::query()->where('action', 'auth.login_failed')->whereNull('actor_user_id')->sole();

    expect($known->new_values)->toMatchArray(['reason' => 'bad_password', 'attempts' => 1])
        ->and($unknown->new_values)->toMatchArray(['reason' => 'unknown_user'])
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('typed-a-password-by-mistake');
});

it('refuses an inactive user even with the right password and says why only in the audit log', function (): void {
    $user = Users::make('gone', active: false);

    attemptLogin('gone', Users::PASSWORD)->assertHasErrors('form');

    expect(AuditLog::query()->where('actor_user_id', $user->id)->sole()->new_values)->toMatchArray(['reason' => 'inactive_user']);
});

it('locks the account after 5 failures for 15 minutes, even for the right password', function (): void {
    $user = Users::make('maria');

    foreach (range(1, 4) as $i) {
        expect(attemptLogin('maria', 'wrong-password-'.$i)->errors()->get('form'))->toBe(['Incorrect username or password.']);
    }

    // The fifth failure locks the account.
    expect(attemptLogin('maria', 'wrong-password-5')->errors()->get('form')[0])->toContain('Too many failed attempts')->toContain('15 minutes');

    // The right password no longer works while locked.
    attemptLogin('maria', Users::PASSWORD)->assertHasErrors('form');
    expect(Auth::check())->toBeFalse();

    $user->refresh();
    expect($user->failed_attempts)->toBe(5)
        ->and($user->locked_until)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.lockout')->where('actor_user_id', $user->id)->count())->toBe(1)
        // Attempts made while locked are not audited again.
        ->and(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(5);
});

it('lets the user in again once the lockout has expired', function (): void {
    Users::make('maria');

    foreach (range(1, 5) as $i) {
        attemptLogin('maria', 'wrong-password-'.$i);
    }

    $this->travel(16)->minutes();

    attemptLogin('maria', Users::PASSWORD)->assertHasNoErrors()->assertRedirect(route('home'));
    expect(Auth::check())->toBeTrue();
});

it('shows an unknown username the same lockout, so the username cannot be probed', function (): void {
    foreach (range(1, 4) as $i) {
        attemptLogin('does-not-exist', 'wrong-password-'.$i);
    }

    expect(attemptLogin('does-not-exist', 'wrong-password-5')->errors()->get('form')[0])->toContain('Too many failed attempts');

    $lockout = AuditLog::query()->where('action', 'auth.lockout')->sole();
    expect($lockout->actor_user_id)->toBeNull()
        ->and($lockout->new_values)->toMatchArray(['known_user' => false]);
});

it('does not let a lockout of one user affect another', function (): void {
    Users::make('maria');
    Users::make('jose');

    foreach (range(1, 5) as $i) {
        attemptLogin('maria', 'wrong-password-'.$i);
    }

    attemptLogin('jose', Users::PASSWORD)->assertRedirect(route('home'));
});

it('reads the attempt limit and lockout length from settings', function (): void {
    ApplicationSetting::query()->create(['key' => 'security.max_login_attempts', 'value' => 2]);
    ApplicationSetting::query()->create(['key' => 'security.lockout_minutes', 'value' => 30]);
    Users::make('maria');

    attemptLogin('maria', 'wrong-password-1');
    $second = attemptLogin('maria', 'wrong-password-2')->errors()->get('form')[0];

    expect($second)->toContain('Too many failed attempts')->toContain('30 minutes');
});

it('keeps the lockout even when the rate limiter cache is lost', function (): void {
    Users::make('maria');

    foreach (range(1, 5) as $i) {
        attemptLogin('maria', 'wrong-password-'.$i);
    }

    // Simulates a cache flush: only users.locked_until remains.
    cache()->flush();

    attemptLogin('maria', Users::PASSWORD)->assertHasErrors('form');
    expect(Auth::check())->toBeFalse();
});

it('validates that both fields are filled in', function (): void {
    Livewire::test(Login::class)->call('login')->assertHasErrors(['username' => 'required', 'password' => 'required']);
});

it('keeps signed-in users off the sign-in page', function (): void {
    $this->actingAs(Users::make('maria'))->get('/login')->assertRedirect();
});

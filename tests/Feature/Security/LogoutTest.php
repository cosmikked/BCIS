<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

// FR-SEC-003: a copied session cookie stops working after logout.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

it('rejects a copied session cookie after logout', function (): void {
    // A real database session, as in production (SESSION_DRIVER=database).
    config(['session.driver' => 'database']);
    $user = Users::make('maria');

    $store = app('session')->driver();
    $store->setId($id = $store->getId());
    $store->put(Auth::guard('web')->getName(), $user->id);
    $store->put('security.last_activity', now()->getTimestamp());
    $store->save();

    // One PHP process serves every request in a test, so it remembers things a
    // real server would not. Forget them: each request must load the session
    // from the database by cookie, and the guard must find the user the same way.
    $forgetProcessState = function () use ($store): void {
        $store->flush();
        app('auth')->forgetGuards();
    };

    // The test client encrypts cookies itself, as a browser's cookie is encrypted
    // by the server. This is the cookie an attacker would copy.
    $name = (string) config('session.cookie');
    $request = function () use ($name, $id, $forgetProcessState) {
        $forgetProcessState();

        return $this->withCredentials()->withCookie($name, $id);
    };

    // Control: a made-up cookie does not get in, so the checks below mean something.
    $forgetProcessState();
    $this->withCredentials()->withCookie($name, str_repeat('a', 40))->get('/')->assertRedirect(route('login'));

    $request()->get('/')->assertOk();
    $request()->post('/logout')->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();

    // The attacker replays the copied cookie after the real user logged out.
    $request()->get('/')->assertRedirect(route('login'));
    expect(AuditLog::query()->where('action', 'auth.logout')->count())->toBe(1);
});

it('logs the sign-out and ends the signed-in state', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'auth.logout')->sole()->actor_user_id)->toBe($user->id);
});

it('does not accept logout from a signed-out visitor', function (): void {
    $this->post('/logout')->assertRedirect(route('login'));

    expect(AuditLog::query()->where('action', 'auth.logout')->count())->toBe(0);
});

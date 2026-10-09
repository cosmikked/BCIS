<?php

declare(strict_types=1);

use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-003, FR-UI-008, FR-SEC-012

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function idleSession(int $minutesAgo, bool $locked = false): array
{
    return array_filter([
        'security.last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        'security.locked' => $locked ?: null,
    ]);
}

it('serves a signed-in user normally while they are active', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(5))->get('/')->assertOk()->assertSee('Lock');
});

it('locks a session that has been idle past the limit and sends no page data', function (): void {
    $user = Users::make('maria');

    $response = $this->actingAs($user)->withSession(idleSession(16))->get('/');

    $response->assertStatus(423)->assertSee('Session locked')->assertDontSee('Phase 1 foundation');
    $response->assertSessionHas('security.locked', true);

    $row = AuditLog::query()->where('action', 'auth.session_locked')->sole();
    expect($row->actor_user_id)->toBe($user->id)->and($row->new_values)->toMatchArray(['reason' => 'idle']);
});

it('uses the idle limit from settings', function (): void {
    ApplicationSetting::query()->create(['key' => 'security.idle_lock_minutes', 'value' => 5]);
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(6))->get('/')->assertStatus(423);
});

it('answers Livewire and ajax calls with 423 while locked and does not run them', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(1, locked: true))
        ->withHeader('X-Livewire', 'true')
        ->postJson('/'.ltrim(Livewire::getUpdateUri(), '/'), ['components' => []])
        ->assertStatus(423)
        ->assertJson(['locked' => true]);

    $this->actingAs($user)->withSession(idleSession(1, locked: true))
        ->postJson(route('session.heartbeat'))
        ->assertOk()
        ->assertJson(['locked' => true]); // heartbeat, unlock and logout are the only routes served while locked
});

it('lets a person lock their own session and audits it', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(1))
        ->postJson(route('session.lock'))
        ->assertOk()->assertJson(['locked' => true])
        ->assertSessionHas('security.locked', true);

    expect(AuditLog::query()->where('action', 'auth.session_locked')->sole()->new_values)->toMatchArray(['reason' => 'manual']);
});

it('unlocks only with the right password, and keeps the page where it was', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(1, locked: true))
        ->postJson(route('session.unlock'), ['password' => 'wrong-password-here'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Incorrect username or password.'])
        ->assertSessionHas('security.locked', true);

    $this->actingAs($user)->withSession(idleSession(1, locked: true))
        ->postJson(route('session.unlock'), ['password' => Users::PASSWORD])
        ->assertOk()
        ->assertJson(['locked' => false])
        ->assertSessionMissing('security.locked');

    expect(AuditLog::query()->where('action', 'auth.unlock_failed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'auth.session_unlocked')->count())->toBe(1);
});

it('counts wrong unlock passwords toward the same lockout as sign-in', function (): void {
    $user = Users::make('maria');

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->withSession(idleSession(1, locked: true))
            ->postJson(route('session.unlock'), ['password' => 'wrong-guess-'.$i]);
    }

    $this->actingAs($user)->withSession(idleSession(1, locked: true))
        ->postJson(route('session.unlock'), ['password' => Users::PASSWORD])
        ->assertStatus(422)
        ->assertSessionHas('security.locked', true);

    expect($user->fresh()->locked_until)->not->toBeNull();
});

it('requires a password to unlock', function (): void {
    $this->actingAs(Users::make('maria'))->withSession(idleSession(1, locked: true))
        ->postJson(route('session.unlock'), [])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('counts a heartbeat as activity only when the browser saw the person active', function (): void {
    $user = Users::make('maria');

    $this->actingAs($user)->withSession(idleSession(10))
        ->postJson(route('session.heartbeat'), ['active' => false])
        ->assertOk()->assertJson(['locked' => false]);
    expect(session('security.last_activity'))->toBeLessThan(now()->subMinutes(9)->getTimestamp());

    $this->actingAs($user)->withSession(idleSession(10))
        ->postJson(route('session.heartbeat'), ['active' => true])
        ->assertOk();
    expect(session('security.last_activity'))->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('reports a locked session from a heartbeat when it went idle', function (): void {
    $this->actingAs(Users::make('maria'))->withSession(idleSession(20))
        ->postJson(route('session.heartbeat'), ['active' => true])
        ->assertOk()->assertJson(['locked' => true]);
});

it('signs out a user who is deactivated mid-session on their very next request', function (): void {
    $user = Users::make('maria');
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->withSession(idleSession(1))->get('/')->assertRedirect(route('login'));
    $this->actingAs($user)->withSession(idleSession(1))->postJson(route('session.heartbeat'))->assertStatus(401);
});

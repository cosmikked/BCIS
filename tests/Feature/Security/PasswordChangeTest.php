<?php

declare(strict_types=1);

use App\Domain\Security\Actions\ChangeOwnPassword;
use App\Livewire\Security\ChangePassword;
use App\Models\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-004, INV-24

uses(RefreshDatabase::class);

const NEW_PASSWORD = 'a-brand-new-long-passphrase';

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function changePassword(string $current, string $new, ?string $confirm = null)
{
    return Livewire::test(ChangePassword::class)
        ->set('current_password', $current)
        ->set('new_password', $new)
        ->set('new_password_confirmation', $confirm ?? $new)
        ->call('save');
}

it('sends a user with a temporary password to the change screen on the first request', function (): void {
    $user = Users::make('newhire', mustChange: true);

    $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
    $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('temporary password');
});

it('refuses Livewire and ajax calls from a user who still has a temporary password', function (): void {
    $user = Users::make('newhire', mustChange: true);

    $this->actingAs($user)
        ->withHeader('X-Livewire', 'true')
        ->postJson('/'.ltrim(Livewire::getUpdateUri(), '/'), ['components' => []])
        ->assertForbidden()
        ->assertJson(['message' => 'You must change your password first.']);
});

it('lets a user who changed their password in normally', function (): void {
    $this->actingAs(Users::make('maria'))->get('/')->assertOk();
});

it('changes the password, clears the temporary flag and audits without secrets', function (): void {
    $user = Users::make('newhire', mustChange: true);
    $this->actingAs($user);

    changePassword(Users::PASSWORD, NEW_PASSWORD)->assertHasNoErrors()->assertRedirect(route('home'));

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and($user->password_hash)->toStartWith('$argon2id$')
        ->and(Hash::check(NEW_PASSWORD, $user->password_hash))->toBeTrue()
        ->and(Hash::check(Users::PASSWORD, $user->password_hash))->toBeFalse();

    $row = AuditLog::query()->where('action', 'auth.password_changed')->sole();
    expect($row->actor_user_id)->toBe($user->id)
        ->and($row->new_values)->toMatchArray(['forced_change' => true])
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain(NEW_PASSWORD)->not->toContain(Users::PASSWORD);
});

it('rejects a wrong current password and counts it toward the lockout', function (): void {
    $user = Users::make('maria');
    $this->actingAs($user);

    changePassword('not-my-password', NEW_PASSWORD)->assertHasErrors('current_password');

    expect(Hash::check(Users::PASSWORD, $user->fresh()->password_hash))->toBeTrue()
        ->and($user->fresh()->failed_attempts)->toBe(1)
        ->and(AuditLog::query()->where('action', 'auth.password_change_failed')->count())->toBe(1);
});

it('locks out guessing of the current password', function (): void {
    $user = Users::make('maria');
    $this->actingAs($user);

    foreach (range(1, 5) as $i) {
        changePassword('wrong-guess-'.$i, NEW_PASSWORD);
    }

    $component = changePassword(Users::PASSWORD, NEW_PASSWORD);

    expect($component->errors()->get('current_password')[0])->toContain('Too many failed attempts')
        ->and(Hash::check(Users::PASSWORD, $user->fresh()->password_hash))->toBeTrue();
});

it('enforces the password rules in the Action', function (string $new, ?string $confirm, string $message): void {
    $this->actingAs(Users::make('maria'));

    $component = changePassword(Users::PASSWORD, $new, $confirm);

    expect($component->errors()->get('new_password')[0] ?? '')->toContain($message);
})->with([
    'too short' => ['short-pass', null, 'at least 12 characters'],
    'mismatch' => [NEW_PASSWORD, 'something-else-entirely', 'do not match'],
    'contains username' => ['my-maria-password-123', null, 'must not contain your username'],
    'same as current' => [Users::PASSWORD, null, 'must be different'],
]);

it('signs out the user on every other device but keeps this one', function (): void {
    config(['session.driver' => 'database']);
    $user = Users::make('maria');
    $other = Users::make('jose');
    $this->actingAs($user);

    foreach (['keep-me', 'other-device', 'jose-device'] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $id === 'jose-device' ? $other->id : $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    app(ChangeOwnPassword::class)
        ->handle($user, Users::PASSWORD, NEW_PASSWORD, NEW_PASSWORD, '127.0.0.1', 'keep-me');

    expect(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['jose-device', 'keep-me']);
});

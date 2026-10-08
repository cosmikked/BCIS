<?php

declare(strict_types=1);

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// FR-SEC-009, INV-18, INV-24

uses(RefreshDatabase::class);

function makeUser(string $username = 'auditor.one'): User
{
    $user = new User(['username' => $username, 'full_name' => 'Audit Subject']);
    $user->forceFill(['password_hash' => 'x'])->save();

    return $user;
}

it('writes actor, action, entity, reason, old and new values and the request id', function (): void {
    RequestContext::setRequestId('req-test-0001');
    $actor = makeUser();

    $row = app(AuditLogger::class)->record(
        'setting.changed',
        $actor,
        entityType: 'application_setting',
        entityId: 7,
        reason: 'Grace period agreed with the owner',
        oldValues: ['grace_days' => 3],
        newValues: ['grace_days' => 5],
    );

    $stored = AuditLog::query()->findOrFail($row->id);

    expect($stored->actor_user_id)->toBe($actor->id)
        ->and($stored->action)->toBe('setting.changed')
        ->and($stored->entity_type)->toBe('application_setting')
        ->and($stored->entity_id)->toBe(7)
        ->and($stored->reason)->toBe('Grace period agreed with the owner')
        ->and($stored->old_values)->toBe(['grace_days' => 3])
        ->and($stored->new_values)->toBe(['grace_days' => 5])
        ->and($stored->request_id)->toBe('req-test-0001')
        ->and($stored->occurred_at)->not->toBeNull();

    RequestContext::setRequestId(null);
});

it('accepts a null actor for events with no known user', function (): void {
    $row = app(AuditLogger::class)->record(AuditAction::AccessDenied, null);

    expect($row->fresh()->actor_user_id)->toBeNull()
        ->and($row->fresh()->action)->toBe('security.access_denied');
});

it('strips secrets from old and new values (INV-24)', function (): void {
    $row = app(AuditLogger::class)->record('user.updated', makeUser(), newValues: [
        'full_name' => 'New Name',
        'password' => 'plain-secret-1',
        'password_hash' => '$argon2id$stored',
        'api_token' => 'tok-123',
        'nested' => ['secret' => 'inner-secret'],
    ]);

    $json = (string) DB::table('audit_logs')->where('id', $row->id)->value('new_values');

    expect($json)->toContain('New Name')
        ->not->toContain('plain-secret-1')
        ->not->toContain('$argon2id$stored')
        ->not->toContain('tok-123')
        ->not->toContain('inner-secret');
});

it('commits and rolls back together with the caller transaction (INV-11, INV-18)', function (): void {
    $logger = app(AuditLogger::class);
    $actor = makeUser();

    try {
        DB::transaction(function () use ($logger, $actor): void {
            $logger->record('payment.posted', $actor);
            throw new RuntimeException('posting failed after the audit write');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AuditLog::query()->where('action', 'payment.posted')->count())->toBe(0);

    DB::transaction(fn () => $logger->record('payment.posted', $actor));

    expect(AuditLog::query()->where('action', 'payment.posted')->count())->toBe(1);
});

it('offers no way to update or delete a row it wrote', function (): void {
    $methods = get_class_methods(AuditLogger::class);

    expect($methods)->toBe(['record']);
});

<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\PasswordAttemptGuard;
use App\Models\User;

/**
 * Checks sign-in credentials (FR-SEC-001, FR-SEC-002). Returns the user on
 * success; the caller (the Livewire login page) then starts the session.
 * Counters, lockout and audit rows are handled by PasswordAttemptGuard.
 */
final class AttemptLogin
{
    public function __construct(private readonly PasswordAttemptGuard $guard) {}

    /** @throws LoginFailed */
    public function handle(string $username, string $password, string $ip): User
    {
        return $this->guard->attempt($username, $password, $ip, AuditAction::Login, AuditAction::LoginFailed);
    }
}

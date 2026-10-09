<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\SessionLockState;
use App\Models\User;

/** Locks the current session, on request or after idle time (FR-SEC-003). */
final class LockSession
{
    public function __construct(
        private readonly SessionLockState $state,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, string $reason): void
    {
        if ($this->state->isLocked()) {
            return;
        }

        $this->state->lock();
        $this->audit->record(AuditAction::SessionLocked, $user, newValues: ['reason' => $reason]);
    }
}

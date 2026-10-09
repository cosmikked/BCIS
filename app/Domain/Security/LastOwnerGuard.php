<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The last active Owner cannot be deactivated or lose the Owner role (PRD
 * section 2, FR-SEC-012). This is the one rule that names a role, on purpose:
 * "Owner" is the account that can always get back in.
 *
 * Call inside the Action's transaction. The active Owners are row-locked, so two
 * Owners removing each other at the same moment cannot both succeed.
 */
final class LastOwnerGuard
{
    /** @throws ValidationException */
    public function assertNotLastActiveOwner(User $target): void
    {
        $activeOwnerIds = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('code', RoleCode::Owner->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeOwnerIds->count() <= 1 && $activeOwnerIds->contains($target->id)) {
            throw ValidationException::withMessages([
                'form' => 'This is the last active Owner. Make another user an Owner first.',
            ]);
        }
    }
}

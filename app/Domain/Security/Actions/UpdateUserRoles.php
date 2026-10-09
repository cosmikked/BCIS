<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\LastOwnerGuard;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\Reason;
use App\Domain\Security\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Replaces the roles of a user (FR-SEC-010: permission changes are audited). */
final class UpdateUserRoles
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
        private readonly LastOwnerGuard $lastOwner,
    ) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $targetId, array $roleIds, string $reason): void
    {
        Gate::forUser($actor)->authorize('user.manage');
        $reason = Reason::require($reason);

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            throw ValidationException::withMessages(['role_ids' => 'Choose at least one role.']);
        }

        DB::transaction(function () use ($actor, $targetId, $roleIds, $reason): void {
            // Re-fetch under lock: the id came from the browser (INV-17).
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            $this->rules->assertCanManage($actor, $target);

            $newRoles = Role::query()->whereIn('id', $roleIds)->orderBy('id')->get();
            if ($newRoles->count() !== count($roleIds)) {
                throw ValidationException::withMessages(['role_ids' => 'One of the chosen roles does not exist.']);
            }

            $oldRoles = $target->roles()->orderBy('roles.id')->get();
            $oldIds = $oldRoles->pluck('id')->all();

            $added = $newRoles->reject(fn (Role $r): bool => in_array($r->id, $oldIds, true));
            $this->rules->assertCanGrantRoles($actor, $added);

            $losesOwner = $oldRoles->contains('code', RoleCode::Owner->value)
                && ! $newRoles->contains('code', RoleCode::Owner->value);
            if ($losesOwner && $target->is_active) {
                $this->lastOwner->assertNotLastActiveOwner($target);
            }

            $target->roles()->sync($newRoles->mapWithKeys(
                fn (Role $r): array => [$r->id => in_array($r->id, $oldIds, true) ? [] : ['assigned_by' => $actor->id]],
            )->all());

            $this->audit->record(AuditAction::UserRolesChanged, $actor, 'user', $target->id, $reason,
                oldValues: ['roles' => $oldRoles->pluck('code')->all()],
                newValues: ['roles' => $newRoles->pluck('code')->all()],
            );
        });
    }
}

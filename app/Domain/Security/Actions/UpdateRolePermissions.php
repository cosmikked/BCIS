<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Permission as PermissionCode;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\Reason;
use App\Domain\Security\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes which permissions a role holds (FR-SEC-010). Rules:
 *  - you cannot grant a permission you do not hold (PRD section 2);
 *  - the Owner role always holds every permission and cannot be edited, so the
 *    system can never be locked out of its own administration.
 * Reseeding (RolePermissionSeeder) restores the PRD matrix.
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
    ) {}

    /**
     * @param  list<string>  $codes
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $roleId, array $codes, string $reason): void
    {
        Gate::forUser($actor)->authorize('role.manage');
        $reason = Reason::require($reason);

        $codes = array_values(array_unique($codes));
        $unknown = array_diff($codes, PermissionCode::codes());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['codes' => 'Unknown permission: '.implode(', ', $unknown).'.']);
        }

        DB::transaction(function () use ($actor, $roleId, $codes, $reason): void {
            $role = Role::query()->whereKey($roleId)->lockForUpdate()->firstOrFail();

            if ($role->code === RoleCode::Owner->value) {
                throw ValidationException::withMessages(['form' => 'The Owner role always holds every permission and cannot be edited.']);
            }

            $old = $role->permissions()->pluck('code')->sort()->values()->all();
            $added = array_values(array_diff($codes, $old));
            $removed = array_values(array_diff($old, $codes));

            $this->rules->assertCanGrantPermissions($actor, $added);

            $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id')->all());

            sort($codes);
            $this->audit->record(AuditAction::RolePermissionsChanged, $actor, 'role', $role->id, $reason,
                oldValues: ['permissions' => $old],
                newValues: ['permissions' => $codes, 'added' => $added, 'removed' => $removed],
            );
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Security\Actions\UpdateRolePermissions;
use App\Domain\Security\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Role/permission matrix (PRD section 2) with editing of one role at a time
 * (FR-SEC-010). The matrix is also the screen to show at the checkpoint.
 * Greyed-out boxes are a convenience only: UpdateRolePermissions refuses to
 * grant a permission the signed-in user does not hold.
 */
#[Title('Roles & Permissions')]
class Roles extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $selectedRoleId = null;

    #[Locked]
    public string $status = '';

    /** @var list<string> Permission codes ticked in the edit panel. Untrusted input. */
    public array $codes = [];

    public string $reason = '';

    // Runs on every request (page load, action, $refresh, polling), so a page
    // left open cannot keep reading the permission matrix after the permission is gone.
    public function boot(): void
    {
        $this->authorize('role.manage');
    }

    public function mount(): void
    {
        $this->authorize('role.manage');
    }

    public function select(int $roleId): void
    {
        $this->authorize('role.manage');

        $role = Role::query()->findOrFail($roleId);

        $this->selectedRoleId = $role->id;
        $this->codes = $role->permissions()->pluck('code')->all();
        $this->reason = '';
        $this->status = '';
        $this->resetErrorBag();
    }

    public function save(UpdateRolePermissions $update): void
    {
        $this->authorize('role.manage');
        abort_if($this->selectedRoleId === null, 422, 'Choose a role first.');

        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);

        $update->handle($actor, $this->selectedRoleId, array_map('strval', $this->codes), $this->reason);

        $this->reason = '';
        $this->status = 'Permissions updated.';
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $actor = auth()->user();
        $actor?->forgetPermissionCache();

        $roles = Role::query()->with('permissions')->orderBy('id')->get();

        return view('livewire.admin.roles', [
            'roles' => $roles,
            'permissions' => Permission::query()->orderBy('code')->get(),
            'held' => $actor instanceof User ? $actor->permissionCodes() : [],
            'selected' => $roles->firstWhere('id', $this->selectedRoleId),
            'ownerCode' => RoleCode::Owner->value,
        ]);
    }
}

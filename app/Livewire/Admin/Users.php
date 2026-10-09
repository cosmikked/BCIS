<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Security\Actions\CreateUser;
use App\Domain\Security\Actions\DeactivateUser;
use App\Domain\Security\Actions\ReactivateUser;
use App\Domain\Security\Actions\ResetUserPassword;
use App\Domain\Security\Actions\UnlockUser;
use App\Domain\Security\Actions\UpdateUserRoles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Owner screen: create users, assign roles, deactivate, reset password, unlock
 * (FR-SEC-002, FR-SEC-012). Thin: every rule lives in the Actions.
 *
 * Public properties are untrusted input (INV-17, FR-SEC-007): the selected user id
 * is #[Locked], role ids are re-validated in the Action, and every method
 * authorizes before it does anything.
 */
#[Title('Users')]
class Users extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $selectedId = null;

    /** One-time password shown after create or reset. Cleared by dismissPassword(). */
    #[Locked]
    public ?string $revealedPassword = null;

    #[Locked]
    public ?string $revealedFor = null;

    /** Result message of the last change, shown above the table. */
    #[Locked]
    public string $status = '';

    public string $newUsername = '';

    public string $newFullName = '';

    /** @var list<int|string> */
    public array $newRoleIds = [];

    /** @var list<int|string> */
    public array $roleIds = [];

    public string $reason = '';

    // Runs on every request (page load, action, $refresh, polling), so a page
    // left open cannot keep reading user data after the permission is gone.
    public function boot(): void
    {
        $this->authorize('user.manage');
    }

    public function mount(): void
    {
        $this->authorize('user.manage');
    }

    public function select(int $userId): void
    {
        $this->authorize('user.manage');

        $user = User::query()->with('roles')->findOrFail($userId);

        $this->selectedId = $user->id;
        $this->roleIds = $user->roles->pluck('id')->all();
        $this->reason = '';
        $this->revealedPassword = null;
        $this->status = '';
        $this->resetErrorBag();
    }

    public function create(CreateUser $createUser): void
    {
        $this->authorize('user.manage');

        $created = $createUser->handle($this->actor(), $this->newUsername, $this->newFullName, array_map('intval', $this->newRoleIds));

        $this->revealedPassword = $created->temporaryPassword;
        $this->revealedFor = $created->user->username;
        $this->reset(['newUsername', 'newFullName', 'newRoleIds']);
        $this->resetErrorBag();
    }

    public function saveRoles(UpdateUserRoles $update): void
    {
        $this->authorize('user.manage');

        $update->handle($this->actor(), $this->selected(), array_map('intval', $this->roleIds), $this->reason);

        $this->afterChange('Roles updated.');
    }

    public function deactivate(DeactivateUser $deactivate): void
    {
        $this->authorize('user.manage');

        $deactivate->handle($this->actor(), $this->selected(), $this->reason);

        $this->afterChange('Account deactivated.');
    }

    public function reactivate(ReactivateUser $reactivate): void
    {
        $this->authorize('user.manage');

        $reactivate->handle($this->actor(), $this->selected(), $this->reason);

        $this->afterChange('Account reactivated.');
    }

    public function resetPassword(ResetUserPassword $reset): void
    {
        $this->authorize('user.manage');

        $userId = $this->selected();
        $this->revealedPassword = $reset->handle($this->actor(), $userId, $this->reason);
        $this->revealedFor = User::query()->whereKey($userId)->value('username');

        $this->afterChange(null);
    }

    public function unlock(UnlockUser $unlock): void
    {
        $this->authorize('user.manage');

        $unlock->handle($this->actor(), $this->selected(), $this->reason);

        $this->afterChange('Account unlocked.');
    }

    public function dismissPassword(): void
    {
        $this->authorize('user.manage');

        $this->revealedPassword = null;
        $this->revealedFor = null;
    }

    public function render(): mixed
    {
        $selected = $this->selectedId !== null ? User::query()->with('roles')->find($this->selectedId) : null;

        return view('livewire.admin.users', [
            'users' => User::query()->with('roles')->orderBy('username')->get(),
            'roles' => Role::query()->orderBy('id')->get(),
            'selected' => $selected,
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function selected(): int
    {
        abort_if($this->selectedId === null, 422, 'Choose a user first.');

        return $this->selectedId;
    }

    private function afterChange(?string $message): void
    {
        $this->reason = '';
        $this->resetErrorBag();

        if ($message !== null) {
            $this->revealedPassword = null;
            $this->status = $message;
        }

        $user = User::query()->with('roles')->findOrFail($this->selected());
        $this->roleIds = $user->roles->pluck('id')->all();
    }
}

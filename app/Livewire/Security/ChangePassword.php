<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use App\Domain\Security\Actions\ChangeOwnPassword;
use App\Domain\Security\SkipsAuthorization;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Change my own password. Also the only screen a user with a temporary password
 * can reach (FR-SEC-004). Changing your own password needs no permission beyond
 * being signed in; the Action re-checks the current password.
 */
#[Layout('components.layouts.guest')]
#[Title('Change password')]
#[SkipsAuthorization('Every signed-in user may change their own password. The current password is re-checked in the Action.')]
class ChangePassword extends Component
{
    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public function save(ChangeOwnPassword $change): mixed
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        $this->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'max:255'],
            'new_password_confirmation' => ['required', 'string', 'max:255'],
        ]);

        try {
            $change->handle(
                $user,
                $this->current_password,
                $this->new_password,
                $this->new_password_confirmation,
                (string) request()->ip(),
                session()->getId(),
            );
        } finally {
            // Never leave typed passwords in the component state.
            $this->reset(['current_password', 'new_password', 'new_password_confirmation']);
        }

        session()->flash('status', 'Your password has been changed.');

        return $this->redirect(route('home'));
    }

    public function render(): mixed
    {
        return view('livewire.security.change-password', [
            'forced' => (bool) auth()->user()?->must_change_password,
            'minLength' => (int) config('bcis.password_min_length'),
        ]);
    }
}

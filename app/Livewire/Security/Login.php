<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use App\Domain\Security\Actions\AttemptLogin;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\SessionLockState;
use App\Domain\Security\SkipsAuthorization;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sign-in page. Thin: validation of shape here, every security decision in
 * AttemptLogin. The error is the same for a wrong password, an unknown user and
 * an inactive user (FR-SEC-002).
 */
#[Layout('components.layouts.guest')]
#[Title('Sign in')]
#[SkipsAuthorization('Public sign-in page: nobody is signed in yet, so there is no permission to check.')]
class Login extends Component
{
    public string $username = '';

    public string $password = '';

    public function login(AttemptLogin $attempt, SessionLockState $lockState): mixed
    {
        $this->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $user = $attempt->handle($this->username, $this->password, (string) request()->ip());
        } catch (LoginFailed $e) {
            $this->reset('password');
            $this->addError('form', $e->getMessage());

            return null;
        }

        $this->reset('password');

        Auth::guard('web')->login($user);
        // A fresh session id on privilege change defeats session fixation.
        session()->regenerate();
        $lockState->unlock();

        return $this->redirectIntended(route('home'));
    }

    public function render(): mixed
    {
        return view('livewire.security.login');
    }
}

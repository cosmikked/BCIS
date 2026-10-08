<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Fixture: every public method authorizes, in each accepted style. */
final class AuthorizedComponent extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('payment.view');
    }

    public function save(): void
    {
        Gate::authorize('payment.create');
    }

    public function peek(): bool
    {
        return auth()->user()?->can('payment.view') ?? false;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

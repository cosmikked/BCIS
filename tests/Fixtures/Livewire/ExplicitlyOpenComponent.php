<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use App\Domain\Security\SkipsAuthorization;
use Livewire\Component;

/** Fixture: no permission needed, and it says so with a reason. */
final class ExplicitlyOpenComponent extends Component
{
    #[SkipsAuthorization('Any logged-in user may end their own session.')]
    public function logout(): void
    {
        //
    }

    #[SkipsAuthorization('Renders no data, only a button.')]
    public function render(): string
    {
        return '<div></div>';
    }
}

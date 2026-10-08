<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Component;

/** Fixture: deliberately forgets to authorize. The inventory must flag it. */
final class UnauthorizedComponent extends Component
{
    public function mount(): void
    {
        // no check
    }

    public function deleteEverything(): void
    {
        // no check
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

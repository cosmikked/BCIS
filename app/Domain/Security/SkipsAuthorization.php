<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Attribute;

/**
 * Explicit marker for a Livewire component (or one of its public methods) that
 * needs no permission check, for example a pure logout button. The inventory
 * test (TO-08) accepts it, and the reason makes the exception reviewable.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipsAuthorization
{
    public function __construct(public readonly string $reason) {}
}

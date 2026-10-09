<?php

declare(strict_types=1);

namespace App\Domain\Security\Data;

use App\Models\User;

/** A new account and its one-time password. Show the password once; it is not kept anywhere. */
final class CreatedUser
{
    public function __construct(
        public readonly User $user,
        public readonly string $temporaryPassword,
    ) {}
}

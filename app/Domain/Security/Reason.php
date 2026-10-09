<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Validation\ValidationException;

/** Administrative changes carry a written reason, kept in the audit log (FR-SEC-009). */
final class Reason
{
    /** @throws ValidationException */
    public static function require(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason of at least 3 characters.']);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason must be 500 characters or fewer.']);
        }

        return $reason;
    }
}

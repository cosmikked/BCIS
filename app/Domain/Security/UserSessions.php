<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Support\Facades\DB;

/** Ends a user's sessions on the server, for example after a password reset or deactivation. */
final class UserSessions
{
    public static function revokeAll(int $userId, ?string $exceptSessionId = null): void
    {
        // Other drivers (array in tests, cookie) keep nothing to delete here.
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}

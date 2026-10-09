<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the session on the server (FR-SEC-003). invalidate() deletes the session
 * row, so a copied session cookie stops working the moment this runs.
 */
final class LogoutUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(?User $user, Session $session, string $reason = 'logout'): void
    {
        if ($user !== null) {
            $this->audit->record(AuditAction::Logout, $user, newValues: ['reason' => $reason]);
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }
}

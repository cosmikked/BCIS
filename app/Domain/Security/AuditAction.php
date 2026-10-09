<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Action names written to audit_logs.action (FR-SEC-010). Each security slice
 * adds the events it produces, so the viewer can offer them as a filter.
 */
enum AuditAction: string
{
    case AccessDenied = 'security.access_denied';
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Lockout = 'auth.lockout';
    case Logout = 'auth.logout';
    case SessionLocked = 'auth.session_locked';
    case SessionUnlocked = 'auth.session_unlocked';
    case UnlockFailed = 'auth.unlock_failed';
    case PasswordChanged = 'auth.password_changed';
    case PasswordChangeFailed = 'auth.password_change_failed';
}

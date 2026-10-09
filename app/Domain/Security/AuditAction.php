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
    case UserCreated = 'user.created';
    case UserRolesChanged = 'user.roles_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserPasswordReset = 'user.password_reset';
    case UserUnlocked = 'user.unlocked';
    case RolePermissionsChanged = 'role.permissions_changed';
    case SettingChanged = 'settings.changed';
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanActivated = 'plan.activated';
    case PlanDeactivated = 'plan.deactivated';
    case SubscriberCreated = 'subscriber.created';
    case SubscriberUpdated = 'subscriber.updated';
    case SubscriberStatusChanged = 'subscriber.status_changed';
    case ServiceAccountCreated = 'service_account.created';
    case ServiceStatusChanged = 'service_account.status_changed';
    case CollectorAssigned = 'collector.assigned';
}

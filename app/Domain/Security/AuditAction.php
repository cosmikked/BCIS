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
}

<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Logging\RedactSensitiveData;
use App\Support\RequestContext;

/**
 * The only way audit rows are written (INV-18). Insert-only: there is no update
 * or delete method, and the database refuses both anyway.
 *
 * A posting Action calls record() INSIDE its own DB::transaction, so the audit
 * row commits or rolls back together with the money it describes. The logger
 * deliberately opens no transaction of its own for that reason. Security events
 * with no financial effect (a failed login, a denied request) may call it
 * outside a transaction.
 *
 * Old and new values are scrubbed of password, token and similar keys before
 * they are stored (INV-24). Callers should still never pass secrets in.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        AuditAction|string $action,
        ?User $actor,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $reason = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $redactor = new RedactSensitiveData;

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action instanceof AuditAction ? $action->value : $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
            'old_values' => $oldValues === null ? null : $redactor->redactData($oldValues),
            'new_values' => $newValues === null ? null : $redactor->redactData($newValues),
            'request_id' => RequestContext::requestId(),
        ]);
    }
}

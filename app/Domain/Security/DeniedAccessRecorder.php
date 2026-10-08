<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Writes the audit row for a refused request (FR-SEC-010). Shared by the
 * `permission` route middleware and the exception handler, which catches
 * AuthorizationException thrown by Gates, Policies and Livewire actions.
 */
final class DeniedAccessRecorder
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  list<string>  $permissions  Codes the route required, when known. */
    public function record(Request $request, ?User $user, array $permissions = []): void
    {
        try {
            $this->audit->record(
                AuditAction::AccessDenied,
                $user,
                entityType: 'route',
                // Path only, never the query string: it can carry tokens (INV-24).
                newValues: array_filter([
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'route' => $request->route()?->getName(),
                    'permissions' => $permissions === [] ? null : $permissions,
                ], fn ($v) => $v !== null),
            );
        } catch (Throwable $e) {
            // A failed audit write must not turn a 403 into a 500 or hide it.
            report($e);
        }
    }
}

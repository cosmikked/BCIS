<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a service account to a new status (FR-SUB-008, INV-21). One
 * transaction writes the new status, one append-only service_events row (actor,
 * time and reason) and one audit row. The account row is locked first, so two
 * people cannot suspend and terminate the same account at once.
 *
 * Who may do what (a flagged decision, the PRD only lists the permissions):
 *  - service.manage may make any allowed move;
 *  - service.execute (the Technician) may only complete an installation,
 *    PENDING -> ACTIVE.
 * Suspension and termination approval workflows arrive in Phase 7.
 */
final class ChangeServiceStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $serviceAccountId, ServiceStatus $to, string $reason): ServiceAccount
    {
        if (! $actor->hasPermission('service.manage') && ! $actor->hasPermission('service.execute')) {
            throw new AuthorizationException;
        }

        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $serviceAccountId, $to, $reason): ServiceAccount {
            $account = ServiceAccount::query()->whereKey($serviceAccountId)->lockForUpdate()->firstOrFail();
            $from = $account->status;

            $installationOnly = ! $actor->hasPermission('service.manage');
            if ($installationOnly && ! ($from === ServiceStatus::Pending && $to === ServiceStatus::Active)) {
                throw new AuthorizationException;
            }

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "A {$from->label()} service cannot become {$to->label()}.",
                ]);
            }

            $values = ['status' => $to];
            // The first activation sets the activation date; a reconnection keeps it.
            if ($to === ServiceStatus::Active && $account->activation_date === null) {
                $values['activation_date'] = now()->toDateString();
            }
            $account->forceFill($values)->save();

            ServiceEvent::query()->create([
                'service_account_id' => $account->id,
                'event_type' => $from->eventTypeFor($to),
                'from_status' => $from,
                'to_status' => $to,
                'actor_user_id' => $actor->id,
                'notes' => $reason,
            ]);

            $this->audit->record(AuditAction::ServiceStatusChanged, $actor, 'service_account', $account->id, $reason,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
            );

            return $account;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\ServiceAccount;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a subscriber between ACTIVE, INACTIVE, TERMINATED and ARCHIVED
 * (FR-SUB-004). The audit row, with the reason and old and new status, is
 * written in the same transaction. Subscribers are never deleted (INV-19).
 *
 * A subscriber with a service account that is not TERMINATED cannot be
 * terminated: end the services first, so no live service is left without an
 * owner. CreateServiceAccount locks the same subscriber row, so a service
 * cannot be added while this check runs.
 */
final class ChangeSubscriberStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $subscriberId, SubscriberStatus $to, string $reason): Subscriber
    {
        Gate::forUser($actor)->authorize('subscriber.manage');
        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $subscriberId, $to, $reason): Subscriber {
            $subscriber = Subscriber::query()->whereKey($subscriberId)->lockForUpdate()->firstOrFail();
            $from = $subscriber->status;

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "A {$from->label()} subscriber cannot become {$to->label()}.",
                ]);
            }

            if ($to === SubscriberStatus::Terminated) {
                $open = ServiceAccount::query()
                    ->where('subscriber_id', $subscriber->id)
                    ->where('status', '!=', ServiceStatus::Terminated->value)
                    ->count();

                if ($open > 0) {
                    throw ValidationException::withMessages([
                        'status' => 'Terminate the subscriber\'s service accounts first.',
                    ]);
                }
            }

            $subscriber->forceFill(['status' => $to])->save();

            $this->audit->record(AuditAction::SubscriberStatusChanged, $actor, 'subscriber', $subscriber->id, $reason,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
            );

            return $subscriber;
        });
    }
}

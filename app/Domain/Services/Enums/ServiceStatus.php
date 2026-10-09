<?php

declare(strict_types=1);

namespace App\Domain\Services\Enums;

/** FR-SUB-007, FR-SUB-008. Only ACTIVE accounts are billed (FR-BIL-002, Phase 4). */
enum ServiceStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Terminated = 'TERMINATED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    /**
     * Allowed moves (flagged in the commit: the PRD names the statuses but not
     * the transitions). PENDING -> TERMINATED is a cancelled installation.
     * TERMINATED is final. Phase 7 puts an approval step in front of suspend
     * and terminate.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Terminated],
            self::Active => [self::Suspended, self::Terminated],
            self::Suspended => [self::Active, self::Terminated],
            self::Terminated => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** The service event a move to $next records. */
    public function eventTypeFor(self $next): ServiceEventType
    {
        return match ($next) {
            self::Active => $this === self::Suspended ? ServiceEventType::Reconnected : ServiceEventType::Activated,
            self::Suspended => ServiceEventType::Suspended,
            self::Terminated => ServiceEventType::Terminated,
            self::Pending => ServiceEventType::Created,
        };
    }
}

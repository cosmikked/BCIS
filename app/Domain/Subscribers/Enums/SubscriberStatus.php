<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Enums;

/** FR-SUB-004. Subscribers are never deleted; leaving is a status. */
enum SubscriberStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Terminated = 'TERMINATED';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    /**
     * Allowed moves (a decision flagged in the commit: the PRD names the four
     * statuses but no transitions). TERMINATED and ARCHIVED are one-way.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Inactive, self::Terminated],
            self::Inactive => [self::Active, self::Terminated],
            self::Terminated => [self::Archived],
            self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}

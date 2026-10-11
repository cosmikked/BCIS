<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** FR-BIL-002. Why an account is not billed for a period; shown in the preview. */
enum SkipReason: string
{
    case Pending = 'pending';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case SubscriberInactive = 'subscriber_inactive';
    case NotStarted = 'not_started';
    case AlreadyBilled = 'already_billed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending installation',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
            self::SubscriberInactive => 'Subscriber not active',
            self::NotStarted => 'Billing has not started',
            self::AlreadyBilled => 'Already billed',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Services\Enums;

/** service_events.event_type (FR-SUB-008, INV-21). */
enum ServiceEventType: string
{
    case Created = 'CREATED';
    case Activated = 'ACTIVATED';
    case Suspended = 'SUSPENDED';
    case Reconnected = 'RECONNECTED';
    case Terminated = 'TERMINATED';
    case PlanChanged = 'PLAN_CHANGED';
    case CollectorChanged = 'COLLECTOR_CHANGED';

    public function label(): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $this->value)));
    }
}

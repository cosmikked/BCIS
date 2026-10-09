<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Enums;

enum AddressType: string
{
    case Billing = 'BILLING';
    case Service = 'SERVICE';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

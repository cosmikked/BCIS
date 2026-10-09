<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Enums;

enum ContactType: string
{
    case Mobile = 'MOBILE';
    case Email = 'EMAIL';
    case Other = 'OTHER';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

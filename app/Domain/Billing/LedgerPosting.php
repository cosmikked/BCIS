<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;

/** One ledger row to write. Exactly one of $debit and $credit is positive. */
final readonly class LedgerPosting
{
    public function __construct(
        public LedgerEntryType $type,
        public int $subscriberId,
        public ?int $serviceAccountId,
        public CarbonInterface $entryDate,
        public Money $debit,
        public Money $credit,
        public string $description,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?string $referenceNo,
        public User $actor,
    ) {}
}

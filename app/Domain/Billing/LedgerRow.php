<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/** One ledger line as shown to the user, with the balance after it (FR-BIL-016). */
final readonly class LedgerRow
{
    public function __construct(
        public int $id,
        public CarbonImmutable $entryDate,
        public LedgerEntryType $type,
        public string $description,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?string $referenceNo,
        public Money $debit,
        public Money $credit,
        public Money $runningBalance,
    ) {}
}

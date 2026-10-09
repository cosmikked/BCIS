<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use Illuminate\Support\Facades\DB;

/**
 * Subscriber and service account numbers (FR-SUB-001, FR-SUB-007, INV-10).
 * Prefix plus a zero-padded PostgreSQL sequence value, never max + 1. A number
 * taken by a transaction that later rolls back is simply skipped: gaps are fine,
 * reuse is not. The unique index on account_no is the final duplicate guard.
 */
final class AccountNumberGenerator
{
    public function nextSubscriberNo(): string
    {
        return $this->format((string) config('bcis.account_numbers.subscriber_prefix'), $this->next('subscriber_no_seq'));
    }

    public function nextServiceAccountNo(): string
    {
        return $this->format((string) config('bcis.account_numbers.service_prefix'), $this->next('service_account_no_seq'));
    }

    /** $sequence is one of the two constants above, never user input. */
    private function next(string $sequence): int
    {
        $row = DB::selectOne("SELECT nextval('{$sequence}') AS n");

        return (int) $row->n;
    }

    private function format(string $prefix, int $value): string
    {
        $digits = (int) config('bcis.account_numbers.digits', 6);

        return $prefix.str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }
}

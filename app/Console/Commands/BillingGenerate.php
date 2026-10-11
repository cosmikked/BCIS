<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Generates the DRAFT invoices of one month from the command line (FR-BIL-001,
 * FR-BIL-003). It is a thin wrapper over GenerateBillingCycle: the same
 * permission check, the same transaction, the same audit row. It exists so that
 * the duplicate-billing guarantee (AT-11) can be tested with two real operating
 * system processes, and so an operator can regenerate a draft month from a shell.
 * It never finalizes: that stays a deliberate step on the Generate Billing screen.
 */
class BillingGenerate extends Command
{
    protected $signature = 'bcis:billing-generate
                            {period : The billing month, for example 2026-10}
                            {--user= : Username of the user who runs it; needs billing.generate}';

    protected $description = 'Generate the draft invoices of one billing month (never finalizes)';

    public function handle(GenerateBillingCycle $generate): int
    {
        $username = (string) $this->option('user');
        $actor = $username === '' ? null : User::query()->where('username', $username)->where('is_active', true)->first();

        if ($actor === null) {
            $this->error('Give --user=<username> of an active user.');

            return self::FAILURE;
        }

        try {
            $result = $generate->handle($actor, (string) $this->argument('period'));
        } catch (ValidationException $e) {
            $this->error(implode(' ', array_merge(...array_values($e->errors()))));

            return self::FAILURE;
        } catch (AuthorizationException) {
            $this->error("{$actor->username} is not allowed to generate billing.");

            return self::FAILURE;
        }

        $this->line("created={$result->created} already_billed={$result->alreadyBilled} ineligible={$result->ineligible}");

        return self::SUCCESS;
    }
}

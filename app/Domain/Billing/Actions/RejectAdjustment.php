<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Models\Adjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns down a pending adjustment or void (FR-BIL-009). Same maker-checker rule
 * as approval: not the requester. A rejection needs a written reason, writes no
 * ledger entry and leaves the invoice exactly as it was. The rejected request is
 * kept for good.
 */
final class RejectAdjustment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $adjustmentId, string $reason): Adjustment
    {
        $kind = Adjustment::query()->whereKey($adjustmentId)->firstOrFail()->kind;
        Gate::forUser($actor)->authorize($kind->permission()->value);

        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $adjustmentId, $reason): Adjustment {
            $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages(['adjustment' => 'This request has already been decided.']);
            }

            if ($adjustment->requested_by === $actor->id) {
                throw ValidationException::withMessages(['adjustment' => 'You cannot reject your own request. Another authorized user must decide it.']);
            }

            $adjustment->forceFill([
                'status' => AdjustmentStatus::Rejected,
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $reason,
            ])->save();

            $this->audit->record(AuditAction::AdjustmentRejected, $actor, 'adjustment', $adjustment->id, $reason,
                oldValues: ['status' => AdjustmentStatus::Pending->value],
                newValues: [
                    'status' => AdjustmentStatus::Rejected->value,
                    'adjustment_no' => $adjustment->adjustment_no,
                    'kind' => $adjustment->kind->value,
                    'amount_centavos' => $adjustment->amount_centavos->centavos,
                    'requested_by' => $adjustment->requested_by,
                ],
            );

            return $adjustment;
        });
    }
}

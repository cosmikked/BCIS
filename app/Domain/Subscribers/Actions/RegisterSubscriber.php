<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Subscribers\AccountNumberGenerator;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Domain\Subscribers\SubscriberInput;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registers a subscriber with contacts and addresses (FR-SUB-001). One
 * transaction: the subscriber, its contacts, its addresses and the audit row
 * commit together or not at all. The duplicate warning (FR-SUB-012) is a
 * separate lookup, DuplicateSubscriberFinder; this Action never blocks on it.
 */
final class RegisterSubscriber
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountNumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): Subscriber
    {
        Gate::forUser($actor)->authorize('subscriber.manage');

        $data = SubscriberInput::validate($input);

        try {
            return DB::transaction(function () use ($actor, $data): Subscriber {
                $subscriber = Subscriber::query()->create([
                    'account_no' => $this->numbers->nextSubscriberNo(),
                    'full_name' => $data['full_name'],
                    'status' => SubscriberStatus::Active,
                    'collection_area_id' => $data['collection_area_id'],
                    'billing_due_day' => $data['billing_due_day'],
                    'notes' => $data['notes'],
                    'created_by' => $actor->id,
                ]);

                foreach ($data['contacts'] as $contact) {
                    $subscriber->contacts()->create([
                        'contact_type' => $contact['contact_type'],
                        'value' => $contact['value'],
                        'is_primary' => $contact['is_primary'],
                    ]);
                }

                foreach ($data['addresses'] as $address) {
                    $subscriber->addresses()->create([
                        'address_type' => $address['address_type'],
                        'line1' => $address['line1'],
                        'barangay' => $address['barangay'],
                        'city' => $address['city'],
                        'landmark' => $address['landmark'],
                        'collection_area_id' => $address['collection_area_id'],
                    ]);
                }

                $this->audit->record(AuditAction::SubscriberCreated, $actor, 'subscriber', $subscriber->id,
                    newValues: [
                        'account_no' => $subscriber->account_no,
                        'full_name' => $subscriber->full_name,
                        'status' => SubscriberStatus::Active->value,
                        'collection_area_id' => $subscriber->collection_area_id,
                        'billing_due_day' => $subscriber->billing_due_day,
                        'contacts' => count($data['contacts']),
                        'addresses' => count($data['addresses']),
                    ],
                );

                return $subscriber;
            });
        } catch (UniqueConstraintViolationException) {
            // Only possible if someone configured a prefix that collides; the
            // sequence itself never repeats.
            throw ValidationException::withMessages(['form' => 'The account number was already taken. Try again.']);
        }
    }
}

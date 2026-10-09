<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Subscribers\Enums\AddressType;
use App\Domain\Subscribers\Enums\ContactType;
use App\Domain\Subscribers\SubscriberInput;
use App\Models\ServiceAccount;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits a subscriber's details, contacts and addresses (FR-SUB-001). The status
 * is NOT changed here; ChangeSubscriberStatus does that with a reason.
 *
 * Contacts and addresses are matched by id. An id that is not on this
 * subscriber is rejected. A row left out of the input is removed, except an
 * address a service account uses: it can be edited but never removed.
 */
final class UpdateSubscriber
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $subscriberId, array $input): Subscriber
    {
        Gate::forUser($actor)->authorize('subscriber.manage');

        $data = SubscriberInput::validate($input);

        return DB::transaction(function () use ($actor, $subscriberId, $data): Subscriber {
            $subscriber = Subscriber::query()->whereKey($subscriberId)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($subscriber);

            $subscriber->forceFill([
                'full_name' => $data['full_name'],
                'collection_area_id' => $data['collection_area_id'],
                'billing_due_day' => $data['billing_due_day'],
                'notes' => $data['notes'],
            ])->save();

            $this->syncContacts($subscriber, $data['contacts']);
            $this->syncAddresses($subscriber, $data['addresses']);

            $after = $this->snapshot($subscriber->refresh());

            $this->audit->record(AuditAction::SubscriberUpdated, $actor, 'subscriber', $subscriber->id,
                oldValues: $before,
                newValues: $after,
            );

            return $subscriber;
        });
    }

    /** @param  list<array{id: ?int, contact_type: ContactType, value: string, is_primary: bool}>  $rows */
    private function syncContacts(Subscriber $subscriber, array $rows): void
    {
        $existing = $subscriber->contacts()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $i => $row) {
            $values = ['contact_type' => $row['contact_type'], 'value' => $row['value'], 'is_primary' => $row['is_primary']];

            if ($row['id'] === null) {
                $subscriber->contacts()->create($values);

                continue;
            }

            $contact = $existing->get($row['id']);
            if ($contact === null) {
                throw ValidationException::withMessages(["contacts.{$i}.value" => 'This contact does not belong to the subscriber.']);
            }

            $contact->update($values);
            $kept[] = $contact->id;
        }

        foreach ($existing as $id => $contact) {
            if (! in_array($id, $kept, true)) {
                $contact->delete();
            }
        }
    }

    /** @param  list<array{id: ?int, address_type: AddressType, line1: string, barangay: ?string, city: string, landmark: ?string, collection_area_id: ?int}>  $rows */
    private function syncAddresses(Subscriber $subscriber, array $rows): void
    {
        $existing = $subscriber->addresses()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $i => $row) {
            $values = [
                'address_type' => $row['address_type'],
                'line1' => $row['line1'],
                'barangay' => $row['barangay'],
                'city' => $row['city'],
                'landmark' => $row['landmark'],
                'collection_area_id' => $row['collection_area_id'],
            ];

            if ($row['id'] === null) {
                $subscriber->addresses()->create($values);

                continue;
            }

            $address = $existing->get($row['id']);
            if ($address === null) {
                throw ValidationException::withMessages(["addresses.{$i}.line1" => 'This address does not belong to the subscriber.']);
            }

            $address->update($values);
            $kept[] = $address->id;
        }

        foreach ($existing as $id => $address) {
            if (in_array($id, $kept, true)) {
                continue;
            }

            if (ServiceAccount::query()->where('service_address_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'addresses' => "{$address->oneLine()} is used by a service account and cannot be removed.",
                ]);
            }

            $address->delete();
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Subscriber $subscriber): array
    {
        return [
            'full_name' => $subscriber->full_name,
            'collection_area_id' => $subscriber->collection_area_id,
            'billing_due_day' => $subscriber->billing_due_day,
            'notes' => $subscriber->notes,
            'contacts' => $subscriber->contacts()->orderBy('id')->get()
                ->map(fn ($c) => $c->contact_type->value.': '.$c->value.($c->is_primary ? ' (primary)' : ''))->all(),
            'addresses' => $subscriber->addresses()->orderBy('id')->get()
                ->map(fn ($a) => $a->address_type->value.': '.$a->oneLine())->all(),
        ];
    }
}

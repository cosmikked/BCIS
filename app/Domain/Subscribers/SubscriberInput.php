<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Domain\Subscribers\Enums\AddressType;
use App\Domain\Subscribers\Enums\ContactType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises the subscriber form (FR-SUB-001). Used by
 * RegisterSubscriber and UpdateSubscriber, so the rules live in one place and
 * the Livewire form never decides what is valid. Error keys are the form field
 * names, such as full_name or contacts.0.value.
 *
 * @phpstan-type ContactRow array{id: ?int, contact_type: ContactType, value: string, is_primary: bool}
 * @phpstan-type AddressRow array{id: ?int, address_type: AddressType, line1: string, barangay: ?string, city: string, landmark: ?string, collection_area_id: ?int}
 * @phpstan-type Clean array{full_name: string, collection_area_id: ?int, billing_due_day: int, notes: ?string, contacts: list<ContactRow>, addresses: list<AddressRow>}
 */
final class SubscriberInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return Clean
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'collection_area_id' => ['nullable', 'integer', Rule::exists('collection_areas', 'id')],
            'billing_due_day' => ['required', 'integer', 'between:1,31'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'contacts' => ['required', 'array', 'min:1', 'max:10'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.contact_type' => ['required', Rule::enum(ContactType::class)],
            'contacts.*.value' => ['required', 'string', 'max:190'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'addresses' => ['required', 'array', 'min:1', 'max:10'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.address_type' => ['required', Rule::enum(AddressType::class)],
            'addresses.*.line1' => ['required', 'string', 'max:190'],
            'addresses.*.barangay' => ['nullable', 'string', 'max:100'],
            'addresses.*.city' => ['required', 'string', 'max:100'],
            'addresses.*.landmark' => ['nullable', 'string', 'max:190'],
            'addresses.*.collection_area_id' => ['nullable', 'integer', Rule::exists('collection_areas', 'id')],
        ], [
            'contacts.min' => 'Add at least one contact.',
            'addresses.min' => 'Add at least one address.',
            'contacts.*.value.required' => 'Enter the contact value.',
            'addresses.*.line1.required' => 'Enter the street or purok.',
            'addresses.*.city.required' => 'Enter the city or municipality.',
        ], [
            'full_name' => 'full name',
            'billing_due_day' => 'billing due day',
        ]);

        $validator->after(function ($validator): void {
            /** @var array<int, array<string, mixed>> $contacts */
            $contacts = $validator->getData()['contacts'] ?? [];

            foreach ($contacts as $i => $row) {
                $type = ContactType::tryFrom((string) ($row['contact_type'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));

                if ($type === null || $value === '') {
                    continue;
                }

                $error = match ($type) {
                    ContactType::Mobile => self::phoneError($value),
                    ContactType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email address.' : null,
                    ContactType::Other => null,
                };

                if ($error !== null) {
                    $validator->errors()->add("contacts.{$i}.value", $error);
                }
            }
        });

        /** @var array<string, mixed> $data */
        $data = $validator->validate();

        // A blank select arrives as '', which must mean "none", never id 0.
        $areaId = self::intOrNull($data['collection_area_id'] ?? null);

        $contacts = [];
        $primarySeen = false;
        /** @var list<array<string, mixed>> $rawContacts */
        $rawContacts = array_values($data['contacts']);
        foreach ($rawContacts as $row) {
            $type = ContactType::from((string) $row['contact_type']);
            // Only the first contact marked primary stays primary.
            $primary = ! $primarySeen && (bool) ($row['is_primary'] ?? false);
            $primarySeen = $primarySeen || $primary;

            $contacts[] = [
                'id' => self::intOrNull($row['id'] ?? null),
                'contact_type' => $type,
                'value' => ContactNormalizer::canonical($type, (string) $row['value']),
                'is_primary' => $primary,
            ];
        }

        if (! $primarySeen) {
            $contacts[0]['is_primary'] = true;
        }

        $addresses = [];
        /** @var list<array<string, mixed>> $rawAddresses */
        $rawAddresses = array_values($data['addresses']);
        foreach ($rawAddresses as $row) {
            $addresses[] = [
                'id' => self::intOrNull($row['id'] ?? null),
                'address_type' => AddressType::from((string) $row['address_type']),
                'line1' => trim((string) $row['line1']),
                'barangay' => self::blankToNull($row['barangay'] ?? null),
                'city' => trim((string) $row['city']),
                'landmark' => self::blankToNull($row['landmark'] ?? null),
                // An address without its own area belongs to the subscriber's.
                'collection_area_id' => self::intOrNull($row['collection_area_id'] ?? null) ?? $areaId,
            ];
        }

        return [
            'full_name' => trim((string) $data['full_name']),
            'collection_area_id' => $areaId,
            'billing_due_day' => (int) $data['billing_due_day'],
            'notes' => self::blankToNull($data['notes'] ?? null),
            'contacts' => $contacts,
            'addresses' => $addresses,
        ];
    }

    private static function phoneError(string $value): ?string
    {
        $length = strlen(ContactNormalizer::phoneDigits($value));

        return $length >= 7 && $length <= 15 ? null : 'Enter a phone number with 7 to 15 digits.';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : (int) $value;
    }

    private static function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

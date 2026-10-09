<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Domain\Subscribers\Enums\ContactType;
use App\Models\Subscriber;
use App\Models\SubscriberContact;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Finds probable duplicates for the registration form (FR-SUB-012). A subscriber
 * is flagged when
 *  - it already has the same contact (phone digits or email), or
 *  - it has the same name AND the same street address.
 * "Same" means equal after trimming, lower-casing and collapsing spaces. This is
 * a warning only; the form lets the user continue, reuse or reactivate. The PRD
 * gives no stricter definition, so it is flagged in the commit.
 */
final class DuplicateSubscriberFinder
{
    private const LIMIT = 5;

    /**
     * @param  list<array{contact_type: string|ContactType, value: string}>  $contacts
     * @param  list<string>  $streetLines  line1 of each address typed so far
     * @return list<DuplicateMatch>
     */
    public function find(User $actor, string $fullName, array $contacts, array $streetLines, ?int $exceptSubscriberId = null): array
    {
        Gate::forUser($actor)->authorize('subscriber.manage');

        /** @var array<int, string> $reasons subscriber id => first reason found */
        $reasons = [];

        foreach ($this->contactMatches($contacts, $exceptSubscriberId) as $subscriberId => $reason) {
            $reasons[$subscriberId] ??= $reason;
        }

        foreach ($this->nameAndAddressMatches($fullName, $streetLines, $exceptSubscriberId) as $subscriberId) {
            $reasons[$subscriberId] ??= 'Same name and address';
        }

        if ($reasons === []) {
            return [];
        }

        $subscribers = Subscriber::query()->whereIn('id', array_keys($reasons))->orderBy('full_name')->limit(self::LIMIT)->get();

        return $subscribers->map(fn (Subscriber $s) => new DuplicateMatch($s, $reasons[$s->id]))->values()->all();
    }

    /**
     * @param  list<array{contact_type: string|ContactType, value: string}>  $contacts
     * @return array<int, string>
     */
    private function contactMatches(array $contacts, ?int $except): array
    {
        $found = [];

        foreach ($contacts as $contact) {
            $type = $contact['contact_type'] instanceof ContactType ? $contact['contact_type'] : ContactType::tryFrom($contact['contact_type']);
            $value = ContactNormalizer::canonical($type ?? ContactType::Other, $contact['value']);

            if ($type === null || $value === '') {
                continue;
            }

            $query = SubscriberContact::query()->select('subscriber_id')
                ->when($except !== null, fn ($q) => $q->where('subscriber_id', '!=', $except));

            if ($type === ContactType::Mobile) {
                // Too few digits to be a real number: do not flag half-typed input.
                if (strlen($value) < 7) {
                    continue;
                }
                $query->whereRaw("regexp_replace(value, '\\D', '', 'g') = ?", [$value]);
                $label = 'Same contact number';
            } else {
                $query->whereRaw('lower(value) = ?', [mb_strtolower($value)]);
                $label = $type === ContactType::Email ? 'Same email address' : 'Same contact';
            }

            foreach ($query->limit(self::LIMIT)->pluck('subscriber_id') as $id) {
                $found[(int) $id] ??= $label;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $streetLines
     * @return list<int>
     */
    private function nameAndAddressMatches(string $fullName, array $streetLines, ?int $except): array
    {
        $name = self::squash($fullName);
        $streets = array_values(array_filter(array_map(self::squash(...), $streetLines), fn (string $s) => $s !== ''));

        if ($name === '' || $streets === []) {
            return [];
        }

        return Subscriber::query()
            ->whereRaw('lower(full_name) = ?', [$name])
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->whereHas('addresses', function ($addresses) use ($streets): void {
                $addresses->where(function ($inner) use ($streets): void {
                    foreach ($streets as $street) {
                        $inner->orWhereRaw('lower(line1) = ?', [$street]);
                    }
                });
            })
            ->limit(self::LIMIT)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private static function squash(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}

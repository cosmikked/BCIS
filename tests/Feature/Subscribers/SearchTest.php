<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Domain\Subscribers\Search\SearchSource;
use App\Domain\Subscribers\Search\Sources\AddressSource;
use App\Domain\Subscribers\Search\Sources\ContactSource;
use App\Domain\Subscribers\Search\Sources\NameSource;
use App\Domain\Subscribers\SubscriberListFilters;
use App\Domain\Subscribers\SubscriberListQuery;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-003, NFR-02, NFR-05

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->search = app(GlobalSearch::class);
});

/** @return list<int> subscriber ids the global search finds, in order */
function foundIds(User $actor, string $term): array
{
    return array_map(fn ($r) => $r->subscriberId, app(GlobalSearch::class)->search($actor, $term));
}

it('finds a subscriber by subscriber account number, service account number, name, contact and address (FR-SUB-003)', function (): void {
    $ana = SubscriberData::subscriber($this->admin, [
        'full_name' => 'Ana Reyes',
        'contacts' => [
            ['contact_type' => 'MOBILE', 'value' => '0917 555 0101', 'is_primary' => true],
            ['contact_type' => 'EMAIL', 'value' => 'ana.reyes@example.test'],
        ],
        'addresses' => [['address_type' => 'SERVICE', 'line1' => '45 Sampaguita Lane', 'barangay' => 'Casisang', 'city' => 'Malaybalay City', 'landmark' => 'near the old chapel']],
    ]);
    $account = SubscriberData::account($this->admin, $ana);
    $other = SubscriberData::subscriber($this->admin, ['full_name' => 'Zed Unrelated']);

    $keys = [
        'subscriber number' => $ana->account_no,
        'subscriber number tail' => substr($ana->account_no, -4),
        'service account number' => $account->account_no,
        'full name' => 'Ana Reyes',
        'partial name, any case' => 'reyes',
        'name with a typo' => 'Anna Reyez',
        'phone as typed' => '0917 555 0101',
        'phone with country code' => '+63 917-555-0101',
        'phone fragment' => '555 0101',
        'email' => 'ANA.REYES@example.test',
        'street' => 'sampaguita lane',
        'barangay' => 'casisang',
        'landmark' => 'old chapel',
    ];

    foreach ($keys as $what => $term) {
        $found = foundIds($this->admin, $term);

        expect(in_array($ana->id, $found, true))->toBeTrue("searching by {$what}: {$term}");
        expect(in_array($other->id, $found, true))->toBeFalse("{$what}: {$term} must not find the unrelated subscriber");
    }
});

it('reports what each hit matched on', function (): void {
    $ana = SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']);

    $results = $this->search->search($this->admin, 'Ana Reyes');

    expect($results)->toHaveCount(1)
        ->and($results[0]->matchedOn)->toBe(['Name'])
        ->and($results[0]->accountNo)->toBe($ana->account_no);
});

it('returns nothing for a term that is too short or matches nothing', function (): void {
    SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']);

    expect($this->search->search($this->admin, ''))->toBe([])
        ->and($this->search->search($this->admin, 'a'))->toBe([])
        ->and($this->search->search($this->admin, 'qqqqzzzz'))->toBe([]);
});

it('treats LIKE wildcards in the term as plain characters', function (): void {
    SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']);

    expect($this->search->search($this->admin, '%%'))->toBe([])
        ->and($this->search->search($this->admin, '_na_'))->toBe([]);
});

it('does not turn a name into a phone search, or a few digits into a contact match', function (): void {
    $contact = new ContactSource;

    expect($contact->subscriberIds('Plain Name'))->toBeNull()
        ->and($contact->subscriberIds('12'))->toBeNull()
        ->and($contact->subscriberIds('0917 555'))->not->toBeNull()
        ->and($contact->subscriberIds('ana@example.test'))->not->toBeNull()
        ->and((new AddressSource)->subscriberIds('ab'))->toBeNull();
});

it('lets any role with subscriber.view search and refuses the Technician', function (): void {
    $ana = SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']);

    foreach ([RoleCode::Cashier, RoleCode::CollectionSupervisor, RoleCode::Auditor, RoleCode::Viewer] as $i => $role) {
        expect(foundIds(Users::make("viewer{$i}", $role), 'Reyes'))->toBe([$ana->id]);
    }

    $technician = Users::make('tony', RoleCode::Technician);
    expect(fn () => $this->search->search($technician, 'Reyes'))->toThrow(AuthorizationException::class);
});

it('accepts new sources without changing GlobalSearch, such as a future invoice number (FR-SUB-003)', function (): void {
    $ana = SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']);
    SubscriberData::subscriber($this->admin, ['full_name' => 'Ben Santos']);

    // Stands in for the Phase 4 InvoiceNumberSource.
    $invoiceSource = new class($ana->id) implements SearchSource
    {
        public function __construct(private readonly int $subscriberId) {}

        public function label(): string
        {
            return 'Invoice number';
        }

        public function subscriberIds(string $term): ?Builder
        {
            return str_starts_with($term, 'INV-')
                ? DB::table('subscribers')->select('id')->where('id', $this->subscriberId)
                : null;
        }
    };

    $search = new GlobalSearch([new NameSource, $invoiceSource]);
    $results = $search->search($this->admin, 'INV-000123');

    expect($results)->toHaveCount(1)
        ->and($results[0]->subscriberId)->toBe($ana->id)
        ->and($results[0]->matchedOn)->toBe(['Invoice number']);

    // The same sources also narrow the list query.
    $query = Subscriber::query();
    $search->applyTo($query, 'INV-000123');
    expect($query->pluck('id')->all())->toBe([$ana->id]);
});

it('uses the trigram indexes for name and address search (NFR-05)', function (): void {
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = fn (Builder $query) => collect(DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()))
        ->map(fn ($row) => (array) $row)->flatten()->implode("\n");

    expect($plan((new NameSource)->subscriberIds('reyes')))->toContain('subscribers_full_name_trgm')
        ->and($plan((new AddressSource)->subscriberIds('sampaguita')))->toContain('subscriber_addresses_text_trgm');
});

describe('subscriber list', function (): void {
    it('pages on the server (FR-SUB-003, NFR-02)', function (): void {
        Subscriber::factory()->count(60)->create();

        $query = app(SubscriberListQuery::class)->build($this->admin, new SubscriberListFilters);
        $page1 = $query->paginate(25, page: 1);
        $page3 = (clone $query)->paginate(25, page: 3);

        expect($page1->total())->toBe(60)
            ->and($page1->items())->toHaveCount(25)
            ->and($page3->items())->toHaveCount(10)
            ->and(collect($page1->items())->pluck('id')->intersect(collect($page3->items())->pluck('id')))->toBeEmpty();
    });

    it('filters by search, status, area, collector and service type', function (): void {
        $area = CollectionArea::factory()->create();
        $collector = Collector::factory()->create();
        $cable = ServicePlan::factory()->cable()->create();

        $match = SubscriberData::subscriber($this->admin, ['full_name' => 'Match Person', 'collection_area_id' => $area->id]);
        SubscriberData::account($this->admin, $match, $cable, ['collector_id' => $collector->id]);
        $inactive = SubscriberData::subscriber($this->admin, ['full_name' => 'Match Inactive', 'collection_area_id' => $area->id]);
        app(ChangeSubscriberStatus::class)->handle($this->admin, $inactive->id, SubscriberStatus::Inactive, 'Paused');
        SubscriberData::subscriber($this->admin, ['full_name' => 'Somebody Else']);

        $ids = fn (SubscriberListFilters $filters) => app(SubscriberListQuery::class)->build($this->admin, $filters)->pluck('subscribers.id')->all();

        expect($ids(new SubscriberListFilters(search: 'Match')))->toEqualCanonicalizing([$match->id, $inactive->id])
            ->and($ids(new SubscriberListFilters(status: SubscriberStatus::Inactive)))->toBe([$inactive->id])
            ->and($ids(new SubscriberListFilters(areaId: $area->id)))->toEqualCanonicalizing([$match->id, $inactive->id])
            ->and($ids(new SubscriberListFilters(collectorId: $collector->id)))->toBe([$match->id])
            ->and($ids(new SubscriberListFilters(serviceTypeId: $cable->service_type_id)))->toBe([$match->id])
            ->and($ids(new SubscriberListFilters(search: 'Match', status: SubscriberStatus::Active, areaId: $area->id)))->toBe([$match->id]);
    });

    it('ignores a one-character search but returns nothing for an unmatched one', function (): void {
        Subscriber::factory()->count(3)->create();

        $count = fn (string $term) => app(SubscriberListQuery::class)->build($this->admin, new SubscriberListFilters(search: $term))->count();

        expect($count('a'))->toBe(3)->and($count('qqqqzzzz'))->toBe(0);
    });

    it('is refused without subscriber.view', function (): void {
        $technician = Users::make('tony', RoleCode::Technician);

        expect(fn () => app(SubscriberListQuery::class)->build($technician, new SubscriberListFilters))->toThrow(AuthorizationException::class);
    });
});

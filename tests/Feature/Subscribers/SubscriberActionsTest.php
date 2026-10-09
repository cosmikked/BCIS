<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Actions\RegisterSubscriber;
use App\Domain\Subscribers\Actions\UpdateSubscriber;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\AuditLog;
use App\Models\CollectionArea;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-001, FR-SUB-004, INV-10, INV-19, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
});

/** @return array<string, list<string>> the error messages keyed by field */
function registerErrors(User $actor, array $input): array
{
    try {
        app(RegisterSubscriber::class)->handle($actor, $input);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('registers a subscriber with contacts, addresses, a sequence number and an audit row (FR-SUB-001)', function (): void {
    $area = CollectionArea::factory()->create();

    $subscriber = app(RegisterSubscriber::class)->handle($this->admin, SubscriberData::input([
        'full_name' => '  Maria Clara  ',
        'collection_area_id' => $area->id,
        'billing_due_day' => 15,
        'notes' => 'Prefers mornings',
    ]));

    expect($subscriber->account_no)->toMatch('/^SUB-\d{6}$/')
        ->and($subscriber->full_name)->toBe('Maria Clara')
        ->and($subscriber->status)->toBe(SubscriberStatus::Active)
        ->and($subscriber->billing_due_day)->toBe(15)
        ->and($subscriber->created_by)->toBe($this->admin->id)
        ->and($subscriber->contacts()->count())->toBe(1)
        ->and($subscriber->addresses()->count())->toBe(1);

    // An address with no area of its own takes the subscriber's.
    expect($subscriber->addresses()->first()->collection_area_id)->toBe($area->id);

    $audit = AuditLog::query()->where('action', 'subscriber.created')->sole();
    expect($audit->entity_id)->toBe($subscriber->id)
        ->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->new_values['account_no'])->toBe($subscriber->account_no);
});

it('numbers subscribers from the sequence, one higher each time (INV-10)', function (): void {
    $first = SubscriberData::subscriber($this->admin);
    $second = SubscriberData::subscriber($this->admin);

    expect((int) substr($second->account_no, -6))->toBe((int) substr($first->account_no, -6) + 1);
});

it('never reuses a number after a failed registration (INV-10)', function (): void {
    $first = SubscriberData::subscriber($this->admin);

    // The number is taken, then the transaction rolls back: a gap, not a reuse.
    try {
        DB::transaction(function (): void {
            app(RegisterSubscriber::class)->handle($this->admin, SubscriberData::input());
            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    $third = SubscriberData::subscriber($this->admin);

    expect((int) substr($third->account_no, -6))->toBeGreaterThan((int) substr($first->account_no, -6) + 1);
    expect(Subscriber::query()->count())->toBe(2);
});

it('normalises phone numbers so differently typed ones are the same (FR-SUB-003)', function (): void {
    $a = SubscriberData::subscriber($this->admin, ['contacts' => [['contact_type' => 'MOBILE', 'value' => '+63 917-123-4567']]]);

    expect($a->contacts()->sole()->value)->toBe('09171234567')
        ->and($a->contacts()->sole()->is_primary)->toBeTrue();
});

it('reports inline errors keyed by field (FR-SUB-001)', function (): void {
    $errors = registerErrors($this->admin, SubscriberData::input([
        'full_name' => '',
        'billing_due_day' => 32,
        'contacts' => [['contact_type' => 'MOBILE', 'value' => '12'], ['contact_type' => 'EMAIL', 'value' => 'not-an-email']],
        'addresses' => [['address_type' => 'SERVICE', 'line1' => '', 'city' => '']],
    ]));

    expect($errors)->toHaveKeys(['full_name', 'billing_due_day', 'contacts.0.value', 'contacts.1.value', 'addresses.0.line1', 'addresses.0.city']);
    expect(Subscriber::query()->count())->toBe(0);
});

it('needs at least one contact and one address', function (): void {
    expect(registerErrors($this->admin, SubscriberData::input(['contacts' => []])))->toHaveKey('contacts')
        ->and(registerErrors($this->admin, SubscriberData::input(['addresses' => []])))->toHaveKey('addresses');
});

it('keeps only one primary contact', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin, ['contacts' => [
        ['contact_type' => 'MOBILE', 'value' => '09171112222', 'is_primary' => true],
        ['contact_type' => 'EMAIL', 'value' => 'a@example.test', 'is_primary' => true],
    ]]);

    expect($subscriber->contacts()->where('is_primary', true)->count())->toBe(1);
});

it('registers nothing at all when a later row is invalid (all or nothing)', function (): void {
    $errors = registerErrors($this->admin, SubscriberData::input([
        'addresses' => [
            ['address_type' => 'SERVICE', 'line1' => '1 Good St', 'city' => 'Malaybalay City'],
            ['address_type' => 'SERVICE', 'line1' => '', 'city' => 'Malaybalay City'],
        ],
    ]));

    expect($errors)->toHaveKey('addresses.1.line1')
        ->and(Subscriber::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'subscriber.created')->count())->toBe(0);
});

it('updates details, adds and removes contacts, and audits old and new values (FR-SUB-001)', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Old Name']);
    $contact = $subscriber->contacts()->sole();
    $address = $subscriber->addresses()->sole();

    app(UpdateSubscriber::class)->handle($this->admin, $subscriber->id, [
        'full_name' => 'New Name',
        'collection_area_id' => null,
        'billing_due_day' => 20,
        'notes' => 'Changed',
        'contacts' => [
            ['id' => $contact->id, 'contact_type' => 'MOBILE', 'value' => '0917 000 1111', 'is_primary' => true],
            ['contact_type' => 'EMAIL', 'value' => 'New@Example.test'],
        ],
        'addresses' => [
            ['id' => $address->id, 'address_type' => 'SERVICE', 'line1' => 'Moved St', 'city' => 'Malaybalay City'],
        ],
    ]);

    $subscriber->refresh();
    expect($subscriber->full_name)->toBe('New Name')
        ->and($subscriber->billing_due_day)->toBe(20)
        ->and($subscriber->contacts()->count())->toBe(2)
        ->and($subscriber->contacts()->where('contact_type', 'EMAIL')->value('value'))->toBe('new@example.test')
        ->and($subscriber->addresses()->sole()->line1)->toBe('Moved St');

    $audit = AuditLog::query()->where('action', 'subscriber.updated')->sole();
    expect($audit->old_values['full_name'])->toBe('Old Name')->and($audit->new_values['full_name'])->toBe('New Name');

    // Dropping the email from the next save removes it.
    app(UpdateSubscriber::class)->handle($this->admin, $subscriber->id, [
        'full_name' => 'New Name', 'billing_due_day' => 20,
        'contacts' => [['id' => $contact->id, 'contact_type' => 'MOBILE', 'value' => '09170001111']],
        'addresses' => [['id' => $address->id, 'address_type' => 'SERVICE', 'line1' => 'Moved St', 'city' => 'Malaybalay City']],
    ]);
    expect($subscriber->contacts()->count())->toBe(1);
});

it('rejects a contact or address id that belongs to another subscriber', function (): void {
    $mine = SubscriberData::subscriber($this->admin);
    $other = SubscriberData::subscriber($this->admin);

    $input = SubscriberData::input();
    $input['contacts'][0]['id'] = $other->contacts()->sole()->id;

    expect(fn () => app(UpdateSubscriber::class)->handle($this->admin, $mine->id, $input))->toThrow(ValidationException::class);
    expect($other->contacts()->sole()->subscriber_id)->toBe($other->id);
});

it('never removes an address a service account uses', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin);
    SubscriberData::account($this->admin, $subscriber);

    $input = SubscriberData::input();
    $input['addresses'] = [['address_type' => 'BILLING', 'line1' => 'PO Box 1', 'city' => 'Malaybalay City']];

    expect(fn () => app(UpdateSubscriber::class)->handle($this->admin, $subscriber->id, $input))->toThrow(ValidationException::class);
    expect($subscriber->addresses()->count())->toBe(1);
});

describe('status changes (FR-SUB-004)', function (): void {
    it('changes status with a reason and writes an audit row', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);

        app(ChangeSubscriberStatus::class)->handle($this->admin, $subscriber->id, SubscriberStatus::Inactive, 'Moved abroad');

        expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Inactive);
        $audit = AuditLog::query()->where('action', 'subscriber.status_changed')->sole();
        expect($audit->reason)->toBe('Moved abroad')
            ->and($audit->old_values)->toBe(['status' => 'ACTIVE'])
            ->and($audit->new_values)->toBe(['status' => 'INACTIVE']);
    });

    it('can reactivate an inactive subscriber but never a terminated one', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);
        $change = fn (SubscriberStatus $to) => app(ChangeSubscriberStatus::class)->handle($this->admin, $subscriber->id, $to, 'Test reason');

        $change(SubscriberStatus::Inactive);
        $change(SubscriberStatus::Active);
        $change(SubscriberStatus::Terminated);

        expect(fn () => $change(SubscriberStatus::Active))->toThrow(ValidationException::class);
        $change(SubscriberStatus::Archived);
        expect(fn () => $change(SubscriberStatus::Active))->toThrow(ValidationException::class);
    });

    it('requires a reason', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);

        expect(fn () => app(ChangeSubscriberStatus::class)->handle($this->admin, $subscriber->id, SubscriberStatus::Inactive, ''))
            ->toThrow(ValidationException::class);
        expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Active);
    });

    it('will not terminate a subscriber who still has a live service account', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);
        $account = SubscriberData::account($this->admin, $subscriber);
        $terminate = fn () => app(ChangeSubscriberStatus::class)->handle($this->admin, $subscriber->id, SubscriberStatus::Terminated, 'Leaving');

        expect($terminate)->toThrow(ValidationException::class);

        app(ChangeServiceStatus::class)->handle($this->admin, $account->id, ServiceStatus::Terminated, 'Cancelled');
        $terminate();

        expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Terminated);
    });

    it('does not let a non-active subscriber get a new service account', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);
        app(ChangeSubscriberStatus::class)->handle($this->admin, $subscriber->id, SubscriberStatus::Inactive, 'Paused');

        expect(fn () => SubscriberData::account($this->admin, $subscriber))->toThrow(ValidationException::class);
    });
});

it('refuses subscriber changes to roles without subscriber.manage (AT-10)', function (RoleCode $role): void {
    $user = Users::make('someone', $role);
    $subscriber = SubscriberData::subscriber($this->admin);

    expect(fn () => app(RegisterSubscriber::class)->handle($user, SubscriberData::input()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSubscriber::class)->handle($user, $subscriber->id, SubscriberData::input()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeSubscriberStatus::class)->handle($user, $subscriber->id, SubscriberStatus::Inactive, 'Nope'))->toThrow(AuthorizationException::class);

    expect(Subscriber::query()->count())->toBe(1)->and($subscriber->refresh()->status)->toBe(SubscriberStatus::Active);
})->with([
    'cashier' => RoleCode::Cashier,
    'supervisor' => RoleCode::CollectionSupervisor,
    'auditor' => RoleCode::Auditor,
    'technician' => RoleCode::Technician,
    'viewer' => RoleCode::Viewer,
]);

<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\DuplicateSubscriberFinder;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-012

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->finder = app(DuplicateSubscriberFinder::class);
    $this->existing = SubscriberData::subscriber($this->admin, [
        'full_name' => 'Elena Cruz',
        'contacts' => [
            ['contact_type' => 'MOBILE', 'value' => '0917 555 0202', 'is_primary' => true],
            ['contact_type' => 'EMAIL', 'value' => 'elena@example.test'],
        ],
        'addresses' => [['address_type' => 'SERVICE', 'line1' => '7 Mabini Street', 'city' => 'Malaybalay City']],
    ]);
});

it('flags a known contact number however it is typed, with a reason', function (string $typed): void {
    $matches = $this->finder->find($this->admin, 'Somebody New', [['contact_type' => 'MOBILE', 'value' => $typed]], []);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->subscriber->id)->toBe($this->existing->id)
        ->and($matches[0]->reason)->toBe('Same contact number');
})->with(['09175550202', '0917-555-0202', '+63 917 555 0202']);

it('flags a known email', function (): void {
    $matches = $this->finder->find($this->admin, 'Somebody New', [['contact_type' => 'EMAIL', 'value' => 'ELENA@example.test']], []);

    expect($matches)->toHaveCount(1)->and($matches[0]->reason)->toBe('Same email address');
});

it('flags the same name at the same street address, ignoring case and spacing', function (): void {
    $matches = $this->finder->find($this->admin, '  elena   CRUZ ', [], ['7  mabini street']);

    expect($matches)->toHaveCount(1)->and($matches[0]->reason)->toBe('Same name and address');
});

it('does not flag a similar name, or the same name at another address', function (): void {
    expect($this->finder->find($this->admin, 'Elaine Cruz', [], ['7 Mabini Street']))->toBe([])
        ->and($this->finder->find($this->admin, 'Elena Cruz', [], ['99 Other Street']))->toBe([])
        ->and($this->finder->find($this->admin, 'Elena Cruz', [], []))->toBe([])
        ->and($this->finder->find($this->admin, 'Nobody', [['contact_type' => 'MOBILE', 'value' => '0917 555']], []))->toBe([]);
});

it('lists a subscriber once even when both rules match', function (): void {
    $matches = $this->finder->find($this->admin, 'Elena Cruz', [['contact_type' => 'MOBILE', 'value' => '09175550202']], ['7 Mabini Street']);

    expect($matches)->toHaveCount(1);
});

it('still finds an inactive subscriber so the form can offer to reactivate', function (): void {
    app(ChangeSubscriberStatus::class)->handle($this->admin, $this->existing->id, SubscriberStatus::Inactive, 'Moved away');

    $matches = $this->finder->find($this->admin, 'Anyone', [['contact_type' => 'MOBILE', 'value' => '09175550202']], []);

    expect($matches[0]->subscriber->status)->toBe(SubscriberStatus::Inactive);
});

it('can leave out the subscriber being edited', function (): void {
    $matches = $this->finder->find($this->admin, 'Elena Cruz', [['contact_type' => 'MOBILE', 'value' => '09175550202']], ['7 Mabini Street'], $this->existing->id);

    expect($matches)->toBe([]);
});

it('is only for roles that can register subscribers', function (): void {
    $cashier = Users::make('cash', RoleCode::Cashier);

    expect(fn () => $this->finder->find($cashier, 'Elena Cruz', [], []))->toThrow(AuthorizationException::class);
});

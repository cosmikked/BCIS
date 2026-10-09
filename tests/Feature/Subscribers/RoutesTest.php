<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-004, INV-17, INV-19, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->subscriber = SubscriberData::subscriber($this->admin);
});

it('opens every Phase 3 screen for an Administrator', function (): void {
    $routes = [
        route('subscribers.index'),
        route('subscribers.show', $this->subscriber->id),
        route('subscribers.create'),
        route('subscribers.edit', $this->subscriber->id),
        route('plans.index'),
        route('service-accounts.index'),
        route('service-history.index'),
    ];

    foreach ($routes as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
});

it('lets read-only roles read but not register or edit subscribers (AT-10)', function (RoleCode $role): void {
    $user = Users::make('reader', $role);

    foreach ([route('subscribers.index'), route('subscribers.show', $this->subscriber->id), route('plans.index'), route('service-accounts.index'), route('service-history.index')] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }

    $this->actingAs($user)->get(route('subscribers.create'))->assertForbidden();
    $this->actingAs($user)->get(route('subscribers.edit', $this->subscriber->id))->assertForbidden();
})->with([
    'cashier' => RoleCode::Cashier,
    'supervisor' => RoleCode::CollectionSupervisor,
    'auditor' => RoleCode::Auditor,
    'viewer' => RoleCode::Viewer,
]);

it('keeps the Technician out of every screen that shows rates (FR-SUB-013)', function (): void {
    $technician = Users::make('tony', RoleCode::Technician);

    foreach ([route('subscribers.index'), route('subscribers.show', $this->subscriber->id), route('subscribers.create'), route('plans.index'), route('service-accounts.index'), route('service-history.index')] as $url) {
        $this->actingAs($technician)->get($url)->assertForbidden();
    }
});

it('sends a guest to the login page', function (): void {
    foreach ([route('subscribers.index'), route('plans.index'), route('service-accounts.index')] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('returns 404 for a subscriber that does not exist', function (): void {
    $this->actingAs($this->admin)->get(route('subscribers.show', 999999))->assertNotFound();
    $this->actingAs($this->admin)->get('/subscribers/not-a-number')->assertNotFound();
});

it('has no delete, update or create-by-post route for subscribers, service accounts or plans (INV-19)', function (): void {
    $risky = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $touchesPhase3 = preg_match('#^(subscribers|service-accounts|service-history|plans|subscriber-documents)(/|$)#', $uri) === 1;
        $methods = array_diff($route->methods(), ['GET', 'HEAD']);

        // Livewire's own endpoint is the only POST allowed to carry component actions.
        if ($touchesPhase3 && $methods !== []) {
            $risky[] = implode('|', $methods).' '.$uri;
        }
    }

    expect($risky)->toBe([]);

    foreach (Route::getRoutes() as $route) {
        expect(in_array('DELETE', $route->methods(), true))->toBeFalse("DELETE route found: {$route->uri()}");
    }
});

it('shows the new screens in the sidebar for the right roles', function (): void {
    $this->actingAs($this->admin)->get(route('home'))
        ->assertSee(route('subscribers.index'), false)
        ->assertSee(route('service-accounts.index'), false)
        ->assertSee(route('plans.index'), false);

    $this->actingAs(Users::make('cash', RoleCode::Cashier))->get(route('home'))
        ->assertSee(route('subscribers.index'), false)
        ->assertDontSee(route('subscribers.create'), false);
});

<?php

declare(strict_types=1);

use App\Domain\Security\NavigationMenu;
use App\Domain\Security\Permission;
use App\Domain\Security\RoleCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

// FR-UI-010: menus follow permissions. Presentation only; the server still enforces everything.

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/** @return list<string> Labels of every visible item and child, flattened. */
function menuFor(?RoleCode $role): array
{
    $user = $role === null ? null : Users::make('nav.'.strtolower($role->value), $role);
    $labels = [];

    foreach (NavigationMenu::visibleFor($user, config('navigation')) as $item) {
        $labels[] = $item['label'];
        foreach ($item['children'] ?? [] as $child) {
            $labels[] = $item['label'].' > '.$child['label'];
        }
    }

    return $labels;
}

it('shows the Owner every group including Administration', function (): void {
    $menu = menuFor(RoleCode::Owner);

    expect($menu)->toContain('Administration > Users', 'Administration > Roles & Permissions', 'Administration > Audit Log', 'Payments > GCash Verification', 'Reports', 'Services');
});

it('hides Administration from a Cashier but keeps the payment screens', function (): void {
    $menu = menuFor(RoleCode::Cashier);

    expect($menu)->not->toContain('Administration')
        ->and($menu)->toContain('Payments > Receive Payment', 'Payments > Payment History')
        ->and($menu)->not->toContain('Payments > GCash Verification', 'Billing > Generate Billing');
});

it('shows a Technician only service screens, with no money screens', function (): void {
    // The Technician holds service.view and service.execute and nothing financial (PRD section 2).
    expect(menuFor(RoleCode::Technician))->toBe(['Dashboard', 'Subscribers', 'Subscribers > Service Accounts', 'Services']);
});

it('shows an Auditor the audit log but not user management', function (): void {
    $menu = menuFor(RoleCode::Auditor);

    expect($menu)->toContain('Administration > Audit Log')
        ->and($menu)->not->toContain('Administration > Users', 'Administration > Settings');
});

it('shows a signed-out visitor only items that need no permission', function (): void {
    expect(menuFor(null))->toBe(['Dashboard']);
});

it('names a real permission code on every menu item', function (): void {
    $known = Permission::codes();
    $bad = [];

    $walk = function (array $items) use (&$walk, $known, &$bad): void {
        foreach ($items as $item) {
            if (isset($item['children'])) {
                $walk($item['children']);

                continue;
            }
            if (($item['permission'] ?? null) !== null && ! in_array($item['permission'], $known, true)) {
                $bad[] = $item['label'];
            }
        }
    };
    $walk(config('navigation'));

    expect($bad)->toBe([]);
});

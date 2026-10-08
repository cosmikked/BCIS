<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sidebar navigation (PRD section 17)
|--------------------------------------------------------------------------
|
| Placeholder structure only. Items without a 'route' render as inactive until
| their phase builds the screen. 'permission' stays null until Phase 2 adds
| roles; then menus follow permissions (FR-UI-010). Hiding a menu item is
| never authorization: every route and action checks the permission itself.
|
*/

return [
    ['label' => 'Dashboard', 'route' => 'home', 'permission' => null],
    ['label' => 'Subscribers', 'permission' => null, 'children' => [
        ['label' => 'All Subscribers'],
        ['label' => 'New Subscriber'],
        ['label' => 'Service Accounts'],
    ]],
    ['label' => 'Billing', 'permission' => null, 'children' => [
        ['label' => 'Current Billing'],
        ['label' => 'Generate Billing'],
        ['label' => 'Invoices'],
    ]],
    ['label' => 'Payments', 'permission' => null, 'children' => [
        ['label' => 'Receive Payment'],
        ['label' => 'Payment History'],
        ['label' => 'GCash Verification'],
    ]],
    ['label' => 'Collections', 'permission' => null, 'children' => [
        ['label' => 'Collectors'],
        ['label' => 'Areas & Routes'],
        ['label' => 'Collection Batches'],
        ['label' => 'Remittance'],
    ]],
    ['label' => 'Receivables', 'permission' => null, 'children' => [
        ['label' => 'Outstanding'],
        ['label' => 'Overdue'],
        ['label' => 'Aging'],
        ['label' => 'Suspension Candidates'],
    ]],
    ['label' => 'Services', 'permission' => null],
    ['label' => 'Reports', 'permission' => null],
    ['label' => 'Administration', 'permission' => null],
];

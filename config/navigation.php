<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sidebar navigation (PRD section 17)
|--------------------------------------------------------------------------
|
| Each leaf names the permission that shows it (FR-UI-010). A group is shown
| when at least one of its children is. Items without a 'route' render as
| inactive text until their phase builds the screen.
|
| Hiding a menu item is NOT authorization. Every route and Livewire action
| checks the permission itself (INV-17); this list only keeps the menu tidy.
|
*/

return [
    // Landing page for every signed-in user, so it needs no permission.
    ['label' => 'Dashboard', 'route' => 'home', 'permission' => null],
    ['label' => 'Subscribers', 'children' => [
        ['label' => 'All Subscribers', 'route' => 'subscribers.index', 'permission' => 'subscriber.view'],
        ['label' => 'New Subscriber', 'route' => 'subscribers.create', 'permission' => 'subscriber.manage'],
        // Shows the monthly rate, so it needs subscriber.view. The Technician
        // (service.view only) gets the operational Services item instead.
        ['label' => 'Service Accounts', 'route' => 'service-accounts.index', 'permission' => 'subscriber.view'],
        ['label' => 'Service History', 'route' => 'service-history.index', 'permission' => 'subscriber.view'],
        ['label' => 'Plans', 'route' => 'plans.index', 'permission' => 'subscriber.view'],
    ]],
    ['label' => 'Billing', 'children' => [
        ['label' => 'Current Billing', 'permission' => 'billing.view'],
        ['label' => 'Generate Billing', 'permission' => 'billing.generate'],
        ['label' => 'Invoices', 'permission' => 'billing.view'],
    ]],
    ['label' => 'Payments', 'children' => [
        ['label' => 'Receive Payment', 'permission' => 'payment.create'],
        ['label' => 'Payment History', 'permission' => 'payment.view'],
        ['label' => 'GCash Verification', 'permission' => 'gcash.verify'],
    ]],
    ['label' => 'Collections', 'children' => [
        ['label' => 'Collectors', 'permission' => 'collection.manage'],
        ['label' => 'Areas & Routes', 'permission' => 'collection.manage'],
        ['label' => 'Collection Batches', 'permission' => 'collection.record'],
        ['label' => 'Remittance', 'permission' => 'collection.remit'],
    ]],
    ['label' => 'Receivables', 'children' => [
        ['label' => 'Outstanding', 'permission' => 'receivable.view'],
        ['label' => 'Overdue', 'permission' => 'receivable.view'],
        ['label' => 'Aging', 'permission' => 'receivable.view'],
        ['label' => 'Suspension Candidates', 'permission' => 'receivable.view'],
    ]],
    ['label' => 'Services', 'route' => 'services.operational', 'permission' => 'service.view'],
    ['label' => 'Reports', 'permission' => 'report.view'],
    ['label' => 'Administration', 'children' => [
        ['label' => 'Users', 'route' => 'admin.users', 'permission' => 'user.manage'],
        ['label' => 'Roles & Permissions', 'route' => 'admin.roles', 'permission' => 'role.manage'],
        ['label' => 'Settings', 'route' => 'admin.settings', 'permission' => 'settings.manage'],
        ['label' => 'Audit Log', 'route' => 'admin.audit', 'permission' => 'audit.view'],
    ]],
];

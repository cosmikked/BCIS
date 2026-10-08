<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Seeded roles and their permissions (PRD section 2 matrix). Used by the seeder
 * only. Application code asks for permissions, not roles (INV-17); the one
 * deliberate exception is the "last active Owner" protection (PRD section 2).
 */
enum RoleCode: string
{
    case Owner = 'OWNER';
    case Administrator = 'ADMINISTRATOR';
    case Cashier = 'CASHIER';
    case CollectionSupervisor = 'COLLECTION_SUPERVISOR';
    case Auditor = 'AUDITOR';
    case Technician = 'TECHNICIAN';
    case Viewer = 'VIEWER';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner (Super Admin)',
            self::Administrator => 'Administrator',
            self::Cashier => 'Cashier',
            self::CollectionSupervisor => 'Collection Supervisor',
            self::Auditor => 'Accounting / Auditor',
            self::Technician => 'Technician',
            self::Viewer => 'Read-only Viewer',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Administrator => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p): bool => ! in_array($p, [
                    Permission::CollectionReconcile,
                    Permission::ServiceSuspendApprove,
                    Permission::AuditView,
                    Permission::UserManage,
                    Permission::RoleManage,
                    Permission::SettingsManage,
                    Permission::BackupCreate,
                    Permission::BackupRestore,
                ], true),
            )),
            self::Cashier => [
                Permission::SubscriberView, Permission::ServiceView, Permission::BillingView,
                Permission::PaymentView, Permission::PaymentCreate, Permission::GcashRecord,
                Permission::PaymentReverseRequest, Permission::ReceiptReprint,
                Permission::CollectionRecord, Permission::CollectionRemit, Permission::ReportView,
            ],
            self::CollectionSupervisor => [
                Permission::DashboardView, Permission::SubscriberView, Permission::ServiceView,
                Permission::BillingView, Permission::PaymentView, Permission::CollectionManage,
                Permission::CollectionRecord, Permission::CollectionRemit, Permission::CollectionReconcile,
                Permission::ReceivableView, Permission::ReportView,
            ],
            self::Auditor => [
                Permission::DashboardView, Permission::SubscriberView, Permission::ServiceView,
                Permission::BillingView, Permission::PaymentView, Permission::ReceivableView,
                Permission::ReportView, Permission::ReportExport, Permission::AuditView,
            ],
            self::Technician => [Permission::ServiceView, Permission::ServiceExecute],
            self::Viewer => [
                Permission::DashboardView, Permission::SubscriberView, Permission::ServiceView,
                Permission::BillingView, Permission::PaymentView, Permission::ReceivableView,
                Permission::ReportView,
            ],
        };
    }
}

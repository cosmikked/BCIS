<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Catalogue of permission codes (PRD section 2). Code checks these codes and
 * never role names (INV-17). The same list seeds the `permissions` table and
 * defines one Gate per code, so the three cannot drift apart.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case SubscriberView = 'subscriber.view';
    case SubscriberManage = 'subscriber.manage';
    case PlanManage = 'plan.manage';
    case ServiceView = 'service.view';
    case ServiceManage = 'service.manage';
    case BillingView = 'billing.view';
    case BillingGenerate = 'billing.generate';
    case InvoiceAdjust = 'invoice.adjust';
    case InvoiceVoid = 'invoice.void';
    case PaymentView = 'payment.view';
    case PaymentCreate = 'payment.create';
    case PaymentAllocateManual = 'payment.allocate_manual';
    case PaymentBackdate = 'payment.backdate';
    case GcashRecord = 'gcash.record';
    case GcashVerify = 'gcash.verify';
    case PaymentReverseRequest = 'payment.reverse.request';
    case PaymentReverse = 'payment.reverse';
    case ReceiptReprint = 'receipt.reprint';
    case CollectionManage = 'collection.manage';
    case CollectionRecord = 'collection.record';
    case CollectionRemit = 'collection.remit';
    case CollectionReconcile = 'collection.reconcile';
    case CollectionClose = 'collection.close';
    case ReceivableView = 'receivable.view';
    case ServiceSuspendRequest = 'service.suspend.request';
    case ServiceSuspendApprove = 'service.suspend.approve';
    case ServiceExecute = 'service.execute';
    case ReportView = 'report.view';
    case ReportExport = 'report.export';
    case AuditView = 'audit.view';
    case UserManage = 'user.manage';
    case RoleManage = 'role.manage';
    case SettingsManage = 'settings.manage';
    case BackupCreate = 'backup.create';
    case BackupRestore = 'backup.restore';

    public function description(): string
    {
        return match ($this) {
            self::DashboardView => 'View the dashboard',
            self::SubscriberView => 'View subscribers',
            self::SubscriberManage => 'Create and edit subscribers',
            self::PlanManage => 'Manage service plans',
            self::ServiceView => 'View service accounts (operational fields only)',
            self::ServiceManage => 'Create and edit service accounts',
            self::BillingView => 'View billing cycles and invoices',
            self::BillingGenerate => 'Generate and finalize billing',
            self::InvoiceAdjust => 'Adjust a finalized invoice',
            self::InvoiceVoid => 'Void an invoice',
            self::PaymentView => 'View payments',
            self::PaymentCreate => 'Receive and post payments',
            self::PaymentAllocateManual => 'Allocate a payment manually',
            self::PaymentBackdate => 'Post a payment with an earlier payment date',
            self::GcashRecord => 'Record a GCash proof',
            self::GcashVerify => 'Verify or reject a GCash payment',
            self::PaymentReverseRequest => 'Request a payment reversal',
            self::PaymentReverse => 'Approve and post a payment reversal',
            self::ReceiptReprint => 'Reprint a receipt',
            self::CollectionManage => 'Manage collectors, areas and batches',
            self::CollectionRecord => 'Record collections on a batch',
            self::CollectionRemit => 'Receive collector remittance',
            self::CollectionReconcile => 'Reconcile a collection batch',
            self::CollectionClose => 'Close a collection batch',
            self::ReceivableView => 'View receivables and aging',
            self::ServiceSuspendRequest => 'Request a suspension',
            self::ServiceSuspendApprove => 'Approve a suspension',
            self::ServiceExecute => 'Execute suspension and reconnection jobs',
            self::ReportView => 'View reports',
            self::ReportExport => 'Export reports',
            self::AuditView => 'View the audit log',
            self::UserManage => 'Manage users',
            self::RoleManage => 'Manage roles and permissions',
            self::SettingsManage => 'Manage application settings',
            self::BackupCreate => 'Create and verify backups',
            self::BackupRestore => 'Restore from a backup',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }
}

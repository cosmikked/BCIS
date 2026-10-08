# BCIS ERD Plan

Oct 9, 2026 · @michaela

## Design decisions that shape the schema

The schema follows one rule from the laboratory document: money moves only by appending rows, and posted history is never edited or deleted. These decisions come from F1 to F10 and apply to every table below.

- **Money:** every amount is a `BIGINT` in centavos (columns end in `_centavos`), currency PHP. No floats and no `NUMERIC` surprises in application code.
- **The ledger is the spine:** `ledger_entries` is append-only. Billing writes debits, payments write credits, reversals and adjustments write counter-entries. The running balance is computed from the entries (window function), so it is always reproducible. A cached balance column, if you add one, is checked by the integrity job and is never the authority.
- **Derived balances:** an invoice's remaining balance is `total − allocations + adjustments`. Invoice status (PAID, PARTIALLY\_PAID, OVERDUE) is recomputed from those rows inside the posting transaction. OVERDUE and aging buckets are derived from due date and balance, not stored.
- **Immutability:** finalized invoices, posted payments, receipts and audit rows cannot be updated or deleted. Enforce it twice: no such routes in the API, and database triggers or revoked `UPDATE`/`DELETE` privileges for the application role.
- **Numbering:** invoice, receipt, reversal, batch and account numbers come from PostgreSQL sequences, each with a `UNIQUE` constraint. Gaps are accepted. Voided numbers stay reserved.
- **No deletes:** every foreign key is `ON DELETE RESTRICT`. Subscribers, service accounts, users and collectors are deactivated through a status column, never removed.
- **Effective dating:** collector assignments, service status changes and setting changes are dated rows, so past reports and past batches stay correct.
- **Snapshots:** the service account stores its own rate, each invoice stores the billed rate on its items, and each batch account stores the bill, arrears and total due at batch creation.
- **Keys and time:** `BIGINT` identity primary keys plus human-readable unique numbers. Timestamps are `timestamptz` stamped by the server. Business dates (due date, payment date) are `date` in Asia/Manila.

## ERD at a glance

&#91;embedded content: core ERD · 16 tables in 5 modules\]

Lines run from the owning table (dot) to the table that holds many rows (arrow). Invoices and payments each write to `ledger_entries`, and a payment reaches an invoice only through `payment_allocations`. Not drawn: `users` and the other security tables, `audit_logs`, `application_settings`, `backup_history`, `service_events`, suspension and reconnection records, `collector_assignments` and `batch_reconciliations`. Field payments carry `payments.batch_account_id`, and every table that records an actor points to `users`.

## Security and system tables

These tables support F10 and the audit rule in every other flow.

| Table | Key columns | Notes |
| --- | --- | --- |
| `users` | id, username (unique), full\_name, password\_hash, is\_active, must\_change\_password, failed\_attempts, locked\_until, last\_login\_at | Never deleted. Hash is argon2id or bcrypt. |
| `roles` | id, code (unique), name | Seeded: Owner, Administrator, Cashier, Collection Supervisor, Auditor, Technician, Viewer. |
| `permissions` | id, code (unique, e.g. `payment.create`), description | Code checks permissions, never role names. |
| `role_permissions` | role\_id, permission\_id | Composite primary key. |
| `user_roles` | user\_id, role\_id, assigned\_by, assigned\_at | Composite primary key. A user may hold several roles. |
| `sessions` (optional) | id, user\_id, token\_hash, device\_name, issued\_at, expires\_at, revoked\_at | Lets logout and deactivation revoke a token on the server. |
| `audit_logs` | id, occurred\_at, actor\_user\_id, action, entity\_type, entity\_id, reason, old\_values (jsonb), new\_values (jsonb), request\_id, prev\_hash, row\_hash | Insert-only. Hash columns are the optional tamper-evidence chain. No secrets in the jsonb. |
| `application_settings` | key (unique), value (jsonb), updated\_by, updated\_at | Grace period, suspension threshold, reconnection fee, GCash policy, cash tolerance (0). |
| `application_setting_changes` | id, key, old\_value, new\_value, changed\_by, changed\_at | Append-only history of settings. |
| `backup_history` | id, kind (BACKUP or RESTORE), triggered\_by, started\_at, finished\_at, file\_path, size\_bytes, checksum\_sha256, schema\_version, verification\_status, verified\_at, notes | Restore events are also written to a server log file outside the database. |
| Sequences | `invoice_no_seq`, `receipt_no_seq`, `reversal_no_seq`, `adjustment_no_seq`, `batch_no_seq` | Plain PostgreSQL sequences, not rows. Account numbers can use sequences too. |

## Subscribers, plans and service accounts

Covers F1. One subscriber owns many service accounts. Rates are snapshotted on the service account.

| Table | Key columns | Notes |
| --- | --- | --- |
| `subscribers` | id, account\_no (unique), full\_name, status (ACTIVE, INACTIVE, TERMINATED, ARCHIVED), collection\_area\_id, billing\_due\_day, notes, created\_by, created\_at | Never deleted. Status only. |
| `subscriber_contacts` | id, subscriber\_id, contact\_type (MOBILE, EMAIL, OTHER), value, is\_primary | Indexed on value for global search. |
| `subscriber_addresses` | id, subscriber\_id, address\_type (BILLING, SERVICE), line1, barangay, city, landmark, collection\_area\_id | One subscriber may have several service addresses. |
| `subscriber_documents` | id, subscriber\_id, file\_path, mime\_type, size\_bytes, sha256, uploaded\_by, uploaded\_at | Validated type and size, safe generated path. |
| `service_types` | id, code (INTERNET, CABLE, COMBO), name | Small lookup table. |
| `service_plans` | id, code (unique), name, service\_type\_id, price\_centavos, installation\_fee\_centavos, speed\_mbps (nullable), channel\_count (nullable), description, is\_active | Internet uses speed, Cable uses channel count. |
| `service_plan_prices` (optional) | id, plan\_id, price\_centavos, effective\_from | Keeps price history. Changes affect future billing only. |
| `service_accounts` | id, account\_no (unique), subscriber\_id, plan\_id, service\_address\_id, status (PENDING, ACTIVE, SUSPENDED, TERMINATED), activation\_date, billing\_start\_date, billing\_due\_day, current\_rate\_centavos, assigned\_collector\_id, created\_at | The rate is copied from the plan at creation. Status changes are mirrored in `service_events`. |

## Billing and ledger

Covers F2 and the ledger spine used by every flow.

| Table | Key columns | Notes |
| --- | --- | --- |
| `billing_cycles` | id, period\_month (date, first of month), status (DRAFT, FINALIZED), generated\_by, generated\_at, finalized\_by, finalized\_at, invoice\_count, total\_centavos | One cycle per month. |
| `invoices` | id, invoice\_no (unique, null while DRAFT), billing\_cycle\_id, service\_account\_id, subscriber\_id, invoice\_type (MONTHLY, ONE\_OFF), billing\_period (date, null for ONE\_OFF), invoice\_date, due\_date, total\_centavos, status (DRAFT, UNPAID, PARTIALLY\_PAID, PAID, VOID, CREDITED), finalized\_at, voided\_at, void\_reason | OVERDUE is derived from due date plus grace and balance. ONE\_OFF covers the reconnection fee, so it is outside the monthly uniqueness rule. |
| `invoice_items` | id, invoice\_id, item\_type (SUBSCRIPTION, INSTALLATION, RECONNECTION, DISCOUNT, PENALTY, OTHER), description, amount\_centavos (signed), plan\_id | Billed rate is preserved here. Immutable once the invoice is finalized. |
| `adjustments` | id, adjustment\_no (unique), subscriber\_id, service\_account\_id, invoice\_id (nullable), kind (DEBIT, CREDIT, WRITE\_OFF, VOID), amount\_centavos, reason, requested\_by, approved\_by, created\_at | Controlled correction after finalization. Ledger entry is written in the same transaction. |
| `ledger_entries` | id, subscriber\_id, service\_account\_id (nullable), entry\_date, posted\_at, entry\_type (INVOICE, PAYMENT, PAYMENT\_REVERSAL, ADJUSTMENT), debit\_centavos, credit\_centavos, reference\_type, reference\_id, reference\_no, description, created\_by | Append-only. Exactly one of debit or credit is above zero. Running balance is computed, not stored. |

An unapplied advance payment is the part of a payment's credit that has no allocation yet. It is derived, so it needs no extra table.

## Payments

Covers F3, F4 and F8. A partial payment is one payment header with allocation lines.

| Table | Key columns | Notes |
| --- | --- | --- |
| `payments` | id, subscriber\_id, method (CASH, GCASH, BANK\_TRANSFER, CHEQUE, OTHER), amount\_centavos (above zero), payment\_date, posted\_at, reference\_no, normalized\_reference, status (PENDING\_VERIFICATION, POSTED, REJECTED, REVERSED), received\_by, batch\_account\_id (nullable), collector\_id (nullable), notes, idempotency\_key (unique) | A GCash submission is a row with PENDING\_VERIFICATION. No ledger or allocation rows exist until POSTED. |
| `payment_verifications` | id, payment\_id, decision (VERIFIED, REJECTED, ON\_HOLD), decided\_by, decided\_at, reason | Append-only history of every decision. |
| `payment_allocations` | id, payment\_id, invoice\_id, amount\_centavos, allocated\_at, reversal\_id (nullable) | A reversal writes compensating rows with a negative amount and the reversal id. Original rows stay. |
| `payment_proofs` | id, payment\_id, file\_path, original\_name, mime\_type, size\_bytes, sha256, uploaded\_by, uploaded\_at | Safe generated path. The hash helps catch the same screenshot sent twice. |
| `payment_reversals` | id, reversal\_no (unique), payment\_id (unique), reason\_code, reason\_text, requested\_by, approved\_by, reversed\_at | One reversal per payment. Voids the receipt and writes the ledger counter-entry. |
| `receipts` | id, receipt\_no (unique), payment\_id (unique), status (ISSUED, VOIDED), issued\_by, issued\_at, voided\_at, void\_reason | Numbers never reused. Reprints can be logged in the audit trail. |

## Collections

Covers F5. Collectors are records, not logins (an assumption to confirm).

| Table | Key columns | Notes |
| --- | --- | --- |
| `collectors` | id, code (unique), full\_name, contact\_no, user\_id (nullable), is\_active | `user_id` stays empty unless collectors get a login later. |
| `collection_areas` | id, code (unique), name, is\_active | Routes such as Poblacion or Riverside. |
| `collector_assignments` | id, collector\_id, collection\_area\_id (nullable), service\_account\_id (nullable), effective\_from, effective\_to, assigned\_by | Exactly one of area or service account is set. Dated rows keep history. |
| `collection_batches` | id, batch\_no (unique), collector\_id, collection\_area\_id, batch\_date, status (OPEN, IN\_PROGRESS, SUBMITTED, REMITTED, RECONCILED, CLOSED), expected\_receivable\_centavos, created\_by, created\_at, submitted\_at, remitted\_at, closed\_by, closed\_at | Server rejects illegal state jumps. |
| `batch_accounts` | id, batch\_id, service\_account\_id, subscriber\_id, current\_bill\_centavos, arrears\_centavos, total\_due\_centavos, visit\_order, outcome (PENDING, COLLECTED, PARTIAL, UNCOLLECTED), uncollected\_reason | Snapshot at batch creation. Unique on batch plus service account. |
| `collector_remittances` | id, batch\_id, amount\_centavos, received\_by, remitted\_at, denomination\_breakdown (jsonb), notes | One row per part when a collector remits in pieces. |
| `batch_reconciliations` | id, batch\_id (unique), expected\_cash\_centavos, remitted\_cash\_centavos, difference\_centavos (signed), difference\_type (BALANCED, SHORTAGE, OVERAGE), explanation, reconciled\_by, reconciled\_at | Expected cash is computed from posted, unreversed cash payments linked to the batch. A non-zero difference needs an explanation. |
| `variance_resolutions` | id, reconciliation\_id, action (PAID\_BACK, WRITTEN\_OFF, INVESTIGATING, CORRECTED), amount\_centavos, approved\_by, resolved\_at, notes | Append-only. A shortage never touches a subscriber's ledger. |

## Operations: service history, suspension and reconnection

Covers F1 Step 7 and F7. All state changes stay in service history.

| Table | Key columns | Notes |
| --- | --- | --- |
| `service_events` | id, service\_account\_id, event\_type (CREATED, ACTIVATED, SUSPENDED, RECONNECTED, TERMINATED, PLAN\_CHANGED, COLLECTOR\_CHANGED), from\_status, to\_status, occurred\_at, actor\_user\_id, reference\_type, reference\_id, notes | Append-only history shown in the Service History tab. |
| `suspension_records` | id, service\_account\_id, status (REQUESTED, APPROVED, EFFECTIVE, CANCELLED), reason, effective\_date, requested\_by, approved\_by, approved\_at, executed\_by, executed\_at, arrears\_snapshot\_centavos, oldest\_invoice\_id, notes | The candidate list is a query, not a table. |
| `reconnection_records` | id, service\_account\_id, suspension\_id, status (REQUESTED, COMPLETED, CANCELLED), request\_date, completion\_date, technician\_user\_id, fee\_invoice\_id (or adjustment\_id), qualifying\_payment\_id, notes | At most one open record per service account. |
| `work_orders` (optional) | id, service\_account\_id, order\_type (INSTALL, SUSPEND, RECONNECT), assigned\_to, scheduled\_for, status, notes | Gives technicians a job list with no financial columns. Not required by the document. |

## Constraints and indexes

Each constraint below exists to make an acceptance test pass at the database level, not only in application code.

| Constraint or index | Table | Protects | Test |
| --- | --- | --- | --- |
| Unique `account_no` | subscribers, service\_accounts | Duplicate accounts | Minimum constraints |
| Unique `invoice_no` | invoices | Duplicate invoice numbers | AT-09 |
| Partial unique on (service\_account\_id, billing\_period) where invoice\_type is MONTHLY and status is not VOID | invoices | Duplicate billing for one period | AT-11 |
| Unique `receipt_no`, unique `payment_id` | receipts | Duplicate receipts, one receipt per payment | AT-09 |
| Partial unique on (method, normalized\_reference) where status is PENDING\_VERIFICATION, POSTED or REVERSED | payments | Duplicate GCash or bank references. REVERSED stays consumed. | AT-05 |
| Unique `payment_id` | payment\_reversals | A payment reversed only once | AT-06 |
| Check: exactly one of debit or credit above zero | ledger\_entries | Malformed ledger rows | AT-01 to AT-04 |
| Check: amounts above zero | payments, adjustments, remittances | Negative or zero money | AT-01 to AT-04 |
| Trigger: sum of allocations per payment never exceeds its amount | payment\_allocations | Over-allocation | AT-02, AT-04 |
| Triggers or revoked privileges blocking UPDATE and DELETE | ledger\_entries, audit\_logs, receipts, payment\_reversals, posted payments | Silent rewriting of history | AT-06, AT-10 |
| Unique (batch\_id, service\_account\_id) | batch\_accounts | Same account twice in a batch | AT-07 |
| Partial unique: one open record per service account | reconnection\_records, suspension\_records | Double requests | AT-09 |
| All foreign keys `ON DELETE RESTRICT` | All | Deleting owned history | Critical failure list |
| Row locks (`SELECT ... FOR UPDATE`) on invoices and payments | Posting transactions | Two PCs touching one invoice | AT-09 |

Indexes the document names or the flows need:

- **Search:** trigram index on subscriber name (`pg_trgm`), index on `account_no`, contact value, address text, `invoice_no`, `receipt_no` and `normalized_reference`.
- **Receivables:** `invoices (due_date, status)`, `invoices (subscriber_id, status)`, `payment_allocations (invoice_id)`.
- **Ledger:** `ledger_entries (subscriber_id, entry_date, id)` for statements and running balances.
- **Payments and reports:** `payments (payment_date)`, `payments (subscriber_id)`, `payments (batch_account_id)`.
- **Collections:** `collection_batches (collector_id, status)`, `collector_assignments (collector_id, effective_from)`, `service_accounts (collection area, collector, status)`.
- **Audit:** `audit_logs (occurred_at)`, `audit_logs (entity_type, entity_id)`, `audit_logs (actor_user_id)`.

## Agreed decisions that change the schema

Agreed on 2026-10-09: every default below is adopted. Build the Phase 4 migrations on them, and change a row only through a documented design change.

| Decision | Agreed default | Schema impact |
| --- | --- | --- |
| Advance payment (AT-03) | Surplus stays on the ledger as credit and is auto-applied by allocation when the next invoice is finalized | None. Uses `payment_allocations`. |
| Duplicate GCash reference (AT-05) | Hard block for verified or reversed references, warn for pending, rejected or soft matches | The partial unique index on `normalized_reference`. |
| Combo plan | One service account on a COMBO plan, one invoice line | `service_accounts.plan_id` only. Two accounts would double the rows. |
| Suspended accounts and billing | Not billed while suspended | Billing eligibility query only. |
| Reconnection and installation fees | One-off invoice type outside the monthly uniqueness rule | `invoices.invoice_type`. |
| OVERDUE status | Derived from due date, grace and balance, never stored | Status column holds only base states. |
| GCash submission storage | A `payments` row with status PENDING\_VERIFICATION | Avoids a second table and keeps the unique guard in one place. |
| Reversal of allocations | Compensating negative allocation rows linked to the reversal | `payment_allocations.reversal_id`. |
| Collector login | No. Staff record collections from the route sheet. | `collectors.user_id` stays nullable. |
| Period locking | Optional later. Corrections go into the current period. | Optional `accounting_periods` table. |
| Primary keys | `BIGINT` identity plus human-readable unique numbers | All tables. |
| Cashier cash count | Not required. Add only if the instructor wants it. | Optional `cashier_shifts` table. |

## Migration build order

Every schema change is a Drizzle migration. This order lets each phase checkpoint demonstrate working tables.

| Phase | Migrations to write | Seeds |
| --- | --- | --- |
| 1. Foundation | Extensions (`pg_trgm`), shared enums, health check table or endpoint | None |
| 2. Authentication and RBAC | `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `sessions`, `audit_logs`, `application_settings`, `application_setting_changes` | Roles, permissions, Owner from environment variable |
| 3. Plans, subscribers, services | `collection_areas`, `collectors`, `service_types`, `service_plans`, `subscribers`, contacts, addresses, documents, `service_accounts`, `service_events`, `collector_assignments` | 3 Internet, 2 Cable, 2 Combo plans, 3 areas, 2 collectors, 50 subscribers, 60+ service accounts |
| 4. Billing and ledger | Sequences, `billing_cycles`, `invoices`, `invoice_items`, `adjustments`, `ledger_entries`, no-update triggers | 3 billing months of invoices |
| 5. Payments | `payments`, `payment_verifications`, `payment_allocations`, `payment_proofs`, `payment_reversals`, `receipts`, unique indexes for references | Mixed cash, GCash, partial, exact and advance payments, one reversal |
| 6. Collections | `collection_batches`, `batch_accounts`, `collector_remittances`, `batch_reconciliations`, `variance_resolutions` | One balanced batch and one shortage batch |
| 7. Receivables and service control | `suspension_records`, `reconnection_records`, optional `work_orders`, aging and receivable views | 10 overdue accounts across buckets, 2 suspension or reconnection scenarios |
| 8. Reports | Read-only views, report indexes, optional month-end snapshot table | None |
| 9. Hardening | `backup_history`, revoked privileges for the application database role, audit hash chain, integrity-check queries | None |
| 10. QA | Test fixtures only, no new tables | Final demo dataset and acceptance-test data |

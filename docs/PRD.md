# BCIS Product Requirements Document

Oct 9, 2026 · @michaela

> Save this doc as `docs/PRD.md`. It is the source of truth for Claude Code. `CLAUDE.md` in the repo root summarizes it, and `docs/phases/phase-01…10` break the build into checkpoints.

## 1. Purpose, scope and document map

BCIS is a LAN-hosted subscription billing and collection system for Bukidnon Cable and Internet Services. It bills Internet, Cable and Combo subscribers monthly, records Cash and GCash payments, keeps a permanent subscriber ledger, tracks collector cash, monitors receivables and produces audit-ready reports. Three office PCs use it at the same time. It comes from a laboratory activity graded on 100 points plus mandatory acceptance tests. A correct financial result matters more than visual polish.

**In scope**

- Subscribers, service accounts, plans, collection areas and collectors.
- Monthly billing, invoices, adjustments and the subscriber ledger.
- Cash, GCash, bank transfer, cheque and other payments, allocation, receipts and reversals.
- GCash proof verification with duplicate detection.
- House-to-house collection batches, remittance and reconciliation.
- Receivables, aging, suspension and reconnection.
- Dashboard, reports with PDF and XLSX export, statements of account and print layouts.
- Users, roles, permissions, audit trail, backup, verification and restore.

**Out of scope (do not build)**

Collector mobile app or PWA, SMS or email reminders, customer self-service portal, online payment gateway or GCash API integration, QR receipts, GIS mapping, technician work orders and equipment inventory, churn analytics. These are optional enhancements, considered only after every mandatory requirement is complete and tested.

**Requirement IDs**

| Prefix | Meaning |
| --- | --- |
| `INV-nn` | Financial or security invariant. Never violate, always test. |
| `FR-<MODULE>-nnn` | Functional requirement. Modules: SHL, SUB, BIL, PAY, GCA, COL, REC, SVC, RPT, SEC, BAK, UI. |
| `NFR-nn` | Non-functional requirement. |
| `AT-nn` | Mandatory acceptance test from the laboratory activity. |

**Source column.** `LAB` means the laboratory document states it. `DEC` means it is a design decision or recommended control agreed for this project. A `DEC` item may be refined, a `LAB` item may not be dropped.

**Rubric tags.** Every requirement carries the grading criterion it supports, so test evidence can cite it.

| Tag | Criterion | Points |
| --- | --- | --- |
| A | Requirements and architecture | 10 |
| B | Database and data integrity | 15 |
| C | Billing, ledger and payment correctness | 20 |
| D | Collection and receivables | 12 |
| E | UI/UX and professional design | 12 |
| F | Security, RBAC and audit | 10 |
| G | Reports and management dashboard | 8 |
| H | Testing and quality | 8 |
| I | Documentation and deployment | 3 |
| J | Demonstration and code understanding | 2 |

**Critical failure conditions (score may be capped).** Destructive deletion of posted payments. Obviously incorrect ledger balances. A shared database-file architecture. Plaintext passwords. The desktop client or renderer accessing the database directly. No server-side authorization checks. Inability to restore a backup. A submission that is only static UI and CRUD without the financial workflows.

## 2. Roles and permissions

Code checks **permission codes**, never role names. Roles, permissions, `role_permissions` and `user_roles` are database tables seeded from this section. Every permission is enforced on the server (see INV-17).

| Role | Main responsibilities |
| --- | --- |
| Owner (Super Admin) | Dashboards, approvals, configuration, users, audit, backup and restore. Holds every permission. |
| Administrator | Subscribers, plans, service accounts, billing, collections, operational reports. No user management, settings or backup. |
| Cashier | Subscriber search, receive payments, issue receipts, record GCash proofs, view balances. |
| Collection Supervisor | Areas, routes, collectors, batches, remittance, reconciliation, collector performance. |
| Accounting / Auditor | Reports, adjustment and reversal review, receivables, audit trail. Read-only on money. |
| Technician | Service account operational information, suspension and reconnection jobs. No balances, invoices or payments. |
| Read-only Viewer | Dashboard and reports. No mutation. |

Permission matrix to seed (✔ = granted):

| Permission | Owner | Admin | Cashier | Coll. Sup. | Auditor | Tech | Viewer |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `dashboard.view` | ✔ | ✔ |  | ✔ | ✔ |  | ✔ |
| `subscriber.view` | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `subscriber.manage` | ✔ | ✔ |  |  |  |  |  |
| `plan.manage` | ✔ | ✔ |  |  |  |  |  |
| `service.view` (operational fields only) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `service.manage` | ✔ | ✔ |  |  |  |  |  |
| `billing.view` | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `billing.generate` | ✔ | ✔ |  |  |  |  |  |
| `invoice.adjust` | ✔ | ✔ |  |  |  |  |  |
| `invoice.void` | ✔ | ✔ |  |  |  |  |  |
| `payment.view` | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `payment.create` | ✔ | ✔ | ✔ |  |  |  |  |
| `payment.allocate_manual` | ✔ | ✔ |  |  |  |  |  |
| `payment.backdate` | ✔ | ✔ |  |  |  |  |  |
| `gcash.record` | ✔ | ✔ | ✔ |  |  |  |  |
| `gcash.verify` | ✔ | ✔ |  |  |  |  |  |
| `payment.reverse.request` | ✔ | ✔ | ✔ |  |  |  |  |
| `payment.reverse` | ✔ | ✔ |  |  |  |  |  |
| `receipt.reprint` | ✔ | ✔ | ✔ |  |  |  |  |
| `collection.manage` | ✔ | ✔ |  | ✔ |  |  |  |
| `collection.record` | ✔ | ✔ | ✔ | ✔ |  |  |  |
| `collection.remit` | ✔ | ✔ | ✔ | ✔ |  |  |  |
| `collection.reconcile` | ✔ |  |  | ✔ |  |  |  |
| `collection.close` | ✔ | ✔ |  |  |  |  |  |
| `receivable.view` | ✔ | ✔ |  | ✔ | ✔ |  | ✔ |
| `service.suspend.request` | ✔ | ✔ |  |  |  |  |  |
| `service.suspend.approve` | ✔ |  |  |  |  |  |  |
| `service.execute` | ✔ | ✔ |  |  |  | ✔ |  |
| `report.view` | ✔ | ✔ | ✔ | ✔ | ✔ |  | ✔ |
| `report.export` | ✔ | ✔ |  |  | ✔ |  |  |
| `audit.view` | ✔ |  |  |  | ✔ |  |  |
| `user.manage` | ✔ |  |  |  |  |  |  |
| `role.manage` | ✔ |  |  |  |  |  |  |
| `settings.manage` | ✔ |  |  |  |  |  |  |
| `backup.create` | ✔ |  |  |  |  |  |  |
| `backup.restore` | ✔ |  |  |  |  |  |  |

Rules:

- A Cashier's `report.view` covers only the Statement of Account and their own daily close-out.
- The Technician's server responses omit balances, invoices and payments entirely. Hiding them in the view is not enough.
- The last active Owner cannot be deactivated or lose the Owner role.
- The person who recorded a GCash proof or posted a payment cannot be the approver of its verification or reversal.
- A user cannot grant a permission they do not hold.

## 3. Architecture and stack decisions

**Non-negotiable.** Laravel (latest stable) with Livewire serves both the backend and the UI from ONE central server machine. The database is PostgreSQL only, never SQLite. `/desktop` holds a minimal Electron shell that opens `http://<SERVER_URL>/` in a window and never connects to the database.

&#91;embedded content: architecture · 3 clients, 1 server, 1 database\]

The three office PCs are clients only. The server owns authentication, authorization, validation, transactions, auditing, file storage and reports.

**Stack**

| Layer | Choice | Notes |
| --- | --- | --- |
| Server framework | Laravel, latest stable at project start | Version pinned by `composer.lock`. |
| UI | Livewire (latest stable that matches Laravel), Blade, Alpine.js, Tailwind CSS | Server-rendered. Livewire components stay thin. Do not add a paid UI kit. |
| Database | PostgreSQL | Laravel `pgsql` driver. Tests also run on PostgreSQL. |
| Domain logic | `app/Domain/<Module>` Action and Service classes | All business rules live here, never in Blade or Livewire components. |
| Money | Integer centavos with a `Money` value object and Eloquent casts | No floats. |
| Validation | Form Requests and Livewire validation rules | Server-side only. Inputs from Livewire public properties are untrusted. |
| Authentication | Laravel session auth, database session driver, argon2id hashing, `RateLimiter` for lockout |  |
| Authorization | Gates and Policies keyed on permission codes from the `roles`, `permissions`, `role_permissions`, `user_roles` tables | Custom tables match the laboratory data model. |
| Reports | PDF with `barryvdh/laravel-dompdf` (or `spatie/laravel-pdf`), XLSX with `maatwebsite/excel` (or `openspout`) | Large exports run as queued jobs. |
| Queue and scheduler | Laravel queue (database driver) and scheduler | Backups, exports, integrity checks. |
| Logging | Monolog daily JSON channel with a redaction processor and a request id |  |
| Testing | Pest with Livewire test helpers, PostgreSQL test database, Playwright for end-to-end | Parallel-process script for AT-09. |
| Code quality | Laravel Pint, Larastan level 6 or higher, ESLint for `/desktop` |  |
| Desktop shell | Electron and electron-builder in `/desktop` | Windows installer. |

**Laboratory requirement mapped to this stack**

| Laboratory item | How this project meets it |
| --- | --- |
| Electron clients → Fastify API → PostgreSQL | Electron shells → Laravel and Livewire server → PostgreSQL. The server is the only data path. There is no separate REST API. |
| Server-side authorization even when the API is called directly (AT-10) | Policies on routes, controllers and every Livewire action. Tests call HTTP routes and Livewire actions as a Cashier and expect refusal. |
| Zod validation of all external input | Form Requests, Livewire validation and domain-level checks. Livewire public properties are re-validated and re-authorized inside each action. |
| Narrow typed preload API | The shell exposes nothing to the page. No preload that grants Node or file access. |
| Drizzle migrations | Laravel migrations, with raw SQL for triggers, partial indexes and sequences. |
| TanStack tables and Query | Livewire tables with server-side pagination, sorting and filtering. |
| pdfmake and ExcelJS | dompdf and Laravel Excel. |
| Pino structured logs | Monolog JSON logs with a request id. |
| Vitest and Playwright Electron | Pest feature tests and Playwright against the web app. |

The shell requirements are FR-SHL-001 to FR-SHL-007 in section 12.

## 4. Domain glossary

| Term | Meaning |
| --- | --- |
| Subscriber | The customer. Owns one or more service accounts. Never deleted. |
| Service account | One billable service (Internet, Cable or Combo) at one service address, with its own plan, rate, status and collector. |
| Plan | A priced offer with a code. Price changes affect future billing only. |
| Billing cycle | One calendar month of invoice generation, DRAFT then FINALIZED. |
| Invoice | A numbered bill for one service account and period. Immutable once finalized. |
| Adjustment | A controlled debit, credit, write-off or void that changes what is owed without editing the invoice. |
| Ledger entry | One append-only debit or credit row on a subscriber's ledger. |
| Payment | Money received from or for a subscriber. Header row only. |
| Allocation | The part of a payment applied to one invoice. A partial payment is one payment with one or more allocations. |
| Customer credit | Payment value not yet allocated to any invoice (an advance payment). Derived, auto-applied to the next finalized invoice. |
| Receipt | The numbered document for a posted payment. A voided receipt keeps its number. |
| Reversal | A linked counter-entry that undoes a posted payment. The original stays visible. |
| GCash submission | A GCash proof recorded by staff, in PENDING\_VERIFICATION until an authorized person verifies it. |
| Collector | A person who collects house to house. A record, not a login. |
| Collection batch | A set of subscriber accounts handed to one collector for one run, with a snapshot of what each owes. |
| Remittance | Cash a collector hands to the office for a batch. |
| Reconciliation | Comparing expected cash (from posted payments) with remitted cash and recording any difference. |
| Shortage / overage | Remitted cash below / above expected cash. Always recorded explicitly. |
| Suspension | A recorded, approved decision to cut a service account for non-payment. |
| Reconnection | A request, fee and technician job that restores a suspended service after a qualifying payment. |
| Aging bucket | Current, 1–30, 31–60, 61–90 or 90+ days past due, counted on the remaining invoice balance. |
| Grace period | Days after the due date before an invoice counts as OVERDUE. Configurable. |
| Centavo | The unit of all stored money. ₱1,499.00 is stored as `149900`. |
| Business date | A calendar date in Asia/Manila, such as a due date or payment date. |
| Posted | A financial record that has taken effect. Posted records are never edited or deleted. |

## 5. Financial and security invariants

These rules hold at all times. Each needs a test, and a change that breaks one is a defect, not a trade-off.

| ID | Rule | Enforced by | Rubric |
| --- | --- | --- | --- |
| INV-01 | All money is an integer number of centavos. No float arithmetic anywhere, including PDF and XLSX building. | Money value object, column types, code review | B, C |
| INV-02 | `ledger_entries` is append-only. A correction is a new row. | Database triggers or revoked privileges, no update routes | B, C |
| INV-03 | A subscriber's balance equals the sum of debits minus the sum of credits. The running balance recomputed from entries equals what is displayed. | Query, integrity check, tests | C |
| INV-04 | A posted payment is never updated or deleted. It is corrected by reversal. | No routes, triggers, tests | B, C |
| INV-05 | A finalized invoice and its items are immutable. Changes go through adjustment or void. | Triggers, domain service, tests | B, C |
| INV-06 | Invoice balance equals total plus adjustments minus net allocations, and never goes below zero. Status is recomputed from it in the same transaction. | Domain service, tests | C |
| INV-07 | Payment amount equals the sum of its allocations plus its unapplied credit. Allocations never exceed the payment. No value is lost. | Trigger and domain check, tests | C |
| INV-08 | Default allocation is oldest due date first, ties by invoice id. Manual allocation needs `payment.allocate_manual` and a reason. | Allocation service, tests | C |
| INV-09 | One non-void MONTHLY invoice per service account per billing period. | Partial unique index | B, C |
| INV-10 | Invoice, receipt, reversal, adjustment and batch numbers come from PostgreSQL sequences, are unique and are never reused. Gaps are allowed. | Sequences plus unique indexes | B |
| INV-11 | Every posting operation (billing finalization, payment, reversal, verification, remittance, reconciliation) is one database transaction with row locks. All or nothing. | `DB::transaction`, `lockForUpdate`, tests | B, C |
| INV-12 | Only verified payments are posted and allocated. An uploaded image never marks an account paid. | Status model, tests | C |
| INV-13 | A payment reference cannot be reused according to the duplicate policy, guarded by a database index. | Partial unique index, tests | B, C |
| INV-14 | A payment is reversed at most once. The reversal links the original, and the receipt becomes VOIDED with its number still reserved. | Unique index, tests | C |
| INV-15 | A collector difference is never silently balanced. A shortage or overage is recorded and never changes a subscriber ledger. | Domain rules, tests | D |
| INV-16 | AR aging total equals the sum of outstanding invoice balances, and equals the sum of ledger balances apart from stated customer credits. | Report tie-out tests | C, D, G |
| INV-17 | Every action is authorized on the server by permission. Hiding a control is never authorization. Livewire public properties are untrusted input. | Policies and Gates in every action, tests | F |
| INV-18 | Every posted financial mutation writes an audit record in the same transaction. Audit rows are insert-only and hold no secrets. | Audit service, DB privileges, tests | F |
| INV-19 | Subscribers, service accounts, users, invoices, payments and receipts are never deleted. Status columns only. | `ON DELETE RESTRICT`, no delete routes | B |
| INV-20 | A plan price change affects future billing only. Invoices and service accounts keep the rate they were billed. | Rate snapshots, tests | C |
| INV-21 | Service status history is append-only. | Triggers, tests | B |
| INV-22 | Business dates use Asia/Manila. Timestamps are stamped by the server, never the client. | App timezone config | B |
| INV-23 | Report totals tie out: collections equal payments equal ledger credits, billing equals ledger debits, breakdowns foot to their headline. | Report tie-out tests | G |
| INV-24 | Passwords are hashed with argon2id (or bcrypt) and never stored, logged or returned in plaintext. | Hashing config, log redaction, tests | F |
| INV-25 | The desktop shell and the browser never access the database. Only the Laravel server does. | Architecture, network rules | A, F |

## 6. Requirements: subscribers, plans and service accounts

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-SUB-001 | Create and maintain a subscriber: unique account number, full name, contacts, addresses, collection area, billing due day, status, notes. | A duplicate account number is rejected by the database. Required fields show inline errors. | B | LAB |
| FR-SUB-002 | One subscriber can own many service accounts and service addresses. | A subscriber with two service accounts at different addresses is billed separately for each. | B | LAB |
| FR-SUB-003 | Global search by account number, name, contact number, address, receipt number, invoice number and GCash reference. | Each key finds the record. Results are paginated on the server and stay fast at 20,000 subscribers (trigram and B-tree indexes). | B, E | LAB |
| FR-SUB-004 | Subscribers are never deleted. Statuses: ACTIVE, INACTIVE, TERMINATED, ARCHIVED. | No delete route exists. Foreign keys are `ON DELETE RESTRICT`. Each status change is audited. | B | LAB |
| FR-SUB-005 | Plans have code, name, service type (Internet, Cable, Combo), price, fees, description and active flag. Internet plans carry speed, Cable plans carry channel count. | Inactive plans cannot be chosen for a new service account. | B | LAB |
| FR-SUB-006 | A plan price change affects future billing only. | After a price change, existing service accounts and every finalized invoice keep their old amounts (INV-20). | C | LAB |
| FR-SUB-007 | A service account stores unique account number, plan, service address, activation date, billing start date, due day, current rate (copied from the plan), status and assigned collector. | Rate is a snapshot. PENDING accounts are not billed. A Combo plan is one service account. | B, C | LAB |
| FR-SUB-008 | Every service status change appends a `service_events` row with actor, time and reason. | Service History tab lists the full sequence. Rows cannot be updated or deleted. | B | LAB |
| FR-SUB-009 | Subscriber profile has tabs: overview, services, billing, payments, ledger, collection, service history, documents, audit. | All nine tabs load with server-side pagination and respect permissions. | E | LAB |
| FR-SUB-010 | Subscriber documents are validated by content type (PDF, JPG, PNG) and size (default 5 MB), stored on a private disk under a generated name and served through an authorized controller. | A renamed `.exe` is rejected. A direct URL to the file path does not work. | F | LAB |
| FR-SUB-011 | Collector assignments are dated. Changing a collector never rewrites past batches or reports. | Reassign a subscriber, and an earlier batch still shows the original collector. | D | DEC |
| FR-SUB-012 | The registration form warns about a probable duplicate (same name and address, or same contact number) and offers to reuse or reactivate the existing subscriber. | Entering a known contact number shows the warning with a link. | E | DEC |
| FR-SUB-013 | Technician responses contain operational fields only. | The Technician's service view returns no balance, invoice or payment field, checked at the response level. | F | LAB |

## 7. Requirements: billing and ledger

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-BIL-001 | Generate monthly invoices for billable service accounts, with a preview first. | Preview shows accounts to bill, accounts skipped with the reason, and the total. Nothing is saved until Generate. | C | LAB |
| FR-BIL-002 | Eligibility: status ACTIVE and billing start date on or before the period. PENDING, SUSPENDED, TERMINATED and INACTIVE accounts and accounts already billed for the period are skipped. | A suspended account appears under "skipped, suspended" (agreed decision). | C | DEC |
| FR-BIL-003 | Duplicate billing is impossible. | Running generation twice, or from two PCs at once, leaves one non-void MONTHLY invoice per service account and period (AT-11, INV-09). | B, C | LAB |
| FR-BIL-004 | Generate creates DRAFT invoices and items inside a billing cycle. DRAFT invoices can be discarded and regenerated. | A FINALIZED invoice cannot be discarded or regenerated. | C | DEC |
| FR-BIL-005 | Finalize in one transaction: assign `invoice_no` from the sequence, set status UNPAID, post the ledger debit, write the audit record. | A forced failure mid-finalization leaves no numbered invoice and no ledger row (INV-11). | B, C | LAB |
| FR-BIL-006 | Invoice items: subscription charge, installation fee, reconnection fee, discount, penalty (if enabled), adjustments. Total equals the sum of items. | Item sum equals `total_centavos` for every invoice in the seed data. | C | LAB |
| FR-BIL-007 | Due date comes from the billing due day. If the month is shorter, use its last day. | Due day 31 in November gives November 30. | C | DEC |
| FR-BIL-008 | Invoice states: DRAFT, UNPAID, PARTIALLY\_PAID, PAID, VOID, CREDITED. OVERDUE is derived from due date, grace period and balance. | No stored OVERDUE value can disagree with the aging report. | C | LAB |
| FR-BIL-009 | Finalized invoices are immutable. Corrections use adjustment or void with reason, approval, ledger counter-entry and audit. | An attempt to update a finalized invoice fails at the database level (INV-05). | B, C | LAB |
| FR-BIL-010 | One-off invoices (`invoice_type` ONE\_OFF) carry installation and reconnection fees and are outside the monthly uniqueness rule. | A reconnection fee invoice can coexist with the monthly invoice of the same month. | C | DEC |
| FR-BIL-011 | A penalty, when enabled in settings (default off), is a separate charge. It never edits a finalized invoice. | Enabling the setting adds a penalty item to a future invoice only. | C | DEC |
| FR-BIL-012 | Customer credit (advance payment) is applied automatically, oldest first, when an invoice is finalized, by writing allocation rows. | After AT-03, the next finalized invoice shows the credit allocated and the right remaining balance. | C | DEC |
| FR-BIL-013 | Invoice and adjustment numbers are never reused. Gaps are allowed. | A voided invoice keeps its number, and the sequence continues. | B | LAB |
| FR-BIL-014 | The subscriber ledger shows a chronological debit and credit history with a reproducible running balance and a date filter. | Running balance recomputed from entries equals the displayed value for every seeded subscriber (INV-03). | C | LAB |
| FR-BIL-015 | Statement of Account for a selected date range, with opening balance, closing balance and a print layout. | Opening plus debits minus credits equals closing for any range. | C, G | LAB |
| FR-BIL-016 | The running balance is computed (window function), not stored as the authority. | An integrity query recomputes balances and reports zero differences. | B, C | DEC |

## 8. Requirements: payments, allocation, receipts and GCash

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-PAY-001 | Receive Payment screen built for cashier speed: search, balances, amount, method, allocation preview, post, print receipt. | A cash payment completes without leaving the screen, and the preview matches what posts. | E | LAB |
| FR-PAY-002 | Methods: Cash, GCash, Bank Transfer, Cheque, Other. Capture payment date, amount, receipt number, actor, method, reference, notes and optional proof. | All fields stored. Reference required for every non-cash method. | C | LAB |
| FR-PAY-003 | Exact, partial and advance payments all work, and no value is lost. | AT-01, AT-02 and AT-03 pass. Payment amount equals allocations plus unapplied credit (INV-07). | C | LAB |
| FR-PAY-004 | Default allocation is oldest due date first. | AT-04 passes: August ₱999 and September ₱999 with ₱1,200 leaves August ₱0 and September ₱798. | C | LAB |
| FR-PAY-005 | Manual allocation needs `payment.allocate_manual` and a reason. The total must equal the amount, no line may exceed an invoice balance, and every line is positive. | A Cashier without the permission is rejected on the server. The override is audited. | C, F | LAB |
| FR-PAY-006 | One allocation function produces both the preview and the real posting. | A test asserts preview output equals posted allocations for many random cases. | C, H | DEC |
| FR-PAY-007 | Posting is one transaction: lock invoice rows, re-check balances, insert payment, allocations, ledger credits, receipt and audit. An idempotency key makes a double submit post once. | A double click, and a forced failure mid-post, each leave exactly zero or one complete payment. | B, C | LAB |
| FR-PAY-008 | For cash, only the applied amount is stored as the payment. Change given is a screen aid, never stored. | A ₱2,000 tender on a ₱1,999.00 invoice stores 199900 centavos. | C | DEC |
| FR-PAY-009 | References are normalized (trimmed, spaces removed, upper case) and checked for duplicates according to the agreed policy. | AT-05 passes. A race between two PCs leaves one record because of the unique index. | B, C | LAB |
| FR-PAY-010 | Receipt numbers are unique, come from a sequence, and print in a dedicated layout. Reprints are marked COPY and audited. A voided receipt shows a VOIDED watermark and keeps its number. | Two PCs posting at once never produce the same receipt number (AT-09). | B, C | LAB |
| FR-PAY-011 | Payment date is the date the money was received. The posting timestamp is stored separately. Backdating needs `payment.backdate`. | A corrected payment keeps the original receipt date, so the subscriber is not penalized for an office error. | C | DEC |
| FR-PAY-012 | Reversal needs reason category, explanation, impact preview and an approver with `payment.reverse`. It writes a payment\_reversals row, compensating negative allocations, a ledger debit and voids the receipt. | AT-06 passes. The original stays visible. A second reversal of the same payment is rejected (INV-14). | B, C, F | LAB |
| FR-PAY-013 | Reversal flags dependent records: collection batch expected cash, reconnection eligibility, consumed GCash reference. | A reversed payment inside a batch appears as an exception, and expected cash drops accordingly. | C, D | DEC |
| FR-PAY-014 | Payment History lists payments with server-side filters and shows REVERSED rows with a link to the reversal. | Filters by date, method, cashier, subscriber, reference and status work and paginate. | E | LAB |
| FR-PAY-015 | A cheque posts when recorded. A dishonored cheque is reversed with the reason "dishonored", and any bank charge is a separate authorized debit. | A reversed cheque restores balances and keeps its reference consumed. | C | DEC |
| FR-GCA-001 | Staff record a GCash submission: subscriber, reference, sender name, masked mobile number, amount, transaction date and time, proof image, notes. Status is PENDING\_VERIFICATION. | No ledger, allocation or receipt row exists for a pending submission (INV-12). | C | LAB |
| FR-GCA-002 | Proof upload is validated by content type (JPG, PNG, PDF) and size, stored under a generated path with a SHA-256 hash. | A renamed non-image is rejected. The stored path never contains the user's filename. | F | LAB |
| FR-GCA-003 | Duplicate detection checks the normalized reference, the image hash and a soft match (amount, sender, near time). Verified or reversed references are blocked. Pending, rejected and soft matches warn. | Re-entering a verified reference is blocked with a message naming the earlier receipt. | B, C | LAB |
| FR-GCA-004 | GCash Verification is a two-pane queue: list on the left, proof preview, details, duplicate warnings, balances and verification controls on the right. | Pending items older than one day are highlighted. | E | LAB |
| FR-GCA-005 | Verify, reject or hold. Reject needs a reason. Verifier identity and timestamp are always kept. | Each decision adds a `payment_verifications` row. Rejected records and proofs stay forever. | C, F | LAB |
| FR-GCA-006 | The person who recorded a submission cannot verify it. | The server rejects self-verification. | F | DEC |
| FR-GCA-007 | Verification posts the payment through the same transaction as FR-PAY-007, with an allocation preview. | Verified and posted happen together or not at all. | B, C | LAB |
| FR-GCA-008 | Payment date is the GCash transaction date. Verification time is stored separately. | A payment made before the due date but verified after it is not treated as late. | C | DEC |
| FR-GCA-009 | Receive Payment and the subscriber profile show a notice for any pending GCash submission. | A pending ₱1,200.00 submission shows a banner on the subscriber's pay screen. | E | DEC |
| FR-GCA-010 | A daily report compares verified GCash totals with a field for the wallet statement total and highlights any difference. | The report shows verified total, entered wallet total and difference. | G | DEC |

## 9. Requirements: collections and remittance

Collectors are records, not users. Staff record collections from the printed route sheet.

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-COL-001 | Maintain collection areas and routes, collectors and dated assignments of areas or service accounts to collectors. | A collector change keeps earlier assignments readable by date. | D | LAB |
| FR-COL-002 | Create a batch for a collector and area. In one transaction it creates the batch in OPEN, one batch account per qualifying account with a snapshot of current bill, arrears and total due, and the expected receivable total. | Totals equal the sum of snapshot rows. Later live balance changes do not alter the snapshot. | D | LAB |
| FR-COL-003 | An account cannot be in two active batches for the same period. | The second batch creation blocks or warns, as configured. | D | DEC |
| FR-COL-004 | Printable route sheet: account, name, address, current bill, arrears, total due, blank columns for amount and notes. Clean print layout without navigation. | Printing shows no menus or buttons. | D, E | LAB |
| FR-COL-005 | Batch states: OPEN, IN\_PROGRESS, SUBMITTED, REMITTED, RECONCILED, CLOSED. The server rejects any other transition. | An API-level test tries every illegal jump and each fails. | D | LAB |
| FR-COL-006 | Recording a collection posts a normal payment through FR-PAY-007, linked to the batch account and collector, with the actual collection date. | The batch payment has the same allocation, ledger and receipt result as a counter payment. | C, D | LAB |
| FR-COL-007 | A batch account can be marked uncollected with a reason, or partial. | Reasons are required and appear in the collector report. | D | DEC |
| FR-COL-008 | GCash collected in the field follows GCash verification. Until verified it shows as pending non-cash and is not counted as collected. | A pending item changes no totals. | C, D | DEC |
| FR-COL-009 | Submit requires every batch account to have an outcome. | Submitting with a blank account fails with a clear message. | D | DEC |
| FR-COL-010 | Remittance records batch, amount, receiver, date, notes and optional denomination breakdown. Several remittances may belong to one batch. | The batch remitted total is the sum of its remittance rows. | D | LAB |
| FR-COL-011 | Reconciliation screen shows expected cash, remitted cash, difference, non-cash totals, accounts collected and exceptions. | Every figure on the screen ties to rows. | D, E | LAB |
| FR-COL-012 | Expected cash is the sum of posted, unreversed CASH payments linked to the batch. It never comes from what the collector reports. | Reversing a batch payment lowers expected cash. | D | DEC |
| FR-COL-013 | A non-zero difference needs an explanation and is stored as SHORTAGE or OVERAGE. It can never be saved as balanced. | AT-07: difference ₱0 reconciles. AT-08: shortage ₱500 shows and cannot close as balanced. | D | LAB |
| FR-COL-014 | Variance resolutions (paid back, written off, investigating, corrected) are append-only and need approval. | A shortage never changes any subscriber ledger. | D | DEC |
| FR-COL-015 | Closing needs `collection.close` and an explicit confirmation. A closed batch is read-only. Later corrections are reversals recorded as exceptions in the current period. | Editing a closed batch fails on the server. | D | LAB |
| FR-COL-016 | The person who counts the cash and the person who entered the collections should differ. The system warns when they are the same. | The warning appears on the reconciliation screen. | D, F | DEC |
| FR-COL-017 | Collector reports: assignment, collection, remittance, shortage and overage, performance. | Totals tie to batches and payments. | D, G | LAB |

## 10. Requirements: receivables, suspension and reconnection

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-REC-001 | Dashboard receivable KPIs: current receivable, overdue receivable, subscribers overdue, accounts for follow-up or suspension. | Values equal the aging report totals. | D, G | LAB |
| FR-REC-002 | Outstanding list of subscribers with a balance, with server-side paging, sorting and export. | Never loads the full dataset in the browser. | D, E | LAB |
| FR-REC-003 | Overdue list columns: subscriber, service, area, collector, months unpaid, oldest unpaid invoice, last payment, total arrears. | All eight columns present and correct on seeded data. | D | LAB |
| FR-REC-004 | Aging buckets Current, 1–30, 31–60, 61–90 and 90+ days, based on due date and remaining balance. A partial payment ages only the remaining amount. | Bucket totals equal total outstanding (INV-16). | C, D | LAB |
| FR-REC-005 | Filters: collector, area, plan, service type, delinquency age. | Each filter, and combinations, return correct rows from indexed queries. | D | LAB |
| FR-REC-006 | Overdue and age are derived from due date, grace period and balance. | Changing the grace setting changes the lists immediately, with no data rewrite. | C, D | DEC |
| FR-REC-007 | Aging supports an as-of date, computed from dated allocations and reversals. | A payment dated after the as-of date does not affect that report. | D, G | DEC |
| FR-REC-008 | Customer credit is shown on its own line and is not netted silently. | A subscriber with credit shows both arrears and credit. | C | DEC |
| FR-SVC-001 | Grace period and suspension threshold are configurable settings. | Changing a setting needs `settings.manage`, is audited and needs no deployment. | D, F | LAB |
| FR-SVC-002 | Suspension Candidates is a query that proposes accounts meeting the threshold. It never changes status. | A candidate stays Active until a person records a decision. | D | LAB |
| FR-SVC-003 | A suspension record stores reason, effective date, approved by, notes and a snapshot of arrears and oldest invoice. | All fields required by the laboratory document are present. | D | LAB |
| FR-SVC-004 | The Administrator requests, the Owner approves. The server re-checks the threshold at approval time. | A paid-up account cannot be approved for suspension. | D, F | DEC |
| FR-SVC-005 | The service account becomes SUSPENDED only when the Technician (or Administrator) confirms the disconnection. | Approval alone leaves the account ACTIVE. | D | DEC |
| FR-SVC-006 | Suspension does not change the ledger or invoices. | Balances before and after are identical. | C | DEC |
| FR-SVC-007 | Reconnection record: request date, fee, technician assignment, completion date, linked qualifying payment. | Completion sets the account ACTIVE and adds a service event. | D | LAB |
| FR-SVC-008 | The qualifying-payment rule is a setting. Default: all arrears plus the reconnection fee. The server computes eligibility from current balances. | A partial payment below the rule leaves the account suspended. | D | DEC |
| FR-SVC-009 | The reconnection fee is a ONE\_OFF invoice posted to the ledger and paid like any other charge. | The fee appears on the ledger and the receipt. | C | DEC |
| FR-SVC-010 | If the qualifying payment is reversed, the open reconnection is flagged for review. | The flag appears on the Administrator's dashboard. | D | DEC |
| FR-SVC-011 | Every suspension, reconnection and termination stays in service history. | History shows actor and timestamp on each row. | B, D | LAB |

## 11. Requirements: reports, dashboard and printing

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-RPT-001 | Dashboard: 4 to 6 compact KPIs, billing vs collection, payment-method summary, AR aging, collector performance, overdue alerts and recent payments. Server-aggregated. | Each tile equals its matching report for the same period. | G, E | LAB |
| FR-RPT-002 | Daily, weekly, monthly and annual collection reports, and a Cash, GCash and other payment-method summary. | One report with different groupings. Reversals show as their own line. | G | LAB |
| FR-RPT-003 | Billing vs Collection with a receivable roll-forward: opening, plus invoices, plus debit adjustments, minus credit adjustments, minus net collections, equals closing. | Closing equals the AR aging total at the same date (INV-16). | C, G | LAB |
| FR-RPT-004 | Revenue by plan, by service type and by area. | Every breakdown foots to the billing total. One-time fees are on their own line. | G | LAB |
| FR-RPT-005 | Subscriber master list (including inactive and terminated), subscriber ledger and Statement of Account. | The list shows all statuses and filters work. | G | LAB |
| FR-RPT-006 | Collector assignment, collection, remittance, shortage and overage, and performance reports. | Shortages show as their own line with resolution status. | D, G | LAB |
| FR-RPT-007 | Audit reports: payment adjustment, payment reversal, voided receipt, user activity, audit trail. | The reversal report lists requester, approver, reason and timestamps. | F, G | LAB |
| FR-RPT-008 | Receivable reports: outstanding, overdue, aging, suspension candidates. | Same numbers as the screens. | D, G | LAB |
| FR-RPT-009 | Every report header shows business name, title, period or as-of date, filters, generated by and generated at. | Absent from no report. | G | DEC |
| FR-RPT-010 | Export to PDF and XLSX (CSV optional) using the same filters as the screen. XLSX amounts are numeric cells. The PDF font includes the ₱ glyph. Text cells beginning with `=`, `+`, `-` or `@` are escaped. Every export writes an audit entry. | A subscriber named `=1+1` opens as text in Excel. A PDF shows ₱ correctly on a clean PC. | G, F | LAB |
| FR-RPT-011 | Large exports run as queued jobs and download when ready, so cashiering stays responsive. | A 12-month export does not freeze other PCs. | G | DEC |
| FR-RPT-012 | Dedicated print layouts for receipts, Statement of Account, route sheets and reports, with no navigation. | `@media print` hides all application chrome. | E | LAB |
| FR-RPT-013 | Collection reports use the payment (received) date and the Asia/Manila time zone. All queries of one report run in one consistent read snapshot. | A payment posted mid-report does not make the sections disagree. | G | DEC |
| FR-RPT-014 | At least six required reports exist with PDF and XLSX samples: monthly collection, AR aging, subscriber SOA, collector remittance and performance, billing vs collection, payment reversal. | Sample files are stored under `reports-samples/`. | G | LAB |

## 12. Requirements: security, audit, backup and restore

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| FR-SEC-001 | Username and password login with argon2id hashing and an active/inactive account state. | Hashes only in the database. An inactive user cannot log in. | F | LAB |
| FR-SEC-002 | Sensible failed-login handling: generic error message, progressive lockout (default 5 attempts, 15 minutes), Owner unlock, all audited. | Message does not reveal whether the username exists. Lockout expires. | F | LAB |
| FR-SEC-003 | Session lock and idle timeout (configurable) re-ask for the password. Logout destroys the server session. | A copied session cookie stops working after logout. | F | LAB |
| FR-SEC-004 | Temporary passwords force a change at first login. | The first request after login redirects to change password. | F | DEC |
| FR-SEC-005 | Authorization is enforced on the server by permission through Gates and Policies on every route, Livewire action and file download. | A test enumerates all routes and Livewire actions and fails if one has neither a permission check nor an explicit public marker. | F | LAB |
| FR-SEC-006 | AT-10: a Cashier calling an admin-only operation directly is rejected. | HTTP 403 for routes. For Livewire, the action throws an authorization exception and changes nothing. | F | LAB |
| FR-SEC-007 | Livewire hardening: sensitive ids are `#[Locked]`, models are re-fetched in each action, input is validated in the action, and every action authorizes. | A test tampers a public property and the action still refuses. | F | DEC |
| FR-SEC-008 | CSRF protection on, secure cookie flags, session driver `database`. HTTPS on the LAN is strongly preferred. | Cookies are HttpOnly and SameSite. If HTTP is used, the deployment guide documents the risk. | F | DEC |
| FR-SEC-009 | `audit_logs` records every posted financial mutation with actor, action, time, reason and relevant old and new values, written in the same transaction. Rows are insert-only. | The application's database role has no UPDATE or DELETE on the table. | F | LAB |
| FR-SEC-010 | Audit also covers logins, lockouts, denied requests, permission changes, settings changes, exports, backups and restores. | Each event type appears in the audit viewer. | F | DEC |
| FR-SEC-011 | Passwords, tokens and payment credentials are never logged. Monolog context is redacted. | A log scan after the test suite finds no password string. | F | LAB |
| FR-SEC-012 | Users are deactivated, never deleted. The last Owner cannot be removed. Password reset gives a temporary password and is audited. | Deactivated user's history still shows their name. | F | DEC |
| FR-SEC-013 | Settings (grace period, penalty, thresholds, fees, policies, timeouts) are stored with change history. | Each change keeps old value, new value, who and when. | F | DEC |
| FR-SEC-014 | Users see understandable messages. Technical details go to structured logs with a request id. | A forced exception shows a friendly message and a log entry with the same request id. | F, I | LAB |
| FR-BAK-001 | Backup bundles a `pg_dump` custom-format file, the attachments and non-secret configuration, with a SHA-256 checksum and a `backup_history` row. | File name carries timestamp and schema version. | B, I | LAB |
| FR-BAK-002 | Nightly scheduled backup plus manual backup, copy to a second location, and a retention rule. Backup files are access-protected. | The scheduler runs it. A failure raises an alert on the Owner dashboard. | I | DEC |
| FR-BAK-003 | Verification: file and checksum check, `pg_restore --list`, and a test restore into a scratch database followed by the integrity checks. | A truncated file is marked FAILED. | B, H | LAB |
| FR-BAK-004 | Restore is Owner-only. It needs a typed confirmation, enters maintenance mode, takes a pre-restore safety backup, restores database and attachments, checks the schema version, runs the integrity checks and leaves maintenance mode only if they pass. | AT-12 passes. | B, F | LAB |
| FR-BAK-005 | Integrity checks: referential integrity, ledger arithmetic, invoice vs allocation, receivable tie-out, uniqueness, sequence safety, attachment links, record counts. | The report shows pass or fail per check and is saved as evidence. | B, H | DEC |
| FR-BAK-006 | After a restore, sequences are raised above the highest number known from the safety backup so printed receipt numbers are never reused. | A receipt issued after restore has a number above any earlier one. | B, C | DEC |
| FR-BAK-007 | The restore event is written outside the restored database as well (append-only server log). | The restore appears in the log even though `audit_logs` was rolled back. | F | DEC |
| FR-SHL-001 | `/desktop` contains a minimal Electron shell that opens `http://<SERVER_URL>/` in a window. The server URL comes from a config file or environment setting. | Changing the setting points the shell to another server without rebuilding. | A | LAB |
| FR-SHL-002 | Window flags: `contextIsolation: true`, `nodeIntegration: false`, `sandbox: true`, `webSecurity: true`. No preload that exposes Node or file system access. | A test inspects the options. | A, F | LAB |
| FR-SHL-003 | The shell never contains a database driver, database credentials or database host. | A repository scan of `/desktop` finds none. | A, F | LAB |
| FR-SHL-004 | Navigation is locked to the SERVER\_URL origin. Other links open in the default browser or are blocked. | `will-navigate` and `setWindowOpenHandler` enforce it. | F | DEC |
| FR-SHL-005 | If the server is unreachable the shell shows a local page with the URL and a Retry button. It caches no business data and queues no transactions. | Unplug the server and the page appears. | A | DEC |
| FR-SHL-006 | Printing uses the web print layouts. Downloads (PDF, XLSX) go through a Save As dialog. DevTools are disabled in production. | A report downloads and a receipt prints from the shell. | E | DEC |
| FR-SHL-007 | electron-builder produces a Windows installer. A single-instance lock prevents duplicate windows. | The installer runs on a clean PC. | I | LAB |

## 13. Core flows, condensed

Each flow lists actors, the modules touched and the key rule. Detailed stories live in the F1 to F10 docs.

| Flow | Steps | Actors | Key rules and tests |
| --- | --- | --- | --- |
| F1 Onboarding | Search for existing subscriber → register subscriber → add service account (rate snapshot, status PENDING) → technician installs → activate → collector and area confirmed → documents attached → eligible for billing | Administrator, Technician, Cashier (search), Collection Supervisor | Unique account numbers. No deletes. FR-SUB-001 to 013. |
| F2 Monthly billing | Preview → generate DRAFT → review → finalize (number, ledger debit, audit, one transaction) → invoices age → corrections by adjustment or void → reconcile billing to ledger | Administrator, Owner, Auditor | AT-11. FR-BIL-001 to 016. |
| F3 Counter payment | Search → see balance → enter amount and method → allocation preview → post (lock, allocate, ledger, receipt, audit) → print receipt | Cashier, Administrator | AT-01 to AT-04, AT-09. FR-PAY-001 to 011. |
| F4 GCash | Customer sends proof (outside system) → staff record submission → duplicate check → queue → authorized verify or reject → verify posts through F3 transaction → receipt | Cashier, Administrator or Owner (verifier) | AT-05. FR-GCA-001 to 010. |
| F5 Collection | Create batch with snapshot → print route sheet → field collection → staff record collections through the payment engine → submit → remit cash → reconcile (shortage or overage recorded) → close | Collection Supervisor, Cashier or Administrator, Owner | AT-07, AT-08. FR-COL-001 to 017. |
| F6 Receivables | Dashboard → outstanding → aging → overdue list with filters → follow-up and batches → suspension candidates → auditor tie-out | Owner, Administrator, Collection Supervisor, Auditor, Viewer | INV-16. FR-REC-001 to 008. |
| F7 Suspension | Candidate list → review → request → Owner approval → technician disconnects → status SUSPENDED → qualifying payment → reconnection request and fee → technician reconnects → ACTIVE | Administrator, Owner, Technician, Cashier | FR-SVC-001 to 011. |
| F8 Reversal | Discover error → choose correction type → request with reason → impact preview → approval → reversal transaction → re-post to correct subscriber → audit | Cashier or Administrator (request), Owner (approve), Auditor | AT-06. FR-PAY-012, 013. |
| F9 Reporting | Set parameters → server aggregates → view → export PDF or XLSX → print → auditor tie-outs | Owner, Auditor, Administrator, Cashier (SOA), Viewer | INV-23. FR-RPT-001 to 014. |
| F10 Administration | Seed Owner → create users and roles → settings → audit review → backup → verify → restore drill → hardening review | Owner, deployer | AT-10, AT-12. FR-SEC-001 to 014, FR-BAK-001 to 007. |

## 14. Data model reference

The full schema plan is the ERD Plan doc. Save it as `docs/erd-plan.md` and treat it as the schema reference. This section is the short version.

**Tables by module**

| Module | Tables |
| --- | --- |
| Security and system | `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `audit_logs`, `application_settings`, `application_setting_changes`, `backup_history` (plus Laravel's `sessions`, `jobs`, `failed_jobs`, `cache`) |
| Subscribers and services | `subscribers`, `subscriber_contacts`, `subscriber_addresses`, `subscriber_documents`, `service_types`, `service_plans`, `service_plan_prices` (optional), `service_accounts`, `service_events` |
| Billing and ledger | `billing_cycles`, `invoices`, `invoice_items`, `adjustments`, `ledger_entries` |
| Payments | `payments`, `payment_verifications`, `payment_allocations`, `payment_proofs`, `payment_reversals`, `receipts` |
| Collections | `collectors`, `collection_areas`, `collector_assignments`, `collection_batches`, `batch_accounts`, `collector_remittances`, `batch_reconciliations`, `variance_resolutions` |
| Operations | `suspension_records`, `reconnection_records` |

**Laravel rules for the schema**

- Every schema change is a Laravel migration. Use `bigIncrements` or `id()` primary keys, `bigInteger` for `*_centavos` columns, `timestampTz` for timestamps and `date` for business dates.
- Partial unique indexes, check constraints, triggers and sequences use raw SQL inside migrations (`DB::statement`).
- Two database roles: an owner role that runs migrations, and a runtime role used by the app with no `UPDATE` or `DELETE` on `ledger_entries`, `audit_logs`, `receipts`, `payment_reversals` and other append-only tables.
- All foreign keys are `ON DELETE RESTRICT`. No Eloquent `SoftDeletes` on financial tables, because soft deletes still hide history.
- Eloquent models for financial tables disable mass updates of posted columns. Domain Actions are the only writers.
- Tests run on a PostgreSQL test database. SQLite is never used, not even for tests.

**Agreed decisions that shape the schema**

| Decision | Agreed default |
| --- | --- |
| Advance payment (AT-03) | The surplus stays on the ledger as credit and is applied automatically by allocation when the next invoice is finalized. |
| Duplicate GCash reference (AT-05) | Hard block for verified or reversed references. Warn for pending, rejected or soft matches. |
| Combo plan | One service account on a COMBO plan with one invoice line. |
| Suspended accounts and billing | Not billed while suspended. |
| Reconnection and installation fees | ONE\_OFF invoice type outside the monthly uniqueness rule. |
| OVERDUE status | Derived from due date, grace and balance. Never stored. |
| GCash submission storage | A `payments` row with status PENDING\_VERIFICATION. |
| Reversal of allocations | Compensating negative allocation rows linked to the reversal. |
| Collector login | None. Staff record collections from the route sheet. |
| Period locking | Optional later. Corrections go into the current period. |
| Primary keys | `BIGINT` identity plus human-readable unique numbers. |
| Cashier cash count | Not required. |

**Key constraints to create**

- Unique: `subscribers.account_no`, `service_accounts.account_no`, `invoices.invoice_no`, `receipts.receipt_no`, `receipts.payment_id`, `payment_reversals.payment_id`, (`batch_id`, `service_account_id`) on `batch_accounts`.
- Partial unique: (`service_account_id`, `billing_period`) on `invoices` where `invoice_type = 'MONTHLY'` and status is not VOID. (`method`, `normalized_reference`) on `payments` where status is PENDING\_VERIFICATION, POSTED or REVERSED. One open record per service account on `reconnection_records` and `suspension_records`.
- Checks: exactly one of `debit_centavos` or `credit_centavos` above zero on `ledger_entries`. Amounts above zero on payments, adjustments and remittances.
- Triggers: sum of allocations per payment never exceeds the payment amount. No `UPDATE` or `DELETE` on append-only and finalized rows.
- Indexes: `pg_trgm` on subscriber name and address text. `invoices (due_date, status)`, `invoices (subscriber_id, status)`, `payment_allocations (invoice_id)`, `ledger_entries (subscriber_id, entry_date, id)`, `payments (payment_date)`, `payments (normalized_reference)`, `collection_batches (collector_id, status)`, `audit_logs (occurred_at)`, `audit_logs (entity_type, entity_id)`.

## 15. Acceptance tests

All twelve are mandatory. A feature that looks finished but fails one of these is incomplete. Each becomes a Pest feature test on a PostgreSQL test database, named `AT01…AT12`, and the tie-out tests below run against the seeded demo dataset.

| ID | Scenario | Test data | Expected result |
| --- | --- | --- | --- |
| AT-01 | Exact payment | Invoice ₱999, payment ₱999 | Remaining ₱0. Invoice PAID. Ledger balanced. Receipt created. |
| AT-02 | Partial payment | Invoice ₱999, payment ₱500 | Remaining ₱499. PARTIALLY\_PAID. One payment with one allocation of ₱500. Ledger correct. |
| AT-03 | Advance payment | Monthly ₱1,000, one invoice due, payment ₱3,000 | Invoice PAID (₱1,000 allocated). ₱2,000 stays as unapplied customer credit. After the next invoice is finalized, it is allocated automatically. Payment amount equals allocations plus credit at every step. |
| AT-04 | Oldest-first arrears | August ₱999, September ₱999, payment ₱1,200 | August ₱0 and PAID. September ₱798 remaining and PARTIALLY\_PAID. |
| AT-05 | Duplicate GCash reference | Submit a GCash reference already verified and posted | The save is blocked with a message naming the earlier receipt. A concurrent second insert fails on the unique index. |
| AT-06 | Payment reversal | Reverse a posted payment with a reason | The original stays visible and marked REVERSED. A linked reversal row, compensating allocations and a ledger debit exist. Invoice status and balance restore. Receipt is VOIDED and its number reserved. Actor, approver and reason are in the audit log. A second reversal is rejected. |
| AT-07 | Balanced remittance | Cash collected ₱20,000, remitted ₱20,000 | Difference ₱0. The batch can reconcile and close. |
| AT-08 | Collector shortage | Cash collected ₱20,000, remitted ₱19,500 | ₱500 SHORTAGE displayed. The batch cannot reconcile as balanced without an explicit recorded variance. No subscriber ledger changes. |
| AT-09 | Concurrent users | Three parallel processes post and read different valid operations. Two processes try to pay the same invoice. | No duplicate receipt or invoice numbers. No over-allocation. Balances recompute correctly. Sessions never cross. |
| AT-10 | Authorization | A Cashier calls user management and backup operations directly | The server refuses (HTTP 403 for routes, authorization exception for Livewire actions). Nothing changes. |
| AT-11 | Duplicate billing | Run billing generation twice for the same period, and from two processes at once | One non-void MONTHLY invoice per service account and period. |
| AT-12 | Backup and restore | Create and verify a backup, change data, restore | The restored database passes the integrity checks and the expected records return. |

**Tie-out tests (run on the seeded demo dataset)**

| ID | Check |
| --- | --- |
| TO-01 | Net collections for a period equal the sum of payments for that period minus reversals, and equal the sum of ledger credits. |
| TO-02 | Finalized invoices plus debit adjustments for a period equal the ledger debits for that period. |
| TO-03 | Receivable roll-forward closing equals the AR aging total and the sum of outstanding invoice balances. |
| TO-04 | Revenue by plan, by service type and by area each foot to the billing total. |
| TO-05 | For every subscriber, the running balance recomputed from entries equals the displayed balance. |
| TO-06 | Statement of Account opening plus debits minus credits equals closing for several date ranges. |
| TO-07 | Preview allocation equals posted allocation for generated random payments. |
| TO-08 | Every route and Livewire action has a permission check or an explicit public marker. |

**Required test evidence:** automated test output for domain rules, feature test results for every posting flow, end-to-end evidence for key workflows (Playwright against the web app, or a reproducible manual script), screenshots or report files showing expected balances, and a bug log with discovered issue, root cause, fix and regression test.

## 16. Non-functional requirements

| ID | Requirement | Acceptance criterion | Rubric | Source |
| --- | --- | --- | --- | --- |
| NFR-01 | Scale to at least 20,000 subscribers, 500,000 invoices, 500,000 payments and 1,000,000 ledger entries. | A performance seeder (`bcis:seed-performance`) builds that volume. `EXPLAIN` shows indexed plans for search, aging, overdue and ledger queries. | B | LAB |
| NFR-02 | List screens use server-side pagination, filtering and sorting. No screen loads a full dataset. | Largest list loads one page. | B, E | LAB |
| NFR-03 | Three office PCs work at the same time without corruption. | AT-09. | B, H | LAB |
| NFR-04 | Critical financial operations are transactional. | Forced-failure tests (FR-BIL-005, FR-PAY-007). | B | LAB |
| NFR-05 | Performance target: search and payment posting respond within about one second on the performance dataset with three concurrent users. | Measured and reported, not a hard gate. | E | DEC |
| NFR-06 | Structured JSON logs (Monolog daily channel), rotation, request id on every log line, redaction of secrets. | Log lines carry `request_id`. No secrets present. | F, I | LAB |
| NFR-07 | Friendly user messages, technical details only in logs. | No stack trace is ever rendered to a user. | F | LAB |
| NFR-08 | Code quality: `declare(strict_types=1)`, Pint formatting, Larastan level 6 or higher, no business rules in Blade or Livewire components. | `composer check` passes. | H | DEC |
| NFR-09 | Testing: unit tests for money, allocation, aging and numbering. Feature tests for every posting flow. All AT-01 to AT-12 and TO-01 to TO-08 automated. | `composer test` runs the whole suite and passes. | H | LAB |
| NFR-10 | Migrations run from a clean database. Seeders contain synthetic data only. No secrets in Git. `.env.example` documents settings. | `php artisan migrate:fresh --seed` works on an empty PostgreSQL database. | B, I | LAB |
| NFR-11 | Time zone Asia/Manila, currency PHP shown as ₱ with two decimals, locale en-PH. | Config values set. Money formatting helper used everywhere. | C | DEC |
| NFR-12 | The UI targets desktop Chromium (the Electron shell) at 1366×768 and larger. It stays usable at 1280 wide. | No horizontal page scroll at those sizes. | E | LAB |
| NFR-13 | Accessibility basics: visible labels, logical focus order, status shown as text plus color. | Manual check on the main screens. | E | LAB |
| NFR-14 | Data privacy: demo data is synthetic. No real customers, GCash numbers or passwords anywhere in the repository or in prompts to AI tools. | Seeders reviewed. | F | LAB |
| NFR-15 | Security headers and HTTPS: send standard security headers, set a restrictive CSP where Livewire allows, and prefer HTTPS on the LAN. | Headers present in the response. | F | DEC |
| NFR-16 | Operations: the server starts web, PostgreSQL, queue worker and scheduler automatically after a reboot, and a UPS is recommended. | Deployment guide documents each service. | I | DEC |

## 17. UI/UX standard and screens

The app must look like a professional commercial ISP billing and operations platform: clean, trustworthy, efficient and information-dense. Avoid generic dashboard templates, excessive gradients, oversized decorative cards, animated counters and unrelated effects.

**Design tokens (define once in the Tailwind config)**

| Token | Value |
| --- | --- |
| Primary / navy | `#0F2747` |
| Accent blue | `#2563EB` |
| Canvas | `#F6F8FB` |
| Surface | `#FFFFFF` |
| Text primary | `#0F172A` |
| Text secondary | `#64748B` |
| Success | `#059669` |
| Warning | `#D97706` |
| Danger | `#DC2626` |
| Typography | Inter (bundled locally, not from a CDN) |

**Rules**

- Financial values are right-aligned with tabular numerals (`tabular-nums`).
- Status badges show text and color, never color alone.
- Tables have sticky headers, search, filters, sorting, server-side pagination, export and consistent badges.
- Forms have visible labels, required indicators, section grouping and inline validation (Livewire `wire:model.blur` with server rules).
- Livewire actions show a loading state (`wire:loading`) and disable the submit button to prevent double posting.
- Printing uses a dedicated layout without navigation (`@media print` and separate print views).
- Use `wire:navigate` for fast page changes, with the layout shell kept stable.

**Navigation (sidebar, permission-aware)**

```
Dashboard
Subscribers: All Subscribers, New Subscriber, Service Accounts
Billing: Current Billing, Generate Billing, Invoices
Payments: Receive Payment, Payment History, GCash Verification
Collections: Collectors, Areas & Routes, Collection Batches, Remittance
Receivables: Outstanding, Overdue, Aging, Suspension Candidates
Services
Reports
Administration
```

**Screen requirements**

| ID | Screen | Requirement |
| --- | --- | --- |
| FR-UI-001 | Dashboard | 4 to 6 compact KPIs, billing vs collection, payment-method summary, AR aging, collector performance, overdue alerts, recent payments. |
| FR-UI-002 | Subscriber profile | Tabs: overview, services, billing, payments, ledger, collection, service history, documents, audit. |
| FR-UI-003 | Receive Payment | Optimized for cashier speed: global search, balance panel, amount, method, allocation preview, post, print receipt. Works well with the keyboard. |
| FR-UI-004 | GCash Verification | Two-pane queue: list on the left, proof preview, details, duplicate warnings and verification controls on the right. |
| FR-UI-005 | Collector reconciliation | Expected cash, remitted cash, difference, non-cash totals, accounts collected and exceptions. Shortage or overage in a clear text-plus-color badge. |
| FR-UI-006 | Overdue and aging | Aging grid with drill-down to invoices, filters for collector, area, plan, service type and age. |
| FR-UI-007 | Reports | Parameter form, on-screen preview, export PDF and XLSX buttons gated by permission. |
| FR-UI-008 | Lock screen | Idle lock that asks for the password without losing unsaved work on the page. |
| FR-UI-009 | Offline and error states | Friendly empty, error and server-unreachable states. |
| FR-UI-010 | Permission-aware menus | Menu items follow permissions. The server still enforces every action. |

## 18. Seed and demo dataset

Seeders use synthetic data only. Demo passwords come from the environment (`SEED_DEMO_PASSWORD`), never from the repository. Two commands exist: `php artisan db:seed` for the demo dataset and `php artisan bcis:seed-performance` for the 20,000-subscriber volume.

| Data | Minimum |
| --- | --- |
| Users | One each for Owner, Administrator, Cashier, Collection Supervisor, Auditor, Technician and Viewer |
| Plans | 3 Internet, 2 Cable, 2 Combo |
| Subscribers | 50 |
| Service accounts | 60 or more across Internet, Cable and Combo |
| Collectors | 2 (Rico Dela Cruz, Mila Santos) |
| Collection areas | 3 (Poblacion, Sampaguita Heights, Riverside) |
| Invoices | At least 3 billing months across the dataset |
| Payments | A mix of Cash and GCash, partial, exact and advance |
| Overdue accounts | At least 10 across the aging buckets |
| Reversal or void | At least 1 approved example |
| Suspension and reconnection | At least 2 scenarios |

**Named scenarios (synthetic) that the tests rely on**

| Subscriber | Scenario |
| --- | --- |
| Ana Reyes | New Combo subscriber, first invoice ₱1,999.00 with installation fee, paid exactly in cash. |
| Ben Santos | October and November invoices ₱999.00, GCash payment of ₱1,200.00 verified (oldest-first, ₱798.00 remains). |
| Carlo Mendoza | Three unpaid invoices (72, 42 and 11 days past due), suspended, then paid ₱3,297.00 and reconnected with a ₱300.00 fee. |
| Dina Flores | Six unpaid invoices ₱1,000.00 each, spanning every bucket, suspended and stays suspended. |
| Elena Cruz and Elaine Cruz | ₱1,499.00 cash payment posted to the wrong subscriber, reversed and re-posted. |
| Gil Ramos | One October invoice ₱799.00, 37 days past due. |
| Rico's batch | 40 accounts, expected ₱48,960.00, cash ₱20,000.00 remitted in full. |
| Mila's batch | Cash ₱20,000.00 collected, ₱19,500.00 remitted, ₱500.00 shortage recorded. |
| GCash queue | One pending, one verified, one rejected and one duplicate-reference attempt. |

The seeded data must make every tie-out test (TO-01 to TO-08) pass with known totals.

## 19. Assumptions and open questions

When Claude Code meets an item below, it uses the stated default and flags it in the pull request. It does not invent a different rule.

| # | Topic | Default used until answered |
| --- | --- | --- |
| 1 | Stack acceptance | The laboratory document recommends Electron, React and Fastify, and requires the Electron → server → PostgreSQL shape. This project uses Laravel and Livewire with an Electron shell. Confirm with the instructor that this is accepted, and keep the lab-to-Laravel mapping in section 3 for the defense. |
| 2 | Server operating system and web server | Not decided. Write the deployment guide for one primary choice (Ubuntu with nginx and PHP-FPM, or Windows) and say so. |
| 3 | HTTPS on the LAN | Prefer HTTPS with an internal certificate authority installed on the three PCs. If not feasible, document the risk. |
| 4 | Penalty | Disabled by default. The amount rule is undefined, so no penalty is generated. |
| 5 | First invoice and proration | No proration. The month containing the billing start date is billed in full. |
| 6 | Installation fee | Added as an item on the first invoice. |
| 7 | Cheque handling | Posts when recorded. A dishonored cheque is reversed. |
| 8 | Maker-checker rules | A person cannot verify their own GCash submission, approve their own reversal or approve their own suspension request. |
| 9 | Collector login | None. Collectors are records. |
| 10 | Technician login | Technicians have a user account with the Technician role. |
| 11 | Document numbering | Prefix plus zero-padded sequence, for example `INV-000001` and `RCPT-000001`, prefixes configurable. |
| 12 | Backup destination | A second local folder or external drive path from settings. Cloud storage is not assumed. |
| 13 | PDF font | Bundle a font with the ₱ glyph (for example Inter or DejaVu Sans). |
| 14 | Batch active-account rule | Block an account from two active batches for the same period. |
| 15 | Advance credit scope | Credit belongs to the subscriber and applies to any of their service accounts, oldest due first. |
| 16 | Livewire and package versions | Latest stable that works together, pinned by `composer.lock` and `package-lock.json`. |

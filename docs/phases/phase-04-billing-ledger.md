# Phase 4: Billing and Ledger

Oct 9, 2026 · @michaela

> Save as `docs/phases/phase-04-billing-ledger.md`. Read `CLAUDE.md`, PRD sections 5, 7 and 14 first. Part of the financial core CP3, tag `phase-4`.

## Goal

Monthly billing with preview, draft and finalize, invoice numbering, line items, duplicate protection, due dates, invoice states and ledger debit posting. This is the first financial core, so it is test-driven.

## Build

- Migrations: sequences for invoice and adjustment numbers, `billing_cycles`, `invoices`, `invoice_items`, `adjustments`, `ledger_entries`. Use raw SQL for the partial unique index on (`service_account_id`, `billing_period`) where `invoice_type = 'MONTHLY'` and status is not VOID, the check on `ledger_entries`, and the triggers that block `UPDATE` and `DELETE` on finalized invoices, items and ledger rows.
- Domain classes: `BillingEligibility`, `PreviewBilling`, `GenerateBillingCycle` (creates DRAFT invoices and items), `DiscardDraftInvoices`, `FinalizeBillingCycle` (assign numbers, set UNPAID, post ledger debits, audit, one transaction), `DueDateCalculator` (short-month fallback), `InvoiceBalanceCalculator`, `InvoiceStatusResolver` (OVERDUE derived), `LedgerPoster`, `CreateAdjustment`, `VoidInvoice`, `CreateOneOffInvoice`.
- A hook where Phase 5 plugs in automatic application of customer credit when invoices are finalized (FR-BIL-012). Leave a tested interface and an implementation that does nothing until Phase 5.
- Screens: Current Billing, Generate Billing (preview, generate, finalize), Invoices (list and detail with items and status), subscriber Billing and Ledger tabs with running balance, adjustment and void dialogs with reason and approval.
- Generate the three demo billing months through the real generator with a period parameter.

## Migrations and seeds

Tables and sequences above. Seed three billing months of invoices across the demo subscribers, including the first invoice with installation fee for Ana Reyes.

## Requirements covered

FR-BIL-001 to FR-BIL-014 and FR-BIL-016, INV-02, INV-03, INV-05, INV-06, INV-09, INV-10, INV-11, INV-20. AT-11, TO-02, TO-05.

## Definition of done

- Running generation twice, and from two processes at once, leaves one non-void MONTHLY invoice per service account and period (AT-11).
- A forced exception during finalization leaves no numbered invoice and no ledger row.
- An attempt to update a finalized invoice, its items or a ledger entry fails at the database level.
- Due dates fall back to the last day of short months.
- A suspended account is skipped with the reason "suspended" in the preview.
- Recomputed running balance equals the displayed balance for every seeded subscriber (TO-05). Finalized invoices equal ledger debits for the period (TO-02).
- Adjustments and voids need a reason and approval, post a ledger counter-entry and keep the invoice and its number visible.

## Evidence for the checkpoint

Billing generator screens, an invoice view, ledger entries, unit and feature test output for billing rules.

## Do not

Edit finalized invoices, compute money with floats, use `max + 1` for numbers, or store OVERDUE as a column.

## Definition of done: where each item is proven

| Item | Test |
| --- | --- |
| Generation twice, and from two processes at once, leaves one non-void MONTHLY invoice per account and period (AT-11) | `tests/Acceptance/At11DuplicateBillingTest.php` (twice in a row, a partly billed month, the unique index firing at insert time, void frees the slot, a finalized month refused); `tests/Concurrency/DuplicateGenerationTest.php` (2 and 4 real `php artisan bcis:billing-generate` processes held at the first billing statement by a table lock, then released together) |
| A forced exception during finalization leaves no numbered invoice and no ledger row | `FinalizeBillingTest`: the credit hook failing on the third invoice, and the ledger unique index rejecting the second; a retry works and leaves a gap, not a reused number |
| Updating a finalized invoice, its items or a ledger entry fails at the database level | `DatabaseGuardsTest` (UPDATE, DELETE and TRUNCATE, even as the table owner); `FinalizeBillingTest` after a real finalize |
| Due dates fall back to the last day of short months | `DueDateCalculatorTest` (31 in November, February 28 and 29) |
| A suspended account is skipped with the reason "suspended" in the preview | `GenerateBillingTest` (eligibility and preview); `BillingScreensTest` (the preview screen shows "Suspended") |
| Recomputed running balance equals the displayed balance for every seeded subscriber (TO-05); finalized invoices equal ledger debits (TO-02) | `DemoBillingSeederTest` (every subscriber, also against the sum of their invoice balances; per month and in total); `LedgerQueryTest`; `FinalizeBillingTest` |
| Adjustments and voids need a reason and approval, post a ledger counter-entry and keep the invoice and number visible | `AdjustmentsTest` (reason, maker-checker, stale approval, void keeps its number, counter-entry); `AdjustmentScreensTest` |
| Item sum equals the total on every seeded invoice (FR-BIL-006) | `DemoBillingSeederTest` |
| Every route and Livewire action is permission-checked | `BillingScreensTest`, `AdjustmentScreensTest`, plus the route and Livewire inventories in `tests/Feature/Security` (TO-08) |

## What was built

Migrations `2026_10_09_000009` to `000013`; `app/Domain/Billing` (Actions: `PreviewBilling`, `GenerateBillingCycle`, `DiscardDraftInvoices`, `FinalizeBillingCycle`, `CreateAdjustment`, `ApproveAdjustment`, `RejectAdjustment`, `CreateOneOffInvoice`; plus `BillingPlanner`, `LedgerPoster`, `LedgerQuery`, `InvoiceBalanceCalculator`, `InvoiceStatusResolver`, `DueDateCalculator`, `DocumentNumberGenerator`, the `AllocationTotals` and `CustomerCreditApplier` seams); Livewire screens Current Billing, Generate Billing, Invoices, invoice detail, Adjustments, the subscriber Billing and Ledger tabs; invoice-number search; `DemoBillingSeeder`; `bcis:billing-generate`.

There is no separate `VoidInvoice` Action: a void is the VOID kind of an adjustment, so it goes through the same request, approval and ledger path (`CreateAdjustment` and `ApproveAdjustment`).

## Agreed with the user

| Topic | Decision |
| --- | --- |
| Approval of adjustments and voids | Two steps. One user requests, a different user approves, and only approval posts the ledger counter-entry. Enforced in the Action and by a database CHECK. |
| Demo months | August, September and October 2026, built through the real generator. Ana Reyes's first invoice is October: ₱1,499.00 + ₱500.00 installation fee = ₱1,999.00, due Oct 5. PRD section 18 and F2 were updated to these dates. |
| Installation fee | A per-account snapshot (`service_accounts.installation_fee_centavos`), billed once on the account's first monthly invoice. |

## Decisions made without a PRD rule (flagged in the commits)

| Topic | Default used |
| --- | --- |
| Number formats | `INV-000001` and `ADJ-000001` (prefix plus six digits from a sequence), prefixes in `config/bcis.php`. |
| Grace period and penalties | `billing.grace_days` defaults to 0 (overdue the day after the due date) and `billing.penalty_enabled` defaults to off. No penalty is generated in this phase. The Settings screen for them is Phase 7. |
| Permissions | `billing.generate` covers generate, discard and finalize, and also creating a one-off invoice. `invoice.adjust` and `invoice.void` cover requesting and deciding the matching kind. |
| Billable months | A month that has not started cannot be billed. Generating into a FINALIZED month is refused; an account activated late for that month is billed by the next cycle. |
| No proration | The first month is billed in full from the billing start date (PRD 19 #5). |
| Adjustment amounts | A credit or write-off may not exceed the current balance (the balance never goes below zero). A debit has no cap. A void always cancels the whole balance and is refused while payments are applied or when nothing is owed. |
| Approval date | The ledger counter-entry is dated the day it is approved, not back-dated into a month that may already be reported. |
| One-off invoice | Items limited to installation, reconnection and other; due on the day it is issued unless a later date is given. A one-off installation item marks the account's fee as billed. |
| Ledger visibility | The Ledger tab and the ledger lines on an invoice need `receivable.view`, so the Cashier does not see them (the Phase 3 tab decision). |
| Statement of Account (FR-BIL-015) | Out of scope for this phase. |

## Known limits and follow-ups

- **Phase 5 seams are empty.** `NullAllocationTotals` reports no payments and `NullCustomerCreditApplier` applies no credit. The hook order is tested (once per invoice, in id order, after numbering and posting, inside the transaction, before the status is resolved); Phase 5 binds the real implementations.
- **A voided installation-fee invoice** leaves `installation_fee_billed_at` set, so the fee is not charged again. Phase 5 or 7 should decide whether a void releases it.
- **A past month cannot be completed after finalize.** An account activated after its month was finalized waits for the next cycle.
- **Preview and draft tables show the first 100 rows**; the preview re-plans on each interaction while open. Fine at demo size; a 20,000-account run should cache the plan or page it.
- **Not run on a dev database:** `migrate:fresh --seed` is covered by `DemoBillingSeederTest` on `bcis_test`.
- **Demo scenarios for later phases:** Dina Flores needs older invoices to span every aging bucket (Phase 7), and the 37-days-past-due case for Gil Ramos is set by that seed. Carlo, Dina and Gil have three invoices each from this phase.
- **Test time.** The full suite runs for several minutes, so `composer.json` sets `process-timeout` to 1200 seconds.
- **Concurrency test.** It rebuilds `bcis_test` with `migrate:fresh` before and after, and refuses to run on any other database name.

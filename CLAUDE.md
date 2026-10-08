# BCIS Billing and Collection System: CLAUDE.md

Oct 9, 2026 · @michaela

> Save this doc as `CLAUDE.md` in the repository root. Claude Code loads it every session, so it stays short. Detail lives in `docs/PRD.md`.

## Project

BCIS is a subscription billing and collection system for Bukidnon Cable and Internet Services (Internet, Cable and Combo). It bills monthly, takes Cash and GCash payments, keeps a permanent subscriber ledger, reconciles collector cash, monitors receivables and produces audit-ready reports. Three office PCs use it at the same time. It is a graded laboratory project. Correct financial results matter more than visual polish. Demo data is synthetic only.

## Architecture (non-negotiable)

- **Backend and UI:** Laravel (latest stable) with Livewire, served from ONE central server machine.
- **Database:** PostgreSQL only. Never SQLite, not even for tests.
- **Desktop client:** a minimal Electron shell in `/desktop` that opens `http://<SERVER_URL>/` in a window. It must never connect to the database and holds no database credentials.
- All business rules live in `app/Domain/<Module>` Action and Service classes. Livewire components and Blade views stay thin.
- Three PCs, one server. Never design for a shared database file.

## Rules you must never break

Full list with IDs: PRD section 5 (`INV-01` to `INV-25`).

- **Money** is an integer number of centavos. No floats anywhere. Use the `Money` value object. Display as ₱ with two decimals only at the edge.
- **Posted history is never edited or deleted.** `ledger_entries`, `audit_logs`, receipts, reversals, finalized invoices and posted payments are append-only. Correct by reversal, adjustment or void. No `SoftDeletes` on financial tables.
- **Every posting operation is one database transaction** with `lockForUpdate` on the rows it changes. All or nothing. The audit record is written inside the same transaction.
- **Balances are derived.** Invoice balance is total plus adjustments minus net allocations. Ledger running balance is computed from entries. OVERDUE and aging are derived, never stored.
- **Default allocation is oldest due date first.** Preview and posting use the same function. Payment amount always equals allocations plus unapplied credit.
- **Duplicates are stopped by the database**, not only by code: unique invoice period, unique receipt number, unique payment reference (partial index), one reversal per payment.
- **Numbers come from PostgreSQL sequences** (invoice, receipt, reversal, adjustment, batch). Never `max + 1`. Voided numbers are never reused. Gaps are fine.
- **Only verified GCash payments are posted.** A screenshot is evidence, never proof.
- **A collector shortage or overage is recorded explicitly** and never changes a subscriber ledger.
- **Authorize on the server, always.** Hiding a button is not authorization. Every route, controller, download and Livewire action checks a permission. Livewire public properties are untrusted input: lock ids with `#[Locked]`, re-fetch models and re-validate inside the action.
- **Passwords** are argon2id hashes. Never log passwords, tokens or payment credentials.
- **Never delete** subscribers, service accounts, users, invoices, payments or receipts. Use status columns. Foreign keys are `ON DELETE RESTRICT`.
- **Every schema change is a migration.** Triggers, partial indexes and sequences use raw SQL inside migrations.
- **Time zone** is Asia/Manila. The server stamps all timestamps.

## Layout, conventions and commands

```
bcis/
├── app/
│   ├── Domain/<Module>/      Actions, Services, DTOs, Enums, Policies, Money
│   ├── Livewire/<Module>/    thin components that call Actions
│   ├── Models/
│   └── Http/                 controllers for downloads, uploads, print views, middleware
├── database/migrations, seeders, factories
├── resources/views/{livewire,print,reports}
├── tests/{Unit,Feature,Acceptance,Concurrency}
├── desktop/                  Electron shell only
├── docs/                     PRD.md, erd-plan.md, phases/phase-01…10
├── reports-samples/
└── CLAUDE.md
```

Modules: Subscribers, Billing, Payments, Collections, Receivables, Services, Reports, Security, Backup.

**Conventions**

- `declare(strict_types=1);` in every PHP file. Laravel Pint formatting. Larastan level 6 or higher.
- One Action class per business operation (for example `PostPayment`, `FinalizeBillingCycle`, `ReversePayment`). Actions own the transaction, locking, audit and ledger writes.
- Eloquent models for financial tables expose no setters that rewrite posted columns.
- Table columns for money end in `_centavos`. Business dates are `date`. Timestamps are `timestampTz`.
- Requirement IDs appear in test names and commit messages, for example `FR-PAY-007`, `AT-04`.

**Commands** (created in Phase 1)

| Command | Purpose |
| --- | --- |
| `composer setup` | Install dependencies, copy `.env.example`, generate key |
| `php artisan migrate:fresh --seed` | Rebuild the demo database on an empty PostgreSQL database |
| `composer test` | Run the whole Pest suite |
| `composer check` | Pint (test mode), Larastan and Pest |
| `composer fix` | Apply Pint formatting |
| `npm run dev` / `npm run build` | Tailwind and Vite |
| `php artisan bcis:seed-performance` | 20,000-subscriber volume dataset |
| `php artisan bcis:backup`, `bcis:backup-verify`, `bcis:restore`, `bcis:integrity-check` | Backup tooling (Phase 9) |
| `cd desktop && npm run start` / `npm run dist` | Run or package the Electron shell |

## Working rules

1. **Read before coding.** For any task, read the matching phase file in `docs/phases/` and the PRD sections it names. Inspect the existing code first.
2. **Work in feature-sized tasks.** Never try to build the whole system in one prompt. Implement the smallest coherent feature, then stop for review.
3. **Tests come with the code.** Add or update tests in the same change. Financial rules are test-driven: write the failing test for the rule (for example an AT case) first.
4. **Run `composer check` before every commit.** Do not commit failing tests, formatting errors or type errors.
5. **Review financial and security code yourself.** Re-read every migration, raw SQL statement, transaction, lock and policy you write before moving on. Check which tables a change can affect.
6. **Ask when a rule is undefined.** If the PRD does not decide something, use the default in PRD section 19 and flag it in the commit message or pull request. Never silently invent a business rule.
7. **Do not build out-of-scope features** (collector app, SMS, gateway integration, and the rest of the PRD section 1 list).
8. **Never use real data.** No real customers, phone numbers, GCash details, passwords or production databases in the repo, prompts, seeders or tests. Demo passwords come from the environment.
9. **No secrets in Git.** Keep `.env` out of version control and maintain `.env.example`.
10. **Meaningful Git history.** Small commits with messages like `feat(payments): post exact and partial payments (FR-PAY-007, AT-01, AT-02)`. Tag each phase checkpoint `phase-N`.
11. **Be able to explain it.** Add short comments for non-obvious financial or security decisions. The developer must defend the code, so prefer simple, readable solutions.
12. **Keep docs current.** When a decision changes the PRD, the ERD plan or a phase file, update that doc in the same change.

**Where to look**

| Need | File |
| --- | --- |
| Requirements, invariants, acceptance tests, seed data | `docs/PRD.md` |
| Tables, constraints, indexes, migration order | `docs/erd-plan.md` |
| What to build next and the definition of done | `docs/phases/phase-01…10` |
| Business stories behind each flow | `docs/flows/F1…F10` (optional) |

# Phase 3: Plans, Subscribers and Service Accounts

Oct 9, 2026 · @michaela

> Save as `docs/phases/phase-03-subscribers-services.md`. Read `CLAUDE.md`, PRD sections 6 and 14, and `docs/erd-plan.md` first. Completes CP2, tag `phase-3`.

## Goal

Plans, subscribers, addresses, contacts, service accounts, status history, collector and area assignment and fast search, with realistic demo records.

## Build

- Migrations and models: `collection_areas`, `collectors`, `collector_assignments`, `service_types`, `service_plans`, `subscribers`, `subscriber_contacts`, `subscriber_addresses`, `subscriber_documents`, `service_accounts`, `service_events`.
- Domain Actions: `RegisterSubscriber`, `UpdateSubscriber`, `ChangeSubscriberStatus`, `CreateServiceAccount` (copies the plan rate), `ChangeServiceStatus` (writes a service event and audit), `AssignCollector` (dated), `UploadSubscriberDocument`.
- Account number generation from sequences with configurable prefix, plus a unique constraint.
- Global search service: account number, name (trigram), contact, address, invoice number, receipt number and GCash reference (the last three work once later tables exist, so build the search to accept new sources).
- Screens: Plans, All Subscribers (server-side paging, search, filters), New Subscriber (grouped form, inline validation, duplicate warning), Subscriber Profile with tabs (billing, payments, ledger, collection and audit tabs show empty states until their phases), Service Accounts, Service History.
- Document upload: validate content type and size, store on a private disk under a generated name, serve through an authorized controller.
- Technician view that returns operational fields only, enforced in the query and response, not the view.

## Migrations and seeds

Tables above. Seed 3 Internet, 2 Cable and 2 Combo plans, 3 collection areas, 2 collectors, 50 synthetic subscribers and 60 or more service accounts, including the named demo subscribers from PRD section 18 where they apply to this phase.

## Requirements covered

FR-SUB-001 to FR-SUB-013, FR-UI-002 (shell of the profile), INV-19, INV-20, INV-21.

## Definition of done

- A duplicate subscriber or service account number is rejected by the database.
- Search finds a subscriber by each supported key and pages on the server.
- Changing a plan price leaves existing service account rates unchanged.
- Every status change writes a `service_events` row and an audit record. Those rows cannot be updated or deleted.
- There is no delete route for subscribers or service accounts.
- A renamed non-PDF or non-image upload is rejected. A direct URL to the stored file does not work.
- The Technician response contains no financial field, checked by a test on the response.

## Evidence for the checkpoint

CRUD workflows with validation, subscriber profile, service account screen, sample records, test output.

## Do not

Delete subscribers, hard-code plan rates into invoices later, or put search logic in the Livewire component.

## Definition of done: where each item is proven

| Item | Test |
| --- | --- |
| A duplicate subscriber or service account number is rejected by the database | `SchemaConstraintsTest`: duplicate subscriber and service account numbers (unique indexes), sequences |
| Search finds a subscriber by each supported key and pages on the server | `SearchTest`: every key, typo, phone formats, the trigram `EXPLAIN` check, paging; `SubscriberScreensTest` paging 25 per page |
| Changing a plan price leaves existing service account rates unchanged | `PlanActionsTest`: leaves existing service account rates unchanged (FR-SUB-006, INV-20) |
| Every status change writes a `service_events` row and an audit record; those rows cannot be updated or deleted | `ServiceAccountActionsTest` status changes; `SchemaConstraintsTest`: `service_events` is append-only (trigger, revoked privileges, model guard) |
| There is no delete route for subscribers or service accounts | `RoutesTest`: no delete, update or create-by-post route; `DocumentsTest`: documents are GET only |
| A renamed non-PDF or non-image upload is rejected; a direct URL to the stored file does not work | `DocumentsTest`: refusing a document by its content; cannot be reached by a direct URL |
| The Technician response contains no financial field | `OperationalViewTest`: the page, the Livewire data, the SQL and the row shape |

## Note: where the Technician restriction is enforced

Hiding a column in a Blade view would still send the rate to the browser. The restriction is therefore applied in three layers that do not depend on the view:

1. **Query:** `OperationalServiceQuery` selects an explicit list of columns and joins only the tables that hold them. It never touches the rate, due day, billing date, contacts, notes, invoices, payments or ledger. A test reads the generated SQL and the column list.
2. **Response shape:** the screen receives `OperationalServiceRow` objects, whose properties are fixed. A test lists them by reflection and rejects any money-like name.
3. **Component state:** `ServiceJobs` keeps no model with a rate in a public property, so nothing financial is in the Livewire snapshot either. A test checks the page, the component state and the snapshot for the distinctive amount used in the fixture.

The Technician also has no permission for the screens that do show rates (`subscriber.view` is not granted), and the Service Accounts menu item moved to `subscriber.view` for the same reason.

## Decisions made without a PRD rule (flagged in the commits)

| Topic | Default used |
| --- | --- |
| Account numbers | Generated only, never typed. PostgreSQL sequences `subscriber_no_seq` and `service_account_no_seq`, prefix plus six digits (`SUB-000001`, `SVC-000001`). Prefixes live in `config/bcis.php` (environment-overridable), not on the Settings screen. Sequences are `OWNED BY` the account number column so `migrate:fresh` recreates them. |
| Service status moves | `PENDING` to `ACTIVE` or `TERMINATED` (cancelled install), `ACTIVE` to `SUSPENDED` or `TERMINATED`, `SUSPENDED` to `ACTIVE` or `TERMINATED`. `TERMINATED` is final. A reason is always required. |
| Who may change service status | `service.manage` any allowed move. `service.execute` (Technician) only `PENDING` to `ACTIVE`. Approval workflows for suspend and terminate arrive with Phase 7. |
| Subscriber status moves | `ACTIVE` and `INACTIVE` both ways, either to `TERMINATED` (only when every service account is `TERMINATED`), `TERMINATED` to `ARCHIVED`. Terminated and archived never return. A reason is required and audited. |
| Service history reason | Stored in `service_events.notes` (the ERD has no `reason` column). The first event of an account has no `from_status`. |
| Collector assignment | Dated rows. A change closes the open row the day before the new start, never rewrites it. The start date may be today or earlier but must be after the current start. One open row per account or area, enforced by partial unique indexes. `service_accounts.assigned_collector_id` is the current collector and is updated in the same transaction. |
| Collection area of an account | The area of its service address (`service_accounts` has no area column). |
| Billing due day | Both the subscriber and the service account store one. The subscriber's is the default; the service account's is the one billing uses. |
| Plans | Internet needs a speed, Cable needs channels, Combo needs both. Code and service type are fixed after creation. A price change needs a reason and reaches new accounts only. `service_plan_prices` is not built; the rate on each account is the price history. Combo is one service account on one plan. |
| Contacts | Phone numbers are stored as digits only, `+63` turned into `0`, any 7 to 15 digits. Emails are lower-cased. The first contact marked primary stays primary. |
| Duplicate warning | Same phone digits or email, or same name and same street, ignoring case and spacing. It warns; the user may continue with "Create anyway", reuse the record, or reactivate an inactive one. |
| Search | Account number (subscriber and service account), name (substring or fuzzy trigram), contact, address. A term shorter than two characters is ignored. Invoice, receipt and GCash reference sources are added later by registering one class each. |
| Documents | PDF, JPEG and PNG judged by content (`finfo`), images must decode, up to 5 MB (`DOCUMENT_MAX_KB`). Stored on the private `documents` disk (no URL) under a uuid name. `original_name` is kept for display only, with the extension of the detected type. Download is always an attachment with `nosniff`. Upload needs `subscriber.manage`, download `subscriber.view`. Documents cannot be deleted or replaced. |
| Profile tabs | Each tab needs the permission its later data will need: `billing.view`, `payment.view`, `receivable.view` (ledger), `collection.record`, `audit.view`. Overview, services, service history and documents need `subscriber.view`. |
| Technician list | Name, address, plan, status, status date and an instruction derived from the status. Subscriber notes and typed reasons are left out because they can mention money. Terminated services are hidden unless filtered for. |
| Demo data | Plan names, speeds, channel counts and the 500 installation fee are invented; prices come from the PRD section 18 amounts. 50 subscribers and 61 service accounts, Rico Dela Cruz with the first 41. Phone numbers use the `0999 000 xxxx` range. |

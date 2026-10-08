# Phase 1: Foundation

Oct 9, 2026 · @michaela

> Save as `docs/phases/phase-01-foundation.md`. Read `CLAUDE.md` and PRD sections 3, 5 and 16 first. Checkpoint CP1, tag `phase-1`.

## Goal

A Laravel and Livewire app running on PostgreSQL, an Electron shell that opens it from another PC, the tooling to keep quality high, and a health check, so every later phase builds on a working base.

## Build

- Create the Laravel project (latest stable). Install Livewire, Tailwind CSS, Pest, Laravel Pint and Larastan. Configure `pgsql` only. Make sure no SQLite connection is configured.
- Set timezone `Asia/Manila`, locale `en_PH`, session driver `database`, queue driver `database`, hashing driver `argon2id`.
- Add a Monolog daily JSON log channel with a redaction processor and a request-id middleware.
- Add composer scripts `setup`, `test`, `check` and `fix`. Configure Pest to run on a PostgreSQL test database.
- Create `scripts/db-setup.sql` that makes two roles: an owner role for migrations and a limited runtime role for the app.
- Add `.env.example` (no secrets) and `.gitignore`.
- Build the base Livewire layout: sidebar shell from PRD section 17, top bar placeholder, design tokens in the Tailwind config, Inter font bundled locally, and an empty print layout.
- Add a public `GET /health` route that checks the database connection and returns JSON. Mark it as the explicit public route.
- Add a `Money` value object (integer centavos, formatting, safe add and subtract) with unit tests.
- Create `/desktop`: `main.js` with `contextIsolation: true`, `nodeIntegration: false`, `sandbox: true`, `webSecurity: true`, no preload, navigation locked to the SERVER\_URL origin, a local "cannot reach server" page with Retry, DevTools off in production, a single-instance lock, a config file for SERVER\_URL, and an electron-builder configuration.
- Write the README with setup steps and an architecture diagram (PRD section 3).
- Copy `CLAUDE.md`, `docs/PRD.md` and `docs/erd-plan.md` into the repository.

## Migrations and seeds

Laravel default tables for sessions, cache and jobs. A migration that enables the `pg_trgm` extension. No domain tables yet.

## Requirements covered

FR-SHL-001 to FR-SHL-006, NFR-06, NFR-08, NFR-10, NFR-11, INV-01 (Money object), INV-22, INV-25.

## Definition of done

- `php artisan migrate:fresh` works on an empty PostgreSQL database.
- `composer check` and `composer test` pass.
- A test asserts that no SQLite connection is configured and the default connection is `pgsql`.
- The Electron shell on a second PC opens the app through the server URL and `/health` returns OK.
- Pointing the shell at a stopped server shows the local error page.
- Navigating the shell to another origin is blocked.
- A scan of `/desktop` finds no database driver, host or credentials.
- README lets a new developer set up from scratch.

## Evidence for the checkpoint

Architecture diagram, repository link and tag, screenshot of the shell window on a second PC, `/health` response, test output, README.

## Do not

Use SQLite, Fastify, React or a shared database file. Put database access, Node access or business logic in the shell.

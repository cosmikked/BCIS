# BCIS: Billing and Collection System

Subscription billing and collection for Bukidnon Cable and Internet Services (Internet, Cable, Combo). Monthly billing, Cash and GCash payments, a permanent subscriber ledger, collector reconciliation, receivables and audit-ready reports. Demo data is synthetic only.

Requirements live in `docs/PRD.md`, the schema plan in `docs/erd-plan.md`, and the build order in `docs/phases/`. Project rules for contributors and Claude Code are in `CLAUDE.md`.

## Architecture

One central server runs Laravel and Livewire and is the only thing that talks to PostgreSQL. The three office PCs run a minimal Electron shell that just opens the server's web address. The shell has no database driver, no credentials and no Node access for the page.

```
  Office PC 1        Office PC 2        Office PC 3
 +-----------+      +-----------+      +-----------+
 | Electron  |      | Electron  |      | Electron  |
 |   shell   |      |   shell   |      |   shell   |
 +-----+-----+      +-----+-----+      +-----+-----+
       |  http(s)://<SERVER_URL>/  (office LAN)
       +-------------------+-------------------+
                           |
              +------------v-------------+
              |  Server machine          |
              |  Laravel + Livewire      |   auth, authorization, validation,
              |  (web, queue, scheduler) |   transactions, audit, reports
              +------------+-------------+
                           | pgsql (bcis_app role)
              +------------v-------------+
              |  PostgreSQL              |
              +--------------------------+
```

Business rules live in `app/Domain/<Module>`. Money is an integer number of centavos (`App\Domain\Money\Money`), never a float.

## Requirements

- PHP 8.3 or newer with `pdo_pgsql`, `intl`, `mbstring`, `zip`, `fileinfo` (argon2id support is built in)
- Composer 2
- Node.js 20 or newer and npm
- PostgreSQL 15 or newer (developed on 17)

## Server setup from scratch

1. **Install dependencies and build assets**

   ```
   composer setup
   ```

   This installs PHP and npm packages, copies `.env.example` to `.env`, generates the app key and builds the front end (Inter is bundled locally, no CDN).

2. **Create the database roles and databases** (once per PostgreSQL server, as a superuser)

   ```
   psql -U postgres -h 127.0.0.1 \
        -v owner_password="'choose-an-owner-password'" \
        -v app_password="'choose-a-runtime-password'" \
        -f scripts/db-setup.sql
   ```

   Run it from the repository root. It creates:

   | Role | Purpose |
   | --- | --- |
   | `bcis_owner` | Owns the databases and tables. Used only to run migrations. |
   | `bcis_app` | Limited runtime role for the web app, queue worker and scheduler. Can read and write rows but cannot create or drop anything. |

   and the databases `bcis` (app) and `bcis_test` (test suite). Passwords come from the command line and are never stored in the repository.

3. **Configure `.env`**

   Set `DB_PASSWORD` to the runtime password (keep `DB_USERNAME=bcis_app` in production). To run migrations, override the credentials for that one command:

   ```
   # PowerShell
   $env:DB_USERNAME='bcis_owner'; $env:DB_PASSWORD='<owner password>'; php artisan migrate; Remove-Item Env:DB_USERNAME,Env:DB_PASSWORD
   # bash
   DB_USERNAME=bcis_owner DB_PASSWORD='<owner password>' php artisan migrate
   ```

   On a development machine you may simply put the owner role in `.env`, so `php artisan migrate:fresh --seed` works directly.

4. **Create the test configuration**

   Copy `.env` to `.env.testing`, set `APP_ENV=testing` and `DB_DATABASE=bcis_test`, and use the owner role (the tests rebuild the schema). The test suite refuses to run unless the database name ends in `_test`.

5. **Run the migrations**

   ```
   php artisan migrate:fresh
   ```

6. **Start the server** (development)

   ```
   php artisan serve --host=0.0.0.0 --port=8000
   ```

   Check `http://<server>:8000/health`. It returns `{"status":"ok","database":"ok",...}` when the database is reachable, or HTTP 503 when it is not.

Production deployment (web server, queue worker and scheduler services, HTTPS on the LAN, backups) is a later phase. Security headers and CSP (NFR-15) are also later work.

## Commands

| Command | Purpose |
| --- | --- |
| `composer setup` | Install dependencies, copy `.env.example`, generate key, build assets |
| `composer test` | Run the Pest suite on the PostgreSQL test database |
| `composer check` | Pint (test mode), Larastan level 6 and Pest |
| `composer fix` | Apply Pint formatting |
| `php artisan migrate:fresh` | Rebuild the database on an empty PostgreSQL database |
| `npm run dev` / `npm run build` | Vite and Tailwind |

Logs are JSON, one object per line, in `storage/logs/bcis-YYYY-MM-DD.log`. Each line has a `request_id` (also returned in the `X-Request-Id` response header) and secrets such as passwords and tokens are redacted.

## Desktop shell (`/desktop`)

A minimal Electron window that opens the server address. It holds no business logic and no database access.

1. Point it at the server. The address is read from the first of these that is valid:
   1. `BCIS_SERVER_URL` environment variable
   2. `config.json` in the user's app-data folder (`%APPDATA%\BCIS Desktop\config.json`)
   3. `config.json` installed with the app (`resources\config.json`)
   4. `desktop/config.json` (development default, `http://localhost:8000/`)

   The file format is `{ "serverUrl": "http://192.168.1.10:8000/" }`. Changing it needs no rebuild.

2. Run and test:

   ```
   cd desktop
   npm install
   npm run start     # open the window
   npm test          # security flags, URL lock, config, "no database in /desktop" scan
   npm run lint
   npm run dist      # Windows installer in desktop/dist
   ```

Behaviour: the page runs with `contextIsolation`, `sandbox` and `webSecurity` on, `nodeIntegration` off and no preload. Navigation and new windows are locked to the server's origin. If the server is unreachable a local page shows the address and a Retry button. Downloads open a Save As dialog. DevTools are disabled in the installed app. Only one window can run at a time.

### Testing the shell from a second PC

1. On the server, start the app listening on the LAN (`php artisan serve --host=0.0.0.0 --port=8000`) and allow port 8000 through the firewall.
2. On the other PC, install the shell (or run `npm run start` with `BCIS_SERVER_URL=http://<server-ip>:8000/`).
3. Confirm the window shows the app, and `http://<server-ip>:8000/health` returns OK.
4. Stop the server and press Retry: the local "cannot reach the server" page appears.
5. Try to navigate to another site from the app: it is blocked.

## Project layout

```
app/Domain/<Module>/   Actions, Services, DTOs, Enums, Policies, Money
app/Livewire/          thin components that call Actions
app/Support/           logging (JSON, redaction) and request context
config/bcis.php        the explicit list of public routes
database/migrations    schema (raw SQL for triggers, indexes, sequences)
resources/views        Blade layouts (app shell, print), Livewire views
scripts/               db-setup.sql
tests/                 Unit, Feature, Acceptance, Concurrency (Pest)
desktop/               Electron shell only
docs/                  PRD, ERD plan, phase files
```

## Data and security notes

- PostgreSQL only. No SQLite anywhere, including tests.
- No secrets in Git: `.env` and `.env.testing` are ignored; `.env.example` has none.
- Demo data is synthetic only. Never put real customers, phone numbers or GCash details in this repository.

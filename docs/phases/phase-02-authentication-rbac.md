# Phase 2: Authentication and RBAC

Oct 9, 2026 · @michaela

> Save as `docs/phases/phase-02-authentication-rbac.md`. Read `CLAUDE.md` and PRD sections 2, 5 (INV-17, INV-18, INV-24) and 12 first. Part of CP2, tag `phase-2`.

## Goal

Working login, session lock and logout, users, roles and permissions, server-enforced authorization, permission-aware navigation and the audit service that every later phase uses.

## Build

- Migrations and models for `users` (extend the Laravel table), `roles`, `permissions`, `role_permissions`, `user_roles`, `audit_logs`, `application_settings`, `application_setting_changes`.
- `RolePermissionSeeder` from the PRD section 2 matrix. `OwnerSeeder` reads the initial Owner credentials from the environment and forces a password change. Demo users for the other roles use `SEED_DEMO_PASSWORD`.
- Authorization: define Gates dynamically from permission codes, `User::hasPermission()`, and Policies. Add a middleware alias for permission checks on routes.
- `AuditLogger` service: insert-only, called inside the caller's transaction, with actor, action, entity, reason, old and new values, request id. No secrets in values.
- Login Livewire page: generic error, progressive lockout (default 5 attempts, 15 minutes), forced change of temporary passwords, `RateLimiter`, audit of logins, failures and lockouts.
- Session lock and idle timeout, logout that destroys the server session, lock screen that keeps the page state.
- Admin screens (Owner only): Users (create, deactivate, reset password, assign roles), Roles and permissions, Settings with change history, and a read-only Audit Log viewer with filters.
- Protect the last active Owner. Block granting permissions you do not hold.
- Permission-aware sidebar. Remember that the server still enforces every action.
- Database hardening: revoke `UPDATE` and `DELETE` on `audit_logs` from the runtime role and add a trigger as a second guard.
- Security headers middleware and secure cookie settings.

## Migrations and seeds

Security and system tables listed above. Seed roles, permissions, role-permission links, one Owner and the demo users.

## Requirements covered

FR-SEC-001 to FR-SEC-004, FR-SEC-005 to FR-SEC-013, FR-UI-008, FR-UI-010, INV-17, INV-18, INV-24, NFR-15. Starts AT-10.

## Definition of done

- Login, lock, idle timeout and logout work. A copied session cookie fails after logout.
- Hashes only in the database. No password appears in logs after the test suite runs.
- A route and Livewire action inventory test (TO-08) fails if any endpoint lacks a permission check or an explicit public marker.
- A Cashier is refused on every Administration route and Livewire action, and nothing changes (AT-10).
- A test tampers a Livewire public property and the action still refuses.
- The audit viewer cannot edit or delete rows, and the runtime role cannot either.
- The last Owner cannot be deactivated.

## Evidence for the checkpoint

Working login, seeded Owner, the role matrix as a screenshot, authorization test output, and a short note on how server-side checks differ from hidden menu items.

## Do not

Check role names in code. Rely on hidden buttons. Store plaintext passwords. Add delete routes for users.

## Note: server-side checks versus hidden menu items

The sidebar (`config/navigation.php`, `NavigationMenu`) hides items a user cannot use. That is tidiness, not security: a Cashier who types `/admin/users` into the address bar, or replays a Livewire request from the browser's developer tools, never touches the menu. Authorization therefore happens in four places that do not depend on what the page shows:

1. **Route:** `->middleware(['auth', 'permission:user.manage'])` returns 403 and writes an audit row.
2. **Livewire component:** `boot()` authorizes on every request (load, action, refresh, paging) and each action method authorizes again before it does anything.
3. **Action class:** `Gate::forUser($actor)->authorize(...)` at the start of every business operation, so the rule holds for any caller (a component, a command, a test).
4. **Inputs:** Livewire public properties are untrusted. Ids are `#[Locked]`, and every id, role or permission code is re-fetched and re-validated inside the Action.

Tests prove each layer: `InventoryTest` fails if a route or Livewire method has no check, `AdminScreensTest` calls every admin route and component method as a role without the permission, and the tamper tests change locked and unlocked properties and expect refusal.

## Decisions made without a PRD rule (flagged in the commits)

| Topic | Default used |
| --- | --- |
| Password rule | At least 12 characters, no username inside, different from the current one. |
| Lockout | The stated 5 attempts and 15 minutes, no escalating durations. Attempts made while locked are not audited. |
| Idle timeout | The idle lock is the only idle timeout (default 15 minutes). Full expiry still follows `SESSION_LIFETIME`. |
| Demo users | Not temporary-password accounts, so they can sign in straight away. |
| Audit IP | Kept in `new_values` of sign-in events, no `ip_address` column. |
| Self-service | No self-deactivation and no self-reset of password by an administrator. |
| Privilege rule | Nobody manages an account that holds permissions they lack (stops a user manager taking over the Owner). |
| Roles | Role permissions are editable at runtime except the Owner role. Reseeding restores the PRD matrix. |
| Setting ranges | 3 to 20 failed sign-ins, 1 to 1440 lockout minutes, 1 to 480 idle minutes. Whole numbers only. |
| CSP | Nonce-based. `script-src` keeps `'unsafe-eval'` for Livewire's Alpine build until `csp_safe` is checked in a browser. Report-Only while `APP_DEBUG=true`. |
| Cookies | `HttpOnly`, `SameSite=Strict`, encrypted database sessions. `Secure` is switched on with `SESSION_SECURE_COOKIE` once HTTPS is in place. |

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public routes
    |--------------------------------------------------------------------------
    |
    | The ONLY routes reachable without logging in, by route name. A test fails
    | if any other route lacks an auth middleware (INV-17). Add to this list
    | only on purpose and say why in the pull request.
    |
    | "login" must be public: nobody is signed in yet when they reach it.
    |
    */

    'public_routes' => [
        'health',
        'login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticated-only routes
    |--------------------------------------------------------------------------
    |
    | Routes any logged-in user may reach without a specific permission, such
    | as logout and change-my-password. They still need the `auth` middleware.
    | tests/Feature/Security/InventoryTest.php (TO-08) fails for any route that
    | has neither a permission check nor a place on one of the two lists.
    |
    */

    'authenticated_routes' => [
        'home',
        'logout',
        'password.change',
        'session.heartbeat',
        'session.lock',
        'session.unlock',
    ],

    /*
    |--------------------------------------------------------------------------
    | Setting defaults
    |--------------------------------------------------------------------------
    |
    | Used until the Owner saves a value in application_settings (FR-SEC-013).
    | Keys are flat strings, not nested arrays, so they read the same here and
    | in the database.
    |
    */

    'setting_defaults' => [
        'security.max_login_attempts' => 5,   // FR-SEC-002
        'security.lockout_minutes' => 15,     // FR-SEC-002
        'security.idle_lock_minutes' => 15,   // FR-SEC-003
        // PRD 19 sets no grace period, so the neutral default is flagged in the
        // commit: an invoice is overdue the day after its due date. Phase 7
        // (FR-SVC-001) is where the Owner is expected to tune it.
        'billing.grace_days' => 0,            // FR-BIL-008
        'billing.penalty_enabled' => 0,       // FR-BIL-011, PRD 19 #4: off by default
    ],

    /*
    | What the settings screen may edit (FR-SEC-013): label, unit and allowed
    | whole-number range. A key must be listed here AND in setting_defaults.
    | Later phases add their own keys (grace period, fees, thresholds, ...).
    */
    'setting_rules' => [
        'security.max_login_attempts' => ['label' => 'Failed sign-ins before lockout', 'unit' => 'attempts', 'min' => 3, 'max' => 20],
        'security.lockout_minutes' => ['label' => 'Lockout length', 'unit' => 'minutes', 'min' => 1, 'max' => 1440],
        'security.idle_lock_minutes' => ['label' => 'Idle time before the screen locks', 'unit' => 'minutes', 'min' => 1, 'max' => 480],
        'billing.grace_days' => ['label' => 'Grace period after the due date', 'unit' => 'days', 'min' => 0, 'max' => 60],
        'billing.penalty_enabled' => ['label' => 'Charge a penalty on overdue accounts (1 = on, 0 = off)', 'unit' => '', 'min' => 0, 'max' => 1],
    ],

    // Password rules for users choosing their own password (PRD 19 has no rule,
    // so this default is flagged in the commit message).
    'password_min_length' => 12,

    /*
    |--------------------------------------------------------------------------
    | Account numbers (FR-SUB-001, FR-SUB-007, INV-10)
    |--------------------------------------------------------------------------
    |
    | Numbers are always generated from PostgreSQL sequences: prefix plus a
    | zero-padded sequence value (PRD assumption 11). Prefixes are configurable
    | here (or in .env); they are not editable on the settings screen. Unique
    | indexes are the real duplicate guard.
    |
    */

    'account_numbers' => [
        'subscriber_prefix' => env('SUBSCRIBER_NO_PREFIX', 'SUB-'),
        'service_prefix' => env('SERVICE_ACCOUNT_NO_PREFIX', 'SVC-'),
        'digits' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing document numbers (FR-BIL-013, INV-10)
    |--------------------------------------------------------------------------
    |
    | Same scheme as account numbers: prefix plus a zero-padded PostgreSQL
    | sequence value, for example INV-000001 (PRD section 19 #11). Prefixes are
    | configurable here (or in .env).
    |
    */

    'document_numbers' => [
        'invoice_prefix' => env('INVOICE_NO_PREFIX', 'INV-'),
        'adjustment_prefix' => env('ADJUSTMENT_NO_PREFIX', 'ADJ-'),
        'digits' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriber documents (FR-SUB-010)
    |--------------------------------------------------------------------------
    |
    | Only these CONTENT types are accepted, judged from the file's bytes and not
    | from its name or the type the browser claims. The stored extension comes
    | from the detected type, never from the uploaded name. The default size
    | limit is 5 MB (PRD section 19).
    |
    */

    'documents' => [
        'max_kb' => (int) env('DOCUMENT_MAX_KB', 5120),
        'types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Seed credentials (Phase 2)
    |--------------------------------------------------------------------------
    |
    | Read from the environment so no password lives in the repository. The
    | Owner is forced to change the initial password at first login. Demo users
    | exist for the other roles and are never created in production.
    |
    */

    'seed' => [
        'owner_username' => env('OWNER_USERNAME'),
        'owner_full_name' => env('OWNER_FULL_NAME', 'System Owner'),
        'owner_initial_password' => env('OWNER_INITIAL_PASSWORD'),
        'demo_password' => env('SEED_DEMO_PASSWORD'),
    ],

];

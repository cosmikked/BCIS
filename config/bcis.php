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
    ],

    // Password rules for users choosing their own password (PRD 19 has no rule,
    // so this default is flagged in the commit message).
    'password_min_length' => 12,

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

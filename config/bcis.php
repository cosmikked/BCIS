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
    | "home" is a temporary placeholder shell page. Phase 2 (Security) puts it
    | behind authentication and removes it from this list.
    |
    */

    'public_routes' => [
        'health',
        'home',
    ],

];

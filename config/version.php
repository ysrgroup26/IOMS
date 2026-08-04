<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the version shown throughout the UI --
    | About dialog, sidebar footer, login footer, and the Home page
    | "What's New" banner all read this via the shared Inertia prop (see
    | HandleInertiaRequests::share()). Bump `number` and update
    | `release_date` + `whats_new` together whenever a release ships;
    | nothing else in the codebase needs to change.
    |
    */

    'number' => '1.5.4',

    'edition' => 'Enterprise Edition',

    'release_date' => '2026-07-24',

    'developer' => 'Yofhanza Shultona Rizqi S.',

    'copyright_year' => '2026',

    'whats_new' => [
        'Sidebar branding is now the visual identity: larger wordmark, more breathing room',
        'Login page wordmark enlarged for a stronger first impression',
        'Home hero rescaled to an enterprise-dashboard feel, not a landing page',
        'Home greeting now shows the real wordmark instead of plain "IOMS" text',
        'Dashboard gained a subtle premium background treatment',
        'About page hierarchy refined: icon, wordmark, name, edition, description',
    ],

];

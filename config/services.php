<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    |
    | v2.74.0. This file did not exist -- IOMS had no third-party service
    | credentials until Google sign-in. Laravel's own convention is that
    | Socialite providers are configured here, so this is the framework
    | location rather than an invented one (`config/ioms.php` holds product
    | metadata, not secrets).
    |
    | NOTHING HERE HAS A DEFAULT VALUE. Every entry reads from the
    | environment and is null when unset, which is what makes an
    | unconfigured deployment degrade cleanly: GoogleAuthController::configured()
    | returns false, the "Continue with Google" button is not rendered, and
    | the OAuth routes 404 rather than throwing.
    |
    | No credential is ever committed. See docs/ADR/038 for the exact
    | configuration a deployment needs.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        /*
         * The redirect URI, which must match EXACTLY one of the
         * "Authorised redirect URIs" registered on the Google Cloud OAuth
         * client -- Google compares it as a string, including scheme,
         * host, port and trailing path.
         *
         * Defaulted through `url()` rather than left null so a deployment
         * only has to set the two secrets; but it stays overridable,
         * because behind the hosting proxy the canonical host is a
         * deployment fact rather than something this file can know.
         */
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

];

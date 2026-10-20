<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IOMS Real-Time Notification Delivery
    |--------------------------------------------------------------------------
    |
    | v2.129.0. Two channels, chosen against the deployment IOMS actually
    | has rather than against what a tutorial assumes:
    |
    |   LIVE SYNC   -- while a tab is open. A cursor-based delta endpoint
    |                  the client asks for what has happened since the last
    |                  thing it saw. No page reload, no full prop bag.
    |   WEB PUSH    -- while the app is in the background or closed, via the
    |                  browser's own push service and the service worker.
    |
    | WHY NOT A WEBSOCKET SERVER. Reverb needs a long-running process and an
    | open port. IOMS deploys to cPanel shared hosting -- `deploy.sh` detects
    | `/opt/cpanel/ea-php*`, and Inertia SSR is off in this product precisely
    | because no Node process runs there. A hosted provider (Pusher, Ably)
    | would work and is left reachable through `transport` below, but it is a
    | paid third-party dependency with credentials the owner must create,
    | and nothing in this release needs one. See ADR 069.
    |
    */

    /*
     * How a tab learns about something new.
     *
     *   'sync'   -- the delta endpoint, asked on an interval while the tab
     *               is VISIBLE and paused while it is not. The default,
     *               because it works on every host and every browser.
     *   'none'   -- off. The Notification Center still works; it updates
     *               when a page is visited, as it did before this release.
     *
     * Not a fallback chain: one transport, named, so what is running is
     * never a guess.
     */
    'transport' => env('IOMS_REALTIME_TRANSPORT', 'sync'),

    /*
     * Seconds between delta requests while the tab is visible and the
     * person is active. Five seconds is the compromise this product is
     * sized for: a yard of tens of users, each request an indexed lookup on
     * `notifications.user_id` returning nothing most of the time.
     *
     * `idle_interval` takes over after `idle_after` seconds without a
     * keystroke, click or scroll -- a tab left open on a desk should not
     * cost the same as one being worked in. A HIDDEN tab polls not at all;
     * that case belongs to web push.
     */
    'interval' => (int) env('IOMS_REALTIME_INTERVAL', 5),
    'idle_interval' => (int) env('IOMS_REALTIME_IDLE_INTERVAL', 30),
    'idle_after' => (int) env('IOMS_REALTIME_IDLE_AFTER', 120),

    /*
     | WEB PUSH (VAPID)
     |
     | Self-hosted and free: the browser's own push service does the
     | delivery, and IOMS authenticates to it with a key pair it generates
     | itself (`php artisan ioms:vapid-keys`). No account, no provider, no
     | recurring cost, nothing for the owner to buy.
     |
     | Push is OFF until these are set. An unset key pair is not an error --
     | it means this installation has not turned push on, and the product
     | says so instead of asking a person for notification permission it
     | cannot then use.
     */
    'push' => [
        'public_key' => env('IOMS_VAPID_PUBLIC_KEY'),
        'private_key' => env('IOMS_VAPID_PRIVATE_KEY'),

        /*
         * The `sub` claim of the VAPID token: how a push service operator
         * reaches whoever is sending. Must be a mailto: or https: URL.
         * Falls back to the product's own support address.
         */
        'subject' => env('IOMS_VAPID_SUBJECT'),

        /*
         * How long the push service should hold a message for a device that
         * is offline. Four hours: a permit waiting for verification is
         * worth delivering late, and is not worth delivering tomorrow.
         */
        'ttl' => (int) env('IOMS_PUSH_TTL', 14400),

        /*
         * Seconds to wait on the push service before giving up on one
         * device. Short on purpose -- this runs after the response, but it
         * still runs in a PHP process, and a hung endpoint must not hold it.
         */
        'timeout' => (int) env('IOMS_PUSH_TIMEOUT', 5),
    ],

];

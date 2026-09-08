<?php

/**
 * v2.57.0 -- published for ONE reason: to stop the global From address
 * drifting away from the IOMS noreply mailbox.
 *
 * IOMS had no `config/mail.php` at all and ran on the framework default,
 * which is almost entirely right. The one thing it cannot do is know about
 * `config('ioms.emails')`, so `MAIL_FROM_ADDRESS` had to be set correctly
 * by hand in every environment — and it was not. The local development
 * `.env` still carried `hse@shipyard.local`, two product renames old,
 * which a test caught while preparing production SMTP.
 *
 * That mattered more than it looks. The three IOMS Mailables set From
 * explicitly, so they were never affected. Anything sent WITHOUT an
 * explicit From falls back to this value — and until this release the
 * password reset email was exactly that: Laravel's own notification, going
 * out under whatever `MAIL_FROM_ADDRESS` happened to say. A From address
 * the sending domain does not authorise is the ordinary reason
 * transactional mail lands in spam.
 *
 * So the default now CHAINS to the IOMS mailbox configuration:
 * `MAIL_FROM_ADDRESS` still wins if set, otherwise `IOMS_NOREPLY_EMAIL`,
 * otherwise the official address. Configure the four IOMS mailboxes and
 * the sender identity follows on its own.
 *
 * Everything else is the framework default, copied unchanged. In
 * particular there is deliberately NO `encryption` key: Laravel 11 removed
 * it, implicit TLS comes from the scheme, and Laravel infers `smtps` from
 * port 465. Re-adding `encryption` here would resurrect a setting that
 * silently does nothing.
 */
return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            // Production (cPanel, port 465) wants `smtps`. Laravel derives
            // that from the port on its own; setting MAIL_SCHEME states it
            // outright so it survives a port change.
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            // Never committed. Entered on the server only.
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => ['transport' => 'ses'],
        'postmark' => ['transport' => 'postmark'],
        'resend' => ['transport' => 'resend'],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => ['transport' => 'array'],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => ['ses', 'postmark'],
            'retry_after' => 60,
        ],

    ],

    'from' => [
        // Chains to the IOMS noreply mailbox rather than standing alone --
        // see this file's own doc comment for the drift this prevents.
        'address' => env('MAIL_FROM_ADDRESS', env('IOMS_NOREPLY_EMAIL', 'noreply@iomsuite.com')),
        // IOMS is the product name. The long-form expansion is not a brand
        // (see config/ioms.php's Canonical Product Identity block).
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'IOMS')),
    ],

];

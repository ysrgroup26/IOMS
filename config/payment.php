<?php

/**
 * v1.11.6 (Production Readiness pass, Part 18/19). Provider-agnostic
 * payment configuration. `gateway` names which adapter
 * `PaymentServiceProvider` SHOULD bind once one is implemented --
 * currently always resolves to `NullPaymentGateway` regardless of this
 * value (see that provider's own doc comment) until a real adapter
 * class exists and is wired in. No API keys are committed here or
 * anywhere in this repository -- every credential is read from the
 * environment only, and is empty by default.
 */
return [
    // Intended for an Indonesian payment gateway (e.g. 'midtrans',
    // 'xendit') once a real adapter is built. Purely descriptive until
    // then -- changing this alone does not activate anything.
    'gateway' => env('PAYMENT_GATEWAY', null),

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
    ],

    // Currency for Invoice.amount -- matches this deployment's market.
    'currency' => env('PAYMENT_CURRENCY', 'IDR'),
];

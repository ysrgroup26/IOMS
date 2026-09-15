<?php

/**
 * v1.11.6 (Production Readiness pass, Part 18/19). Provider-agnostic
 * payment configuration. No API keys are committed here or anywhere in
 * this repository -- every credential is read from the environment only,
 * and is empty by default.
 *
 * v2.51.0: `gateway` is now load-bearing. Setting it to 'midtrans' AND
 * providing both Midtrans keys binds the real adapter (see
 * PaymentServiceProvider); anything less keeps NullPaymentGateway, which
 * throws rather than pretending a payment succeeded.
 */
return [
    // 'midtrans' activates App\Services\Payments\MidtransGateway when its
    // credentials are also present. Leave null (the default) to keep the
    // application in its no-payments-configured state.
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

    // How long a generated checkout stays payable. Also drives when an
    // abandoned onboarding registration is considered expired.
    'checkout_expiry_hours' => (int) env('PAYMENT_CHECKOUT_EXPIRY_HOURS', 24),

    /*
    |--------------------------------------------------------------------
    | Renewal model (v2.70.0)
    |--------------------------------------------------------------------
    | IOMS bills INVOICE-PER-CYCLE, and there is no switch here because
    | there is only one behaviour to describe. A renewal invoice is issued
    | before the current period ends (`saas.renewal_lead_days`), the
    | customer pays it exactly as they paid the first one, and a
    | signature-verified webhook extends the period.
    |
    | A `recurring_enabled` flag used to sit here, promising that setting
    | it to true would make IOMS use the provider's recurring flow.
    | Nothing implemented that: the flag changed one paragraph of copy on
    | the Billing page and nothing else, so turning it on made the product
    | tell customers their card would be charged automatically when it
    | never would be. It is removed rather than left as a trap.
    |
    | Midtrans Subscription/Recurring needs supported payment channels and
    | separate merchant-side activation. If IOMS ever adopts it, that is a
    | real integration with its own webhook handling -- not a boolean.
    */
];

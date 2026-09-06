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
    | Automatic recurring charging
    |--------------------------------------------------------------------
    | Midtrans Subscription/Recurring is NOT available by default: it needs
    | supported payment channels and separate merchant-side activation.
    | IOMS therefore refuses to assume it exists.
    |
    | false (the default) = invoice-per-cycle. A renewal invoice is issued
    | and the customer pays it, exactly like the first one. This is fully
    | implemented and needs nothing beyond ordinary Midtrans credentials.
    |
    | true = the merchant has confirmed recurring is activated, and IOMS
    | may use the provider's recurring flow. The tenant-side subscription
    | model is identical in both modes -- only who initiates each charge
    | differs -- so switching this flag never migrates data.
    */
    'recurring_enabled' => (bool) env('PAYMENT_RECURRING_ENABLED', false),
];

<?php

namespace App\Contracts;

/** v1.11.6 -- plain value object returned by createCheckout()/createPayment(), gateway-agnostic. */
class PaymentCheckoutResult
{
    public function __construct(
        public readonly string $gatewayReference,
        public readonly string $redirectUrl,
        public readonly string $status,
        /**
         * v2.55.0 -- an OPTIONAL provider token for launching the payment
         * interface in place, without sending the customer to another site.
         *
         * Nullable and last, so it stays gateway-agnostic: a provider that
         * only offers a hosted redirect leaves it null and every caller
         * still works from `redirectUrl`. Midtrans returns a Snap token
         * alongside its redirect URL, which is what lets IOMS show its own
         * order summary first and then open Snap over it.
         *
         * Carries no authority. A token proves a checkout was CREATED, not
         * that anything was paid -- settlement is decided only by a signed
         * webhook.
         */
        public readonly ?string $token = null,
    ) {}
}

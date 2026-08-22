<?php

namespace App\Contracts;

/** v1.11.6 -- plain value object returned by createCheckout()/createPayment(), gateway-agnostic. */
class PaymentCheckoutResult
{
    public function __construct(
        public readonly string $gatewayReference,
        public readonly string $redirectUrl,
        public readonly string $status,
    ) {}
}

<?php

namespace App\Contracts;

/** v1.11.6 -- plain value object returned by handleWebhook(), gateway-agnostic. */
class PaymentWebhookResult
{
    public function __construct(
        public readonly string $gatewayReference,
        public readonly string $status,
        public readonly ?float $amount = null,
        public readonly bool $alreadyProcessed = false,
    ) {}
}

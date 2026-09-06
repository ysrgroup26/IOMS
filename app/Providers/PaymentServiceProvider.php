<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Services\Payments\MidtransGateway;
use App\Services\Payments\NullPaymentGateway;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * v1.11.6 (Production Readiness pass, Part 18/19). Single place
 * `PaymentGatewayInterface` is bound. Nothing else in the codebase should
 * ever `new` a concrete gateway class directly.
 *
 * v2.51.0: a real adapter now exists, so this resolves by configuration
 * instead of always returning the null object. The selection is
 * deliberately conservative in both directions:
 *
 *  - A gateway is used only when it is BOTH named in config('payment.
 *    gateway') AND actually holds credentials. Naming a provider without
 *    keys does not half-activate it.
 *  - Anything else falls back to NullPaymentGateway, which throws rather
 *    than returning a fake success. A misconfigured deployment therefore
 *    fails loudly at checkout instead of silently activating tenants that
 *    never paid.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function () {
            return match (config('payment.gateway')) {
                MidtransGateway::GATEWAY => $this->midtrans(),
                default => new NullPaymentGateway,
            };
        });
    }

    private function midtrans(): PaymentGatewayInterface
    {
        try {
            return new MidtransGateway(
                (string) config('payment.midtrans.server_key', ''),
                (string) config('payment.midtrans.client_key', ''),
                (bool) config('payment.midtrans.is_production', false),
            );
        } catch (Throwable) {
            // Credentials are missing/blank. Falling back to the null
            // gateway keeps the failure honest and loud (it throws on
            // every call) rather than booting a half-configured adapter
            // that would fail later in a less obvious place.
            return new NullPaymentGateway;
        }
    }
}

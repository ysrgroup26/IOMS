<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Services\Payments\NullPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * v1.11.6 (Production Readiness pass, Part 18/19). Single place
 * `PaymentGatewayInterface` is bound -- swap `NullPaymentGateway` for a
 * real provider adapter (implementing the same interface) once
 * `config('payment.gateway')` and its credentials are actually set.
 * Nothing else in the codebase should ever `new` a concrete gateway
 * class directly.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function () {
            // REQUIRES PROVIDER CONFIGURATION: no real adapter is wired
            // up yet. config('payment.gateway') names the intended
            // provider (see config/payment.php) for when one is added --
            // this deliberately does not silently fall back to pretending
            // a provider is active.
            return new NullPaymentGateway;
        });
    }
}

<?php

namespace App\Console\Commands;

use App\Contracts\PaymentGatewayInterface;
use App\Services\Payments\DuitkuGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * v2.132.0 -- DOES DUITKU ACCEPT THIS SERVER'S CREDENTIALS AND SIGNATURE?
 *
 *   php artisan ioms:duitku-check
 *
 * Asks the SANDBOX for the payment methods available for a nominal amount.
 * That call is signed with the same HMAC key as an inquiry and a callback,
 * so a non-empty answer proves the merchant code, the API key, the
 * signature formula and outbound HTTPS from this host in one request --
 * without creating a transaction, an invoice or a row of any kind.
 *
 * REFUSES PRODUCTION. "Never use a real production transaction to test the
 * integration": with DUITKU_IS_PRODUCTION=true this command does nothing.
 *
 * Exit 0 only when Duitku answered with at least one method. With
 * --allow-unconfigured, a deployment that has not selected Duitku yet
 * passes with a warning (used by a rehearsal that holds no credentials).
 */
class DuitkuCheck extends Command
{
    protected $signature = 'ioms:duitku-check {--allow-unconfigured : Pass with a warning when Duitku is not selected}';

    protected $description = 'Verify the Duitku SANDBOX accepts this server\'s credentials and signature (creates nothing)';

    public function handle(): int
    {
        $gateway = (string) config('payment.gateway');
        $isProduction = filter_var(config('payment.duitku.is_production'), FILTER_VALIDATE_BOOL);

        $this->table(['Setting', 'Value'], [
            ['PAYMENT_GATEWAY', $gateway ?: '(none)'],
            ['DUITKU_MERCHANT_CODE', filled(config('payment.duitku.merchant_code')) ? config('payment.duitku.merchant_code') : '(EMPTY)'],
            ['DUITKU_API_KEY', filled(config('payment.duitku.api_key')) ? '(set, not shown)' : '(EMPTY)'],
            ['DUITKU_IS_PRODUCTION', $isProduction ? 'true' : 'false'],
            ['Callback URL to register', route('webhooks.payment.duitku')],
        ]);

        if ($gateway !== DuitkuGateway::GATEWAY) {
            if ($this->option('allow-unconfigured')) {
                $this->warn('Duitku is not selected (PAYMENT_GATEWAY != duitku). Skipped.');

                return self::SUCCESS;
            }
            $this->error('Duitku is not selected. Set PAYMENT_GATEWAY=duitku, DUITKU_MERCHANT_CODE and DUITKU_API_KEY.');

            return self::FAILURE;
        }

        if ($isProduction) {
            $this->error('DUITKU_IS_PRODUCTION=true. This check runs against the SANDBOX only and will not touch production.');

            return self::FAILURE;
        }

        try {
            $client = app(PaymentGatewayInterface::class);
        } catch (Throwable $e) {
            $this->error('The Duitku adapter could not be built: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $client instanceof DuitkuGateway) {
            $this->error('PAYMENT_GATEWAY=duitku but a credential is missing, so the null gateway is bound.');

            return self::FAILURE;
        }

        $methods = $client->getPaymentMethods(10000);

        if ($methods === []) {
            $this->error('Duitku returned no payment methods. Most likely: wrong merchant code or API key for the SANDBOX, '
                .'or outbound HTTPS to sandbox.duitku.com is blocked. The exact response is in storage/logs/laravel.log '
                .'("Duitku refused to list payment methods").');

            return self::FAILURE;
        }

        $this->info(sprintf('Duitku sandbox accepted the signature and offered %d method(s): %s',
            count($methods), implode(', ', array_column($methods, 'code'))));

        return self::SUCCESS;
    }
}

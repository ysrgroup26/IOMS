<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** v2.132.0 -- ioms:duitku-check proves the sandbox accepts us, and never touches production. */
class DuitkuCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private function duitku(bool $production = false): void
    {
        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DXXXX',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => $production,
        ]);
        app()->forgetInstance(PaymentGatewayInterface::class);
    }

    public function test_it_passes_when_the_sandbox_lists_methods_and_never_prints_the_key(): void
    {
        $this->duitku();
        Http::fake(['sandbox.duitku.com/*' => Http::response([
            'paymentFee' => [['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'totalFee' => '0']],
            'responseCode' => '00',
        ])]);

        $this->artisan('ioms:duitku-check')
            ->expectsOutputToContain('offered 1 method(s): BC')
            ->doesntExpectOutputToContain('test-api-key')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sandbox.duitku.com/'));
    }

    public function test_it_fails_when_the_sandbox_refuses(): void
    {
        $this->duitku();
        Http::fake(['sandbox.duitku.com/*' => Http::response(['Message' => 'Invalid Signature'], 401)]);

        $this->artisan('ioms:duitku-check')->assertFailed();
    }

    public function test_it_refuses_to_run_against_production(): void
    {
        $this->duitku(production: true);
        Http::fake();

        $this->artisan('ioms:duitku-check')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_unconfigured_fails_unless_explicitly_allowed(): void
    {
        config(['payment.gateway' => null]);

        $this->artisan('ioms:duitku-check')->assertFailed();
        $this->artisan('ioms:duitku-check', ['--allow-unconfigured' => true])->assertSuccessful();
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The payment-method picker shows each channel's own logo, as Duitku serves
 * it, so a customer recognises their bank. Only HTTPS on Duitku's domain.
 */
class DuitkuPaymentLogoTest extends TestCase
{
    public function test_each_method_carries_duitku_s_own_logo_and_nothing_else(): void
    {
        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DXXXX',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => false,
        ]);
        app()->forgetInstance(PaymentGatewayInterface::class);

        Http::fake(['sandbox.duitku.com/*' => Http::response(['responseCode' => '00', 'paymentFee' => [
            ['paymentMethod' => 'I1', 'paymentName' => 'BNI VA', 'paymentImage' => 'https://images.duitku.com/hotlink-ok/I1.PNG', 'totalFee' => '0'],
            ['paymentMethod' => 'SP', 'paymentName' => 'NUSAPAY QRIS', 'paymentImage' => 'http://images.duitku.com/hotlink-ok/SP.PNG', 'totalFee' => '0'],
            ['paymentMethod' => 'M2', 'paymentName' => 'MANDIRI VA H2H', 'paymentImage' => 'https://evil.example/duitku.com.png', 'totalFee' => '0'],
            ['paymentMethod' => 'BR', 'paymentName' => 'BRI VA', 'totalFee' => '0'],
        ]])]);

        $methods = collect(app(PaymentGatewayInterface::class)->getPaymentMethods(239000))->keyBy('code');

        $this->assertSame('https://images.duitku.com/hotlink-ok/I1.PNG', $methods['I1']['image']);
        $this->assertNull($methods['SP']['image']);   // not HTTPS
        $this->assertNull($methods['M2']['image']);   // not Duitku's domain
        $this->assertNull($methods['BR']['image']);   // none sent
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Payments\MidtransGateway;
use App\Services\Payments\NullPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * v2.80.0 -- ADDING A SECOND PROVIDER MUST BE A NEW CLASS, NOT A NEW BRANCH.
 *
 * iPaymu is the second provider IOMS intends to support, and it is BLOCKED on
 * things outside this repository: sandbox credentials and the current official
 * API documentation for the signature, callback payload and status vocabulary.
 * Writing that adapter from memory would produce code that looks finished and
 * fails on first contact, so it is not written (docs/kb/backlog/iPaymu Payment
 * Provider.md).
 *
 * What IS in this release is the work that does not need iPaymu to exist: the
 * seam it will drop into, and this file, which pins the property that makes it
 * a drop-in. The audit that produced it found three real leaks, all now closed
 * and all asserted below -- two controllers doing
 * `$gateway instanceof MidtransGateway`, and the SHARED verified-payment path
 * calling a static on MidtransGateway to find the invoice a reference belonged
 * to. A second gateway would have been configured, bound, and then failed to
 * find its own invoices.
 */
class PaymentProviderAbstractionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Files that carry the subscription/billing DOMAIN. None of them may name
     * a provider: that is the definition of "no provider-specific logic
     * scattered through subscription code".
     */
    private const DOMAIN_FILES = [
        'app/Services/SubscriptionLifecycleService.php',
        'app/Services/TenantProvisioningService.php',
        'app/Services/EntitlementService.php',
        'app/Services/PlatformOperationsService.php',
        'app/Models/Subscription.php',
        'app/Models/Invoice.php',
        'app/Models/PaymentTransaction.php',
        'app/Http/Controllers/SubscriptionController.php',
        'app/Http/Controllers/Public/RegistrationController.php',
    ];

    /** Provider names that must appear only inside an adapter or its config. */
    private const PROVIDER_NAMES = ['midtrans', 'ipaymu', 'xendit', 'stripe', 'doku'];

    public function test_no_domain_file_names_a_payment_provider(): void
    {
        $offenders = [];

        foreach (self::DOMAIN_FILES as $relative) {
            $path = base_path($relative);

            if (! file_exists($path)) {
                continue;
            }

            // Comments are stripped: this codebase explains its own history in
            // comments, and "the Midtrans defect of v2.78.1" is documentation,
            // not coupling.
            $code = $this->stripComments(file_get_contents($path));

            foreach (self::PROVIDER_NAMES as $name) {
                if (stripos($code, $name) !== false) {
                    $offenders[] = $relative.' names "'.$name.'"';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Domain code must go through PaymentGatewayInterface, never name a provider:'],
            $offenders,
        )));
    }

    public function test_the_shared_verified_payment_path_resolves_invoices_through_the_contract(): void
    {
        // The single most important line in the audit: the webhook's apply()
        // is shared by every provider, so a static call to one provider there
        // would break the next one silently.
        $code = $this->stripComments(file_get_contents(base_path('app/Http/Controllers/PaymentWebhookController.php')));

        $this->assertStringContainsString('$gateway->invoiceIdFromReference(', $code);
        $this->assertStringNotContainsString('MidtransGateway::invoiceIdFromOrderId(', $code);
    }

    public function test_no_controller_branches_on_a_concrete_gateway_class(): void
    {
        foreach (['app/Http/Controllers/SubscriptionController.php', 'app/Http/Controllers/Public/RegistrationController.php'] as $relative) {
            $code = $this->stripComments(file_get_contents(base_path($relative)));

            $this->assertStringNotContainsString(
                'instanceof MidtransGateway',
                $code,
                $relative.' still branches on a concrete gateway; a second provider would have to be added to it.'
            );
        }
    }

    public function test_every_adapter_implements_the_whole_contract(): void
    {
        // Including the three calls added in v2.80.0 -- an adapter that
        // implements only the payment half is exactly how the instanceof
        // chains appeared in the first place.
        $required = array_map(
            fn ($m) => $m->getName(),
            (new ReflectionClass(PaymentGatewayInterface::class))->getMethods()
        );

        foreach ([MidtransGateway::class, NullPaymentGateway::class] as $adapter) {
            foreach ($required as $method) {
                $this->assertTrue(
                    method_exists($adapter, $method),
                    $adapter.' does not implement '.$method.'().'
                );
            }
        }

        $this->assertContains('isConfigured', $required);
        $this->assertContains('clientConfig', $required);
        $this->assertContains('invoiceIdFromReference', $required);
    }

    public function test_with_no_provider_configured_nothing_pretends_a_payment_is_possible(): void
    {
        $gateway = new NullPaymentGateway();

        // These three answer instead of throwing, because they are asked
        // BEFORE a payment is attempted -- to decide whether to offer one.
        $this->assertFalse($gateway->isConfigured());
        $this->assertSame([], $gateway->clientConfig());
        $this->assertNull($gateway->invoiceIdFromReference('INV1-20260101000000'));

        // And the ones that would take money still refuse loudly rather than
        // faking success.
        $this->expectException(\RuntimeException::class);
        $gateway->createCheckout(new Invoice());
    }

    public function test_the_payment_ledger_records_which_provider_took_each_payment(): void
    {
        // Reporting reads the provider from the data, so a second gateway
        // appears in the operations console with no code change.
        $columns = (new PaymentTransaction())->getFillable();

        $this->assertContains('gateway', $columns);
        $this->assertContains('gateway_reference', $columns);
    }

    /**
     * PHP comments only. This codebase explains its own history in comments --
     * "the Midtrans overcharge of v2.78.1" is documentation, not coupling -- so
     * scanning them would fail this test for being well documented.
     */
    private function stripComments(string $source): string
    {
        $source = preg_replace('#/\\*.*?\\*/#s', '', $source);

        return preg_replace('#^\\s*//.*$#m', '', $source);
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\Payments\DuitkuGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.94.0 -- THE DUITKU PAYMENT FLOW, END TO END.
 *
 * `AdminContextAndBillingFlowTest` already pins the two things that decide
 * whether the integration is SAFE: the callback signature formula, and the
 * fact that a verified callback is the only thing that settles an invoice.
 * Those are not repeated here.
 *
 * This covers what that test does not: the CHECKOUT side, where a payment
 * session is created, and the idempotency of everything around it. That is
 * where the defects were.
 *
 * THE BUG THIS FILE WAS WRITTEN TO CATCH. `SubscriptionController::
 * openCheckout()` decided whether to reuse a live payment session by asking
 * whether it had a `checkout_token`. Midtrans has one; Duitku, a hosted
 * redirect, returns null. So for Duitku the reuse branch could never be
 * taken, and every page load opened a NEW inquiry at the provider and wrote
 * a NEW payment transaction. The registration checkout was worse: it had no
 * reuse check at all.
 *
 * Neither is visible from reading the Duitku adapter, which is correct in
 * isolation. They only appear when you ask what the callers do with a null
 * token.
 */
class DuitkuPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->configureDuitku();

        // Every test in this file drives checkout, and not one of them may
        // reach the real provider. Faked at the HTTP boundary rather than by
        // mocking the gateway, so the adapter's own request assembly --
        // including the signature -- is exercised.
        Http::fake([
            '*duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
                'statusCode' => '00',
                'statusMessage' => 'SUCCESS',
                'reference' => 'DUITKU-REF-1',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/pay/ABC123',
            ]),
        ]);
    }

    /**
     * Tenants created BY THE TEST, not by the schema.
     *
     * `create_tenants_table` inserts a "Default Tenant" to backfill
     * pre-tenancy companies, so every test starts with one. Counting
     * absolutely would assert against that fixture instead of against what
     * the payment flow did.
     */
    private function tenantsCreated(): int
    {
        return Tenant::where('name', '!=', 'Default Tenant')->count();
    }

    private function configureDuitku(): void
    {
        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DXXXX',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => false,
        ]);

        app()->forgetInstance(PaymentGatewayInterface::class);
    }

    private function package(): Package
    {
        return Package::firstOrCreate(['slug' => 'starter'], [
            'name' => 'Starter', 'price_monthly' => 189000, 'price_yearly' => 1890000,
            'currency' => 'IDR', 'max_users' => 3, 'max_companies' => 1,
            'is_active' => true, 'is_public' => true,
        ]);
    }

    /** A verified registration sitting at the point where it would pay. */
    private function registration(array $overrides = []): TenantRegistration
    {
        return TenantRegistration::create([...[
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_VERIFIED,
            'contact_name' => 'Buyer',
            'contact_email' => 'buyer-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass-1'),
            'company_legal_name' => 'PT. Contoh Bayar',
            'company_display_name' => 'Contoh Bayar',
            'package_id' => $this->package()->id,
            'billing_cycle' => 'monthly',
            'amount' => 189000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'expires_at' => now()->addDays(7),
        ], ...$overrides]);
    }

    /* ==================================================================
     * CHECKOUT IS NOT A PAYMENT
     * ================================================================== */

    /**
     * Returning to checkout must reuse the live payment session, not open
     * a second one.
     *
     * A customer who clicks pay, thinks better of it, and comes back ten
     * minutes later is the ordinary case, not an edge case. Each repeat
     * previously cost a real inquiry at the provider and left another
     * pending row behind, so a single customer could accumulate a dozen
     * "pending payments" for one invoice -- which is also what an operator
     * then has to reconcile.
     */
    public function test_returning_to_registration_checkout_reuses_the_payment_session(): void
    {
        $registration = $this->registration();

        // Time MOVES between the attempts, on purpose. The reference
        // carries a timestamp, so without this the second attempt collides
        // with the first on the unique index and the test would pass
        // whether or not the reuse branch works at all.
        $this->post(route('register.checkout', $registration->token));
        $this->travel(2)->seconds();
        $this->post(route('register.checkout', $registration->token));
        $this->travel(2)->seconds();
        $this->post(route('register.checkout', $registration->token));

        $invoice = $registration->fresh()->invoice;

        $this->assertNotNull($invoice);
        $this->assertSame(
            1,
            PaymentTransaction::where('invoice_id', $invoice->id)->count(),
            'Three visits to checkout must leave ONE payment session, not three.',
        );

        // And exactly one invoice, reused rather than reissued.
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('registration_id', $registration->id)->count());
    }

    /** The same rule for an existing customer paying a renewal. */
    public function test_returning_to_subscription_checkout_reuses_the_payment_session(): void
    {
        [$admin, $invoice] = $this->tenantWithRenewalInvoice();

        $this->actingAs($admin)->get(route('subscription.pay', $invoice));
        $this->travel(2)->seconds();
        $this->actingAs($admin)->get(route('subscription.pay', $invoice));

        $this->assertSame(
            1,
            PaymentTransaction::where('invoice_id', $invoice->id)->count(),
            'Reopening the payment page must not open a second session.',
        );
    }

    /**
     * A reused session keeps its reference, so the callback that eventually
     * arrives still matches the row IOMS is waiting on.
     */
    public function test_a_reused_session_keeps_its_gateway_reference(): void
    {
        $registration = $this->registration();

        $this->post(route('register.checkout', $registration->token));
        $first = PaymentTransaction::latest('id')->first();

        $this->travel(2)->seconds();
        $this->post(route('register.checkout', $registration->token));
        $second = PaymentTransaction::latest('id')->first();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->gateway_reference, $second->gateway_reference);
    }

    /** Opening a payment page leaves the invoice unpaid and grants nothing. */
    public function test_opening_checkout_never_marks_anything_paid(): void
    {
        $registration = $this->registration();

        $this->post(route('register.checkout', $registration->token));

        $this->assertSame(Invoice::STATUS_ISSUED, $registration->fresh()->invoice->status);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, PaymentTransaction::latest('id')->first()->status);
        $this->assertNull($registration->fresh()->paid_at);
        $this->assertNull($registration->fresh()->tenant_id);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /* ==================================================================
     * THE REFERENCE
     * ================================================================== */

    /**
     * Two sessions opened inside the same second must not collide.
     *
     * The reference was invoice id plus a to-the-second timestamp, and
     * `payment_transactions.gateway_reference` is unique. Two attempts in
     * one second therefore produced a constraint violation, which the
     * caller swallowed as "checkout could not be created" -- a payment the
     * customer could not make, for no reason they could see.
     */
    public function test_two_references_minted_in_the_same_second_are_distinct(): void
    {
        $invoice = $this->bareInvoice();

        $this->travelTo(now()->startOfSecond());

        $a = DuitkuGateway::orderIdFor($invoice);
        $b = DuitkuGateway::orderIdFor($invoice);

        $this->assertNotSame($a, $b, 'A reference minted twice in one second must still be unique.');
    }

    /** However it is minted, it still points back at exactly one invoice. */
    public function test_a_reference_remains_traceable_to_its_invoice(): void
    {
        $invoice = $this->bareInvoice();
        $gateway = app(PaymentGatewayInterface::class);

        $reference = DuitkuGateway::orderIdFor($invoice);

        $this->assertSame($invoice->id, $gateway->invoiceIdFromReference($reference));
    }

    /* ==================================================================
     * THE CALLBACK, AND WHAT IT MUST REFUSE
     * ================================================================== */

    /**
     * Duitku retries a callback it did not get a 200 for. A retry must not
     * settle the invoice twice, extend the period twice, or provision a
     * second tenant.
     */
    public function test_a_redelivered_callback_settles_nothing_a_second_time(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        $payload = $this->signedCallback($transaction->gateway_reference, '189000', '00');

        $this->post(route('webhooks.payment.duitku'), $payload)->assertOk();
        $this->post(route('webhooks.payment.duitku'), $payload)->assertOk();
        $this->post(route('webhooks.payment.duitku'), $payload)->assertOk();

        $this->assertSame(1, $this->tenantsCreated(), 'Three deliveries of one payment must provision one tenant.');
        $this->assertSame(1, Subscription::count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /**
     * A callback for an amount below the invoice total must not settle it.
     *
     * The signature covers the amount, so this is not a forgery scenario --
     * it is a partial or mis-keyed payment, and treating it as settlement
     * would hand over a subscription that was not paid for.
     */
    public function test_a_callback_for_less_than_the_invoice_does_not_settle_it(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '1000', '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated(), 'An underpayment must provision nothing.');
    }

    /** A callback naming an order IOMS has never heard of changes nothing. */
    public function test_a_callback_for_an_unknown_order_settles_nothing(): void
    {
        [$invoice] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback('INV999999-20260101120000', '189000', '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /** A failed result code records the failure and activates nothing. */
    public function test_a_failed_callback_activates_nothing(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '189000', '01'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->fresh()->status);
        $this->assertSame(0, $this->tenantsCreated());
    }

    /** The whole point: a verified callback DOES provision the tenant. */
    public function test_a_verified_callback_settles_the_invoice_and_provisions_the_tenant(): void
    {
        [$invoice, $transaction, $registration] = $this->pendingPaymentForRegistration(withRegistration: true);

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '189000', '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(PaymentTransaction::STATUS_PAID, $transaction->fresh()->status);
        $this->assertNotNull($registration->fresh()->tenant_id);
        $this->assertSame(1, $this->tenantsCreated());

        // Settled by Duitku, and recorded as such -- not under another
        // provider's name.
        $this->assertSame('duitku', $invoice->fresh()->payment_method);
    }

    /* ==================================================================
     * WHAT THE ROW HAS TO ANSWER AFTERWARDS (v2.94.0)
     * ================================================================== */

    /**
     * A settled payment records the provider's own facts.
     *
     * None of these decide anything -- the settlement already happened on
     * the mapped result. They exist because "the customer says they paid on
     * Tuesday and Duitku shows reference X" was previously unanswerable
     * from this table.
     */
    public function test_a_settled_payment_records_the_providers_own_facts(): void
    {
        [, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(route('webhooks.payment.duitku'), [
            ...$this->signedCallback($transaction->gateway_reference, '189000', '00'),
            'publisherOrderId' => 'PUB-77',
            'paymentCode' => 'BC',
        ])->assertOk();

        $transaction->refresh();

        $this->assertSame(PaymentTransaction::STATUS_PAID, $transaction->status);
        $this->assertSame('DUITKU-REF-1', $transaction->provider_reference);
        $this->assertSame('PUB-77', $transaction->publisher_order_id);
        $this->assertSame('BC', $transaction->payment_method);
        $this->assertSame('00', $transaction->result_code);
        $this->assertNotNull($transaction->paid_at);
        $this->assertNotNull($transaction->callback_received_at);
        $this->assertNull($transaction->failure_reason);
    }

    /**
     * THE SIGNATURE IS NEVER STORED.
     *
     * It is derived from the API key, so keeping it would put a
     * secret-derived value in the table beside the data it authenticates --
     * and it has no use once verification has happened.
     */
    public function test_the_stored_provider_metadata_never_contains_the_signature(): void
    {
        [, $transaction] = $this->pendingPaymentForRegistration();

        $payload = $this->signedCallback($transaction->gateway_reference, '189000', '00');
        $this->post(route('webhooks.payment.duitku'), $payload)->assertOk();

        $metadata = $transaction->fresh()->provider_metadata;

        $this->assertIsArray($metadata);
        $this->assertArrayNotHasKey('signature', $metadata);
        $this->assertArrayHasKey('merchantOrderId', $metadata);
        $this->assertStringNotContainsString(
            $payload['signature'],
            json_encode($transaction->fresh()->toArray()),
            'No part of the row may carry the signature.',
        );
    }

    /** A failed callback is explained, not merely marked failed. */
    public function test_a_failed_payment_records_why(): void
    {
        [, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(route('webhooks.payment.duitku'), [
            ...$this->signedCallback($transaction->gateway_reference, '189000', '01'),
            'statusMessage' => 'Transaction expired at the bank.',
        ])->assertOk();

        $transaction->refresh();

        $this->assertSame(PaymentTransaction::STATUS_FAILED, $transaction->status);
        $this->assertSame('01', $transaction->result_code);
        $this->assertSame('Transaction expired at the bank.', $transaction->failure_reason);
        $this->assertNotNull($transaction->callback_received_at);
        $this->assertNull($transaction->paid_at, 'A failed payment has no payment time.');
    }

    /** A refused amount is recorded on the row, not only in the log. */
    public function test_an_underpayment_is_recorded_as_a_refusal(): void
    {
        [, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '1000', '00'),
        )->assertOk();

        $transaction->refresh();

        $this->assertNull($transaction->paid_at);
        $this->assertNotNull($transaction->failure_reason);
        $this->assertStringContainsString('Amount below', $transaction->failure_reason);
    }

    /**
     * The amount is checked against the SESSION as well as the invoice.
     *
     * If an invoice were repriced downward after the customer opened
     * checkout, settling against the invoice alone would accept less than
     * the figure the customer was actually shown and sent to the provider.
     */
    public function test_a_payment_below_the_opened_session_amount_is_refused(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        // The session was opened at 189000. The invoice is then corrected
        // downward; the customer is still mid-payment on the old figure.
        $invoice->update(['amount' => 1000]);

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '1000', '00'),
        )->assertOk();

        $this->assertSame(
            Invoice::STATUS_ISSUED,
            $invoice->fresh()->status,
            'A payment below the amount the session was opened for must not settle it.',
        );
    }

    /* ==================================================================
     * DECIDED POLICY (v2.94.0)
     * ================================================================== */

    /**
     * AN OVERPAYMENT IS REFUSED, not quietly kept.
     *
     * The tempting reading is that the customer paid at least what was
     * owed, so activate them. But an amount IOMS did not ask for means its
     * idea of the price and the provider's have diverged, and quietly
     * keeping money against a subscription whose price nobody can
     * reconstruct is worse than a delayed activation. A human decides.
     */
    public function test_an_overpayment_is_refused_and_escalated(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '500000', '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status, 'An overpayment must not settle the invoice.');
        $this->assertSame(0, $this->tenantsCreated(), 'An overpayment must provision nothing.');
        $this->assertNull($transaction->fresh()->paid_at);
        $this->assertStringContainsString('above the expected total', $transaction->fresh()->failure_reason);

        // Escalated, not merely logged.
        $this->assertNotNull(
            ActivityLog::withoutGlobalScopes()
                ->where('description', 'like', '%was NOT settled%')
                ->latest('id')
                ->first(),
            'A refused amount must leave an audit record somebody can find.',
        );
    }

    /** The exact amount still settles. The tolerance is a cent, not a policy. */
    public function test_the_exact_amount_still_settles(): void
    {
        [$invoice, $transaction] = $this->pendingPaymentForRegistration();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, '189000', '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNotNull($transaction->fresh()->paid_at);
        $this->assertNull($transaction->fresh()->failure_reason);
    }

    /**
     * AN UNFINISHED ORDER IS ALWAYS RESUMABLE.
     *
     * The scenario this was decided for: a customer starts in October,
     * abandons checkout, and returns weeks later. They resume the SAME
     * order and the SAME invoice.
     *
     * Previously the public status page DISABLED THE PAY BUTTON once
     * `expires_at` passed, while the subscribe flow resumed the same row
     * and pushed the date forward -- so whether a returning customer could
     * pay depended on which link they came back through.
     */
    public function test_an_order_abandoned_weeks_ago_can_still_be_paid(): void
    {
        $registration = $this->registration(['expires_at' => now()->subDays(13)]);

        // Nineteen days later, the customer comes back to their own status
        // link and pays.
        $this->travel(19)->days();

        $this->post(route('register.checkout', $registration->token))
            ->assertSessionHasNoErrors();

        $registration->refresh();
        $invoice = $registration->invoice;

        $this->assertNotNull($invoice, 'A returning customer must still reach an invoice.');
        $this->assertSame(1, PaymentTransaction::where('invoice_id', $invoice->id)->count());

        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->firstOrFail();

        $this->post(
            route('webhooks.payment.duitku'),
            $this->signedCallback($transaction->gateway_reference, (string) (int) $invoice->amount, '00'),
        )->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNotNull($registration->fresh()->tenant_id);
    }

    /** Resuming keeps ONE registration and ONE invoice, never a second pair. */
    public function test_resuming_an_abandoned_order_does_not_create_a_second_invoice(): void
    {
        $registration = $this->registration(['expires_at' => now()->subDays(20)]);

        $this->post(route('register.checkout', $registration->token));
        $firstInvoiceId = $registration->fresh()->invoice_id;

        $this->travel(30)->days();
        $this->post(route('register.checkout', $registration->token));

        $this->assertSame($firstInvoiceId, $registration->fresh()->invoice_id);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('registration_id', $registration->id)->count());
        $this->assertSame(1, TenantRegistration::where('contact_email', $registration->contact_email)->count());
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    private function signedCallback(string $orderId, string $amount, string $resultCode): array
    {
        return [
            'merchantCode' => 'DXXXX',
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => $resultCode,
            'reference' => 'DUITKU-REF-1',
            'signature' => hash_hmac('sha256', 'DXXXX'.$amount.$orderId, 'test-api-key'),
        ];
    }

    private function bareInvoice(): Invoice
    {
        return Invoice::withoutGlobalScopes()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'amount' => 189000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'purpose' => Invoice::PURPOSE_ONBOARDING,
        ]);
    }

    /**
     * A registration that has reached checkout: a real invoice and a real
     * pending payment session, exactly as the controller would leave them.
     *
     * @return array{0: Invoice, 1: PaymentTransaction, 2?: TenantRegistration}
     */
    private function pendingPaymentForRegistration(bool $withRegistration = false): array
    {
        $registration = $this->registration();

        $this->post(route('register.checkout', $registration->token));

        $registration->refresh();
        $invoice = $registration->invoice;
        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->firstOrFail();

        return $withRegistration ? [$invoice, $transaction, $registration] : [$invoice, $transaction];
    }

    /** @return array{0: User, 1: Invoice} */
    private function tenantWithRenewalInvoice(): array
    {
        $tenant = Tenant::create(['name' => 'Payer', 'slug' => 'payer-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $this->package()->id,
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(3),
        ]);

        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_number' => 'INV-REN-'.uniqid(),
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'amount' => 189000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'purpose' => Invoice::PURPOSE_RENEWAL,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);

        $admin = User::create([
            'name' => 'Owner', 'email' => 'owner-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        return [$admin, $invoice];
    }
}

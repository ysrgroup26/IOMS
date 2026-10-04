<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\TenantRegistration;
use App\Services\Payments\DuitkuGateway;
use App\Services\Payments\MidtransGateway;
use App\Services\SubscriptionLifecycleService;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v2.51.0 -- the ONLY endpoint in IOMS that may confirm a payment.
 *
 * Everything about this controller is shaped by one rule: a browser
 * reaching a success page proves nothing. Activation is driven from a
 * payload the provider signed and this server verified.
 *
 * Order of operations, and why:
 *
 *  1. VERIFY FIRST. The signature is checked before any state is read or
 *     written, so an unsigned payload cannot even cause a lookup.
 *  2. RECORD, THEN CHECK. PaymentWebhookEvent::recordIfNew() is unique on
 *     (gateway, event_id), so the database itself -- not application
 *     logic -- decides whether this delivery is the first. A replay finds
 *     an already-processed row and returns 200 without re-applying.
 *  3. AMOUNT IS RE-CHECKED. A notification claiming a smaller payment than
 *     the invoice is never treated as settlement.
 *  4. ALWAYS 200 ON A HANDLED EVENT. Gateways retry non-2xx responses; a
 *     500 for an event we understood but chose not to act on produces a
 *     retry storm rather than a fix.
 *
 * v2.70.0 -- this endpoint now settles RENEWALS and PLAN CHANGES too, not
 * only onboarding. The rule above is unchanged and the reason it matters
 * has grown: a payment verified here is the only thing in IOMS that can
 * extend a subscription period or move a customer to a different plan.
 * What a given payment buys is read from the invoice's own `purpose`, so
 * the decision is made from server-side state, never from anything the
 * payer's browser carried back.
 */
class PaymentWebhookController extends Controller
{
    public function midtrans(
        Request $request,
        TenantProvisioningService $provisioning,
        SubscriptionLifecycleService $lifecycle,
    ): JsonResponse {
        $payload = $request->all();
        $gateway = app(PaymentGatewayInterface::class);

        if (! $gateway instanceof MidtransGateway) {
            // Midtrans is not the configured provider on this deployment.
            // Refuse rather than half-processing a payload from a gateway
            // this instance is not set up to trust.
            Log::warning('Midtrans webhook received while Midtrans is not the configured gateway.');

            return response()->json(['message' => 'Gateway not configured.'], 503);
        }

        if (! $gateway->verifyWebhookSignature($payload, $request->headers->all())) {
            Log::warning('Rejected a Midtrans webhook with an invalid signature.', [
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Midtrans has no separate event id, so the event identity is the
        // order plus its status plus the provider's own transaction id --
        // the exact tuple that makes one state transition unique. A repeat
        // of the same transition collides on the unique index; a genuine
        // later transition (pending -> settlement) does not.
        $eventId = implode(':', [
            $payload['order_id'] ?? 'unknown',
            $payload['transaction_status'] ?? 'unknown',
            $payload['transaction_id'] ?? '',
        ]);

        $event = PaymentWebhookEvent::recordIfNew(
            MidtransGateway::GATEWAY,
            $eventId,
            (string) ($payload['transaction_status'] ?? ''),
            $payload,
            true,
        );

        if ($event->processed) {
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            $result = $gateway->handleWebhook($payload);
            $this->apply($gateway, $result->gatewayReference, $result->status, $result->amount, $provisioning, $lifecycle, $payload);

            $event->update(['processed' => true, 'processed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Midtrans webhook processing failed.', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            // Left unprocessed on purpose so a retry can succeed once the
            // underlying problem is fixed. 500 asks the gateway to retry.
            return response()->json(['message' => 'Processing failed.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * v2.84.1 -- THE DUITKU ENTRY POINT, and the thing to notice is how
     * little of it there is.
     *
     * Same four rules as the Midtrans endpoint above, in the same order:
     * verify before anything is read, record-then-check so the database
     * decides whether a delivery is the first, re-check the amount, and
     * return 200 for an event that was understood. Only the payload's shape
     * and the event identity differ, because only those are Duitku's.
     *
     * Duitku posts form-encoded and carries no event id of its own, so the
     * identity is the order plus its result plus Duitku's own reference --
     * the tuple that makes one state transition unique. A redelivery of the
     * same transition collides on the unique index; a genuine later one does
     * not.
     */
    public function duitku(
        Request $request,
        TenantProvisioningService $provisioning,
        SubscriptionLifecycleService $lifecycle,
    ): JsonResponse {
        $payload = $request->all();
        $gateway = app(PaymentGatewayInterface::class);

        if (! $gateway instanceof DuitkuGateway) {
            Log::warning('Duitku callback received while Duitku is not the configured gateway.');

            return response()->json(['message' => 'Gateway not configured.'], 503);
        }

        if (! $gateway->verifyWebhookSignature($payload, $request->headers->all())) {
            Log::warning('Rejected a Duitku callback with an invalid signature.', [
                'merchantOrderId' => $payload['merchantOrderId'] ?? null,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $eventId = implode(':', [
            $payload['merchantOrderId'] ?? 'unknown',
            $payload['resultCode'] ?? 'unknown',
            $payload['reference'] ?? '',
        ]);

        $event = PaymentWebhookEvent::recordIfNew(
            DuitkuGateway::GATEWAY,
            $eventId,
            (string) ($payload['resultCode'] ?? ''),
            $payload,
            true,
        );

        if ($event->processed) {
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            $result = $gateway->handleWebhook($payload);
            $this->apply($gateway, $result->gatewayReference, $result->status, $result->amount, $provisioning, $lifecycle, $payload);

            $event->update(['processed' => true, 'processed_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Duitku callback processing failed.', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * v2.94.0 -- the provider's own facts, pulled from an ALREADY-VERIFIED
     * payload into the columns that describe a payment.
     *
     * Deliberately field-by-field rather than storing the payload wholesale.
     * The raw body is already kept on `payment_webhook_events`; what belongs
     * on the transaction is the handful of facts a human asks for.
     *
     * THE SIGNATURE IS STRIPPED. It is derived from the API key, so keeping
     * it would place a secret-derived value in the table beside the data it
     * authenticates -- and it has no use after verification, which has
     * already happened by the time this runs.
     */
    private function providerFacts(array $payload, string $status): array
    {
        if ($payload === []) {
            return [];
        }

        $metadata = $payload;
        unset($metadata['signature']);

        $reason = $payload['statusMessage'] ?? $payload['status_message'] ?? null;

        return [
            // Duitku: reference. Midtrans: transaction_id.
            'provider_reference' => $payload['reference'] ?? $payload['transaction_id'] ?? null,
            'publisher_order_id' => $payload['publisherOrderId'] ?? null,
            'payment_method' => $payload['paymentCode'] ?? $payload['payment_type'] ?? null,
            'result_code' => $payload['resultCode'] ?? $payload['status_code'] ?? null,
            'failure_reason' => $status === 'failed' ? mb_substr((string) $reason, 0, 500) ?: 'Provider reported a failed payment.' : null,
            'provider_metadata' => $metadata,
        ];
    }

    /**
     * Applies a verified payment result. Extracted from the Midtrans entry
     * point so a second gateway adapter reuses the identical state
     * machine rather than writing its own -- which is exactly what the
     * Duitku endpoint above does.
     */
    private function apply(
        PaymentGatewayInterface $gateway,
        string $gatewayReference,
        string $status,
        ?float $amount,
        TenantProvisioningService $provisioning,
        SubscriptionLifecycleService $lifecycle,
        array $payload = [],
    ): void {
        // v2.80.0: which invoice a reference belongs to is the ADAPTER'S
        // knowledge, because the reference format is. This is the shared
        // verified-payment path, so naming one provider here was the single
        // worst place for provider-specific logic to sit -- a second gateway
        // would have silently failed to find its own invoices.
        $invoiceId = $gateway->invoiceIdFromReference($gatewayReference);
        $transaction = PaymentTransaction::with('invoice')->where('gateway_reference', $gatewayReference)->first();
        $invoice = $transaction?->invoice ?? ($invoiceId ? Invoice::find($invoiceId) : null);

        if (! $invoice) {
            Log::warning('Verified payment notification did not match any invoice.', ['reference' => $gatewayReference]);

            return;
        }

        // v2.94.0 -- WRITE DOWN WHAT THE PROVIDER SAID, whatever it said.
        //
        // Recorded for EVERY verified callback, including failures, and
        // before any decision below. A failed payment nobody can explain is
        // the support case that costs the most time, and "the callback
        // arrived at 14:02 with result code 01" is the whole answer.
        //
        // None of it is load-bearing: the status transition is still decided
        // from the mapped result, and the provider's own ids are stored to
        // be quoted, never to be matched on.
        $transaction?->update([
            'status' => $status,
            'callback_received_at' => now(),
            ...$this->providerFacts($payload, $status),
        ]);

        if ($status !== 'paid') {
            // Failed / expired / still pending. The registration stays
            // where it is: a failed payment must never activate anything,
            // and the customer can retry checkout.
            //
            // v2.79.0: a failed attempt is recorded on the platform
            // operator's feed, because it is the other thing they get asked
            // about. Nothing about the subscription changes, which is the
            // point -- this is visibility, not a state transition.
            if ($status === 'failed') {
                app(\App\Services\NotificationService::class)->notifyPlatformAdmins(
                    \App\Models\Notification::CATEGORY_WARNING,
                    'Pembayaran gagal: '.($invoice->tenant?->name ?? $invoice->invoice_number),
                    'Tagihan '.$invoice->invoice_number.' belum lunas. Tidak ada akses yang berubah.',
                    $invoice->tenant_id ? route('platform.tenants.show', $invoice->tenant_id) : null,
                    $invoice,
                );
            }

            return;
        }

        /*
         * A settlement notification must actually cover what was owed.
         *
         * v2.94.0 -- CHECKED AGAINST BOTH the invoice and the payment
         * session, where there is one. The invoice is the contract; the
         * transaction is the amount the customer was actually SHOWN and sent
         * to the provider. They are normally identical, and when they are
         * not, settling against whichever happens to be lower would accept a
         * payment for a figure nobody agreed to. Taking the higher is the
         * safe direction: the one thing that must never happen is treating
         * an underpayment as settlement.
         */
        $expected = max((float) $invoice->amount, (float) ($transaction?->amount ?? 0));

        if ($amount !== null && $amount + 0.01 < $expected) {
            Log::warning('Payment notification amount is below what was owed; not treating it as settled.', [
                'invoice' => $invoice->invoice_number,
                'notified' => $amount,
                'expected' => $expected,
            ]);

            // Recorded as a refusal rather than left silent: an amount that
            // does not match is exactly the event somebody needs to find
            // later, and the log line alone is not queryable.
            $transaction?->update([
                'failure_reason' => 'Amount below the expected total (notified '.$amount.', expected '.$expected.').',
            ]);

            return;
        }

        DB::transaction(function () use ($invoice, $gatewayReference, $lifecycle, $gateway, $transaction) {
            // THE IDEMPOTENCY BOUNDARY. Everything a payment CHANGES sits
            // inside this guard, so a redelivered notification that slips
            // past the event-id unique index still finds a settled invoice
            // and extends nothing a second time. Settling the invoice and
            // acting on it are one atomic step: an invoice can never be
            // paid-but-not-applied, nor applied twice.
            if ($invoice->status === Invoice::STATUS_PAID) {
                return;
            }

            // v2.84.1: the CONFIGURED provider's own name, not a literal.
            // This is the shared path every gateway settles through, so a
            // hardcoded name here made a second adapter record its payments
            // under the first one's.
            $invoice->markPaid($gatewayReference, $gateway->gatewayName());

            // v2.94.0: when the money was confirmed, on the row that
            // represents the payment. `updated_at` moves for any write and
            // was never a payment time.
            $transaction?->update(['paid_at' => now()]);

            if ($invoice->registration_id) {
                TenantRegistration::whereKey($invoice->registration_id)
                    ->where('status', '!=', TenantRegistration::STATUS_PROVISIONED)
                    ->update(['status' => TenantRegistration::STATUS_PAID, 'paid_at' => now()]);

                return;
            }

            // An existing customer buying another period, or an upgrade.
            // The invoice says which; the service does the arithmetic and
            // re-entitles the tenant if the plan moved.
            if ($invoice->subscription_id) {
                $lifecycle->applyPaidInvoice($invoice);
            }
        });

        // Onboarding payment -> provision the tenant. The service is itself
        // idempotent, so this is safe even if the guards above ever let a
        // duplicate through.
        if ($invoice->registration_id) {
            $registration = TenantRegistration::find($invoice->registration_id);

            if ($registration) {
                $provisioning->activate($registration);
            }
        }
    }
}

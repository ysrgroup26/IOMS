<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\TenantRegistration;
use App\Services\Payments\MidtransGateway;
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
 */
class PaymentWebhookController extends Controller
{
    public function midtrans(Request $request, TenantProvisioningService $provisioning): JsonResponse
    {
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
            $this->apply($result->gatewayReference, $result->status, $result->amount, $provisioning);

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
     * Applies a verified payment result. Extracted from the Midtrans entry
     * point so a second gateway adapter reuses the identical state
     * machine rather than writing its own.
     */
    private function apply(string $gatewayReference, string $status, ?float $amount, TenantProvisioningService $provisioning): void
    {
        $invoiceId = MidtransGateway::invoiceIdFromOrderId($gatewayReference);
        $transaction = PaymentTransaction::where('gateway_reference', $gatewayReference)->first();
        $invoice = $transaction?->invoice ?? ($invoiceId ? Invoice::find($invoiceId) : null);

        if (! $invoice) {
            Log::warning('Verified payment notification did not match any invoice.', ['reference' => $gatewayReference]);

            return;
        }

        $transaction?->update(['status' => $status]);

        if ($status !== 'paid') {
            // Failed / expired / still pending. The registration stays
            // where it is: a failed payment must never activate anything,
            // and the customer can retry checkout.
            return;
        }

        // A settlement notification must actually cover the invoice.
        if ($amount !== null && $amount + 0.01 < (float) $invoice->amount) {
            Log::warning('Payment notification amount is below the invoice total; not treating it as settled.', [
                'invoice' => $invoice->invoice_number,
                'notified' => $amount,
                'expected' => (float) $invoice->amount,
            ]);

            return;
        }

        DB::transaction(function () use ($invoice, $gatewayReference) {
            if ($invoice->status !== Invoice::STATUS_PAID) {
                $invoice->markPaid($gatewayReference, MidtransGateway::GATEWAY);
            }

            if ($invoice->registration_id) {
                TenantRegistration::whereKey($invoice->registration_id)
                    ->where('status', '!=', TenantRegistration::STATUS_PROVISIONED)
                    ->update(['status' => TenantRegistration::STATUS_PAID, 'paid_at' => now()]);
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

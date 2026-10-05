<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v2.104.0 -- THE RULES AN ADD-ON IS BOUGHT AND RENEWED BY.
 *
 * Capacity used to be a number on the subscription that went live at once
 * and appeared on the next renewal invoice. The approved model replaces
 * that with a purchase, and this class is where the four rules live:
 *
 *   PAID BEFORE IT IS GRANTED. `purchase()` raises an invoice and creates
 *   the add-on in `pending_payment`. It grants nothing. Only `activate()`,
 *   reached from a settled invoice, sets the period -- the same standing
 *   rule as everywhere else in IOMS: reaching a confirmation page grants
 *   nothing, a verified payment does.
 *
 *   ITS OWN CLOCK. The period starts when the payment settles, not when
 *   the subscription renews, so two add-ons bought two months apart fall
 *   due two months apart. Nothing here touches the subscription's dates.
 *
 *   NO PRORATION. A period is bought whole. Buying mid-month does not
 *   discount the first period and cancelling does not refund the rest of
 *   one, which is why cancellation leaves `ends_at` alone.
 *
 *   EARLY PAYMENT MUST NOT MOVE THE SCHEDULE. `extend()` adds a period to
 *   the EXISTING `ends_at`, never to "now", so paying a renewal a week
 *   early buys the same period it always did and the anniversary never
 *   drifts earlier. Paying the same invoice twice extends once, because
 *   the extension is keyed on the invoice.
 *
 * PRICES ARE READ HERE AND NOWHERE ELSE. The browser sends a quantity and a
 * type; the unit price comes from configuration at purchase and is then
 * SNAPSHOTTED on the row, so a later catalogue change cannot reprice a
 * customer who already bought.
 */
class AddOnPurchaseService
{
    /** Per unit, per month. The browser never sends an amount. */
    public function unitPrice(string $type): float
    {
        return match ($type) {
            SubscriptionAddOn::TYPE_ADDITIONAL_USERS => (float) config('saas.additional_user_price', 0),
            SubscriptionAddOn::TYPE_MY_WORK_PACKS => (float) config('saas.my_work_pack_price', 0),
            default => 0.0,
        };
    }

    private function invoiceItemKind(string $type): string
    {
        return match ($type) {
            SubscriptionAddOn::TYPE_ADDITIONAL_USERS => InvoiceItem::KIND_ADDITIONAL_USERS,
            SubscriptionAddOn::TYPE_MY_WORK_PACKS => InvoiceItem::KIND_MY_WORK_PACKS,
            default => InvoiceItem::KIND_SUBSCRIPTION,
        };
    }

    /**
     * Creates the add-on and the invoice that must be paid before it counts.
     *
     * Returns the invoice, because the only sensible next step for the
     * caller is to send the customer to pay it.
     */
    public function purchase(
        Subscription $subscription,
        string $type,
        int $quantity,
        ?User $operator = null,
    ): Invoice {
        return DB::transaction(function () use ($subscription, $type, $quantity, $operator) {
            $unitPrice = $this->unitPrice($type);

            $addOn = SubscriptionAddOn::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'currency' => $subscription->agreed_currency ?: 'IDR',
                'billing_cycle' => $subscription->billing_cycle,
                'status' => SubscriptionAddOn::STATUS_PENDING_PAYMENT,
                'created_by' => $operator?->id,
            ]);

            // Loaded so periodCharge() can ask the subscription how many
            // months an annual payment is priced at.
            $addOn->setRelation('subscription', $subscription);

            $invoice = $this->raiseInvoice($addOn, Invoice::PURPOSE_ADD_ON, $addOn->periodCharge(), $operator);

            ActivityLog::record(
                'created',
                "Add-on purchase raised: {$quantity} x {$addOn->label()}, invoice {$invoice->invoice_number}.",
                $subscription
            );

            return $invoice;
        });
    }

    /**
     * The renewal invoice for an add-on that is about to lapse.
     *
     * Raised ahead of `ends_at` so the customer can pay before capacity
     * stops counting. It does NOT extend anything: payment does that.
     */
    public function raiseRenewalInvoice(SubscriptionAddOn $addOn): Invoice
    {
        // Still PURPOSE_ADD_ON, not PURPOSE_RENEWAL: the renewal machinery
        // keys on purpose to decide whether to move a SUBSCRIPTION's dates,
        // and an add-on renewal moves none of them.
        return $this->raiseInvoice($addOn, Invoice::PURPOSE_ADD_ON, $addOn->periodCharge());
    }

    private function raiseInvoice(SubscriptionAddOn $addOn, string $purpose, float $amount, ?User $operator = null): Invoice
    {
        /*
         * The period this invoice BUYS, which for a renewal is the one
         * after the current `ends_at` and for a first purchase starts now.
         * Stated on the invoice so a customer paying late can see what they
         * are paying for, rather than inferring it from the payment date.
         */
        $periodStart = $addOn->ends_at ?? now();
        $periodEnd = $addOn->periodEndFrom($periodStart);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateNumber($addOn->tenant_id),
            'tenant_id' => $addOn->tenant_id,
            'subscription_id' => $addOn->subscription_id,
            'subscription_add_on_id' => $addOn->id,
            'purpose' => $purpose,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount' => $amount,
            'currency' => $addOn->currency,
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays((int) config('saas.invoice_due_days', 14)),
            'notes' => "{$addOn->quantity} x {$addOn->label()}",
            'created_by' => $operator?->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'kind' => $this->invoiceItemKind($addOn->type),
            'description' => "{$addOn->label()} ({$addOn->quantity})",
            'quantity' => $addOn->quantity,
            'unit_amount' => (float) $addOn->unit_price,
            'amount' => $amount,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    /**
     * Applies a settled add-on invoice. Idempotent on the invoice.
     *
     * A first payment ACTIVATES: the period starts now, because now is when
     * the customer began paying for it. A renewal EXTENDS from the existing
     * `ends_at`, never from now -- that is the whole of "early payment must
     * not shift the schedule". A customer who pays a week early gets the
     * period they bought and their anniversary does not move; one who pays
     * after lapsing starts a fresh period from today rather than being sold
     * time that has already passed.
     */
    public function applyPaidInvoice(Invoice $invoice): void
    {
        $addOn = $invoice->subscriptionAddOn;

        if (! $addOn) {
            return;
        }

        if ($addOn->status === SubscriptionAddOn::STATUS_PENDING_PAYMENT) {
            $start = now();

            $addOn->forceFill([
                'status' => SubscriptionAddOn::STATUS_ACTIVE,
                'starts_at' => $start,
                'ends_at' => $addOn->periodEndFrom($start),
            ])->save();

            ActivityLog::record(
                'updated',
                "Add-on activated: {$addOn->quantity} x {$addOn->label()}, until {$addOn->ends_at->format('d M Y')}.",
                $addOn->subscription
            );

            return;
        }

        /*
         * ALREADY APPLIED. The webhook guards against settling one invoice
         * twice, but this is money and the service must not depend on its
         * caller for that: the invoice records the period it buys, so an
         * add-on that already runs to at least that date has had this
         * invoice applied. A provider that delivers the same notification
         * twice, which they all do, extends once.
         */
        if ($invoice->period_end && $addOn->ends_at
            && $addOn->ends_at->greaterThanOrEqualTo($invoice->period_end)) {
            return;
        }

        $this->extend($addOn);
    }

    private function extend(SubscriptionAddOn $addOn): void
    {
        // Lapsed already: a fresh period from today. Selling time that has
        // already passed would charge for capacity nobody could use.
        $from = $addOn->ends_at && $addOn->ends_at->isFuture()
            ? $addOn->ends_at
            : Carbon::now();

        $addOn->forceFill([
            'ends_at' => $addOn->periodEndFrom($from),
            // Paying a renewal on a cancelled add-on is a decision to keep
            // it, so it renews again.
            'status' => SubscriptionAddOn::STATUS_ACTIVE,
            'cancelled_at' => null,
        ])->save();

        ActivityLog::record(
            'updated',
            "Add-on renewed: {$addOn->quantity} x {$addOn->label()}, until {$addOn->ends_at->format('d M Y')}.",
            $addOn->subscription
        );
    }

    /**
     * Stops the next renewal, and nothing else.
     *
     * The paid period is preserved: `ends_at` is not moved, the capacity
     * keeps counting until that date, and no refund is computed, because
     * the period was bought whole and no proration exists in either
     * direction. The customer keeps what they paid for and is not billed
     * again.
     */
    public function cancel(SubscriptionAddOn $addOn, ?User $operator = null): void
    {
        if ($addOn->status === SubscriptionAddOn::STATUS_PENDING_PAYMENT) {
            // Never paid for, so there is nothing to preserve and nothing
            // to bill. Its invoice is left alone: voiding somebody else's
            // financial record is not this method's business.
            $addOn->forceFill([
                'status' => SubscriptionAddOn::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            return;
        }

        $addOn->forceFill([
            'status' => SubscriptionAddOn::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        ActivityLog::record(
            'updated',
            "Add-on cancelled: {$addOn->quantity} x {$addOn->label()}. "
            ."Capacity remains until {$addOn->ends_at?->format('d M Y')} and will not renew.",
            $addOn->subscription
        );
    }

    /**
     * How much counted capacity of one type a tenant currently holds from
     * add-ons. Reads `counted()`, so a cancelled-but-paid add-on is
     * included and a pending one is not.
     */
    public function countedQuantity(?int $tenantId, string $type): int
    {
        if ($tenantId === null) {
            return 0;
        }

        return (int) SubscriptionAddOn::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->counted()
            ->sum('quantity');
    }
}

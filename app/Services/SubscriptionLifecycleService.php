<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2.70.0 -- EVERY SUBSCRIPTION STATE TRANSITION, IN ONE PLACE.
 *
 * Before this class, the only thing that ever moved a subscription was
 * provisioning (which created it) and a Platform Admin editing the row by
 * hand. There was no renewal, no period extension, and no plan change --
 * the webhook could settle an invoice and had no idea what that invoice
 * was FOR.
 *
 * Three rules shape everything here.
 *
 * 1. THE INVOICE IS THE CONTRACT. A verified payment settles an invoice,
 *    and the invoice says what it buys (`purpose`, `target_package_id`,
 *    `target_billing_cycle`). Nothing is inferred from which foreign key
 *    happens to be null, and nothing a browser sends decides anything.
 *
 * 2. TIME IS ADDED, NEVER RESET. Extending a period is always
 *    `max(current end, now) + cycle`. A customer who renews a fortnight
 *    early keeps that fortnight; one who renews a month late does not
 *    silently pay for the month they could not use. Getting this wrong by
 *    writing `now + cycle` is the single most common renewal bug and it
 *    quietly steals from whichever party it rounds against.
 *
 * 3. NOTHING HERE DELETES ANYTHING. A subscription can lapse, be
 *    downgraded, be suspended and be revived, and the tenant, its users,
 *    its configuration and every operational record are untouched
 *    throughout. Subscription state governs ACCESS; it is not a lifecycle
 *    for the customer's system of record.
 */
class SubscriptionLifecycleService
{
    public function __construct(private readonly PricingService $pricing) {}

    /* ==================================================================
     * RENEWAL
     * ================================================================== */

    /**
     * Issue the invoice for this subscription's next period, if one is due
     * and none is already outstanding.
     *
     * Idempotent by query, not by flag: an unpaid renewal invoice already
     * covering the next period IS the record that one was issued, so a job
     * that runs twice — or a customer who asks to renew while an invoice is
     * waiting — reuses it rather than raising a second demand for the same
     * money.
     */
    public function issueRenewalInvoice(Subscription $subscription, bool $force = false): ?Invoice
    {
        if ($subscription->isLifetime() || $subscription->tenant_id === null) {
            return null;
        }

        // A suspended or cancelled subscription is not renewed -- not by
        // the nightly job, and not by the customer asking. Both statuses
        // are a platform operator's deliberate decision, and reviving one
        // is theirs to make; inviting payment for a subscription that
        // will not restore access would be taking money under a promise
        // IOMS is not going to keep.
        if ($subscription->isBlocked()) {
            return null;
        }

        if ($existing = $this->outstandingRenewalInvoice($subscription)) {
            return $existing;
        }

        if (! $force && ! $this->renewalIsDue($subscription)) {
            return null;
        }

        [$cycle, $package] = $this->nextPeriodPlan($subscription);
        [$start, $end] = $this->nextPeriodWindow($subscription, $cycle);

        $amount = $this->renewalAmount($subscription, $package, $cycle);

        return DB::transaction(function () use ($subscription, $package, $cycle, $start, $end, $amount) {
            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateNumber($subscription->tenant_id),
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'purpose' => Invoice::PURPOSE_RENEWAL,
                // A renewal that also carries a pending downgrade or cycle
                // change records what it is buying, so paying it applies
                // the change and nothing has to be remembered elsewhere.
                'target_package_id' => $package->id,
                'target_billing_cycle' => $cycle,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'amount' => $amount,
                'currency' => $subscription->agreedCurrency(),
                'status' => Invoice::STATUS_ISSUED,
                'due_date' => now()->addDays((int) config('saas.invoice_due_days', 14))->toDateString(),
            ]);

            ActivityLog::record(
                'created',
                "Renewal invoice {$invoice->invoice_number} issued for the period "
                ."{$start->toDateString()} to {$end->toDateString()}.",
                $subscription,
            );

            return $invoice;
        });
    }

    /** An unpaid, non-void invoice already covering the next period. */
    public function outstandingRenewalInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('purpose', [Invoice::PURPOSE_RENEWAL, Invoice::PURPOSE_PLAN_CHANGE])
            ->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE])
            ->latest('id')
            ->first();
    }

    /**
     * Due once the period end is inside the lead window — including when
     * it is already behind us, because a lapsed customer needs an invoice
     * to pay more urgently than anyone.
     */
    public function renewalIsDue(Subscription $subscription): bool
    {
        $days = $subscription->daysUntilPeriodEnd();

        return $days !== null && $days <= (int) config('saas.renewal_lead_days', 14);
    }

    /* ==================================================================
     * APPLYING A VERIFIED PAYMENT
     * ================================================================== */

    /**
     * Apply an invoice that a verified payment has settled.
     *
     * THE ONLY CALLER IS THE WEBHOOK. Nothing a browser does reaches this,
     * which is the property the whole payment design exists to protect.
     *
     * Idempotency is the caller's: PaymentWebhookController runs this
     * inside the same transaction as `markPaid()`, guarded on the invoice
     * not already being paid, so a replayed notification finds a settled
     * invoice and does nothing. This method additionally refuses an
     * invoice that is not attached to a subscription, so a malformed row
     * can never extend an unrelated tenant.
     */
    public function applyPaidInvoice(Invoice $invoice): void
    {
        // Explicit loads, not lazy ones: this runs in a webhook where
        // `Model::preventLazyLoading()` is active outside production, and
        // an N+1 guard throwing mid-settlement would leave a paid invoice
        // unapplied.
        $invoice->loadMissing(['subscription.tenant', 'subscription.package', 'targetPackage']);

        $subscription = $invoice->subscription;

        if (! $subscription) {
            return;
        }

        // The tenant boundary, restated at the write. The invoice was
        // located from a gateway reference, so this is the point where
        // "which customer does this money belong to" must be provably
        // consistent rather than assumed.
        if ($invoice->tenant_id !== null && $invoice->tenant_id !== $subscription->tenant_id) {
            throw new RuntimeException(
                "Invoice {$invoice->invoice_number} names tenant {$invoice->tenant_id} but its subscription "
                ."belongs to tenant {$subscription->tenant_id}. Refusing to apply."
            );
        }

        $package = $invoice->targetPackage ?? $subscription->package;
        $cycle = $invoice->target_billing_cycle ?: $subscription->billing_cycle;

        if ($invoice->purpose === Invoice::PURPOSE_PLAN_CHANGE) {
            $this->applyPlanChange($subscription, $package, $cycle, extendPeriod: false);

            return;
        }

        // A renewal. Extend first, then apply whatever plan the invoice
        // was raised for -- a downgrade or cycle switch rides along on the
        // renewal it was scheduled against.
        $wasReadOnly = $subscription->lifecycleState() === Subscription::LIFECYCLE_LAPSED;

        $this->extendPeriod($subscription, $cycle);
        $this->applyPlanChange($subscription, $package, $cycle, extendPeriod: false);

        $this->announceRenewal($subscription->fresh(['tenant', 'package']), $wasReadOnly);
    }

    /**
     * v2.78.0 -- "YOUR SUBSCRIPTION HAS BEEN RENEWED", AND ONLY WHEN IT HAS.
     *
     * Reached only from applyPaidInvoice(), whose two callers are the
     * signature-verified payment webhook and a Platform Admin recording a
     * bank transfer under their own audited identity. Nothing a browser
     * does can reach it, so this email cannot announce a payment that did
     * not happen.
     *
     * AFTER COMMIT. Both callers wrap settlement in a transaction; sending
     * inside it would mail a "renewed" message for a payment whose write
     * could still roll back. DB::afterCommit() runs immediately when there
     * is no transaction. A replayed webhook never gets here -- the caller
     * returns early for an invoice already marked paid -- so it cannot be
     * sent twice.
     *
     * Not sent to a suspended or cancelled subscription: the period
     * extends (ADR 033 §8) but access does not return, and an email saying
     * "renewed" would say otherwise.
     */
    private function announceRenewal(Subscription $subscription, bool $writesRestored): void
    {
        if ($subscription->isBlocked() || ! $subscription->tenant_id) {
            return;
        }

        \Illuminate\Support\Facades\DB::afterCommit(function () use ($subscription, $writesRestored) {
            $administrators = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\UserTenantScope::class)
                ->where('tenant_id', $subscription->tenant_id)
                ->where('is_active', true)
                ->where('role', \App\Models\User::ROLE_SUPER_ADMIN)
                ->get();

            foreach ($administrators as $user) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\SubscriptionLifecycleNotice(
                        $subscription,
                        \App\Mail\SubscriptionLifecycleNotice::EVENT_RENEWED,
                        route('subscription.billing'),
                        $writesRestored,
                    ));
                } catch (\Throwable $e) {
                    // A mail failure must never unwind a payment that was
                    // verified and applied. Logged, not thrown.
                    \Illuminate\Support\Facades\Log::error('Renewal confirmation email failed.', [
                        'to' => $user->email, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Move the period end forward by one cycle from whichever is later:
     * the current end, or now.
     *
     * Renewing early adds to the time already paid for. Renewing after a
     * lapse starts the new period today rather than back-dating it into a
     * gap the customer could not use.
     */
    public function extendPeriod(Subscription $subscription, ?string $cycle = null): void
    {
        $cycle = $cycle ?: $subscription->billing_cycle;

        $from = $subscription->ends_at && $subscription->ends_at->isFuture()
            ? $subscription->ends_at->copy()
            : now();

        /*
         * A PAYMENT BUYS TIME. IT DOES NOT LIFT A SUSPENSION.
         *
         * `status` is the deliberate axis: suspended and cancelled are
         * things a platform operator DECIDED, for abuse, a legal hold, or
         * an escalation that went past billing. Money arriving must not
         * overturn that decision -- otherwise a suspended customer
         * restores their own access by raising an invoice and paying it,
         * and the operator's control is only as strong as the customer's
         * willingness to spend.
         *
         * The period still extends and the money is still recorded, so
         * nothing is lost when an operator does lift the suspension: the
         * customer resumes with the time they paid for. `trial` promotes
         * to `active` because a paid trial IS a purchase, which is the
         * one status change a payment legitimately makes.
         */
        $wasBlocked = $subscription->isBlocked();

        $subscription->forceFill([
            'status' => $wasBlocked ? $subscription->status : Subscription::STATUS_ACTIVE,
            'billing_cycle' => $cycle,
            'ends_at' => $this->addCycle($from, $cycle),
            'starts_at' => $subscription->starts_at ?? now(),
            // Only cleared alongside a status that is actually leaving
            // cancellation -- never while the row stays cancelled.
            'cancelled_at' => $wasBlocked ? $subscription->cancelled_at : null,
            'renewal_reminded_at' => null,
        ])->save();

        ActivityLog::record(
            'renewed',
            "Subscription extended to {$subscription->ends_at->toDateString()} ({$cycle})."
            .($wasBlocked ? " Status remains {$subscription->status}; a platform operator must lift it." : ''),
            $subscription,
        );
    }

    /* ==================================================================
     * PLAN CHANGES
     * ================================================================== */

    /**
     * Is moving to this package an upgrade? Measured by what the customer
     * would pay for it, not by a hardcoded tier order -- the catalogue is
     * editable and a name tells you nothing about its price.
     */
    public function isUpgrade(Subscription $subscription, Package $target, string $cycle): bool
    {
        $current = (float) ($subscription->agreedAmountFor($cycle) ?? 0);

        return $this->pricing->amountFor($target, $cycle) > $current;
    }

    /**
     * Record what the customer asked for.
     *
     * UPGRADE — takes effect when paid, and is billed only for the part of
     * the current period that is left. Charging a full cycle for an upgrade
     * on day 25 of 30 would be indefensible, and making them wait until
     * renewal for capacity they need now would be worse.
     *
     * DOWNGRADE or CYCLE CHANGE — recorded as pending and applied at the
     * period boundary. The customer paid for the period they are in; and a
     * downgrade applied today could put a tenant below the seats or
     * operating units it is actively using, which is a data-integrity
     * problem dressed up as a billing one.
     *
     * @return Invoice|null the invoice to pay now, or null when the change was scheduled
     */
    public function requestPlanChange(Subscription $subscription, Package $target, string $cycle): ?Invoice
    {
        if ($subscription->tenant_id === null) {
            throw new RuntimeException('A subscription with no tenant cannot change plan.');
        }

        if ($this->isUpgrade($subscription, $target, $cycle)) {
            return $this->issueUpgradeInvoice($subscription, $target, $cycle);
        }

        $subscription->forceFill([
            'pending_package_id' => $target->id,
            'pending_billing_cycle' => $cycle,
            'pending_requested_at' => now(),
        ])->save();

        ActivityLog::record(
            'updated',
            "Plan change to {$target->name} ({$cycle}) scheduled for the end of the current period.",
            $subscription,
        );

        return null;
    }

    public function cancelPendingChange(Subscription $subscription): void
    {
        if ($subscription->pending_package_id === null && $subscription->pending_billing_cycle === null) {
            return;
        }

        $subscription->forceFill([
            'pending_package_id' => null,
            'pending_billing_cycle' => null,
            'pending_requested_at' => null,
        ])->save();

        ActivityLog::record('updated', 'Scheduled plan change cancelled.', $subscription);
    }

    /**
     * The prorated cost of moving to a richer plan for the remainder of the
     * current period: what the new plan costs for the days that are left,
     * minus what the customer already paid for those same days.
     *
     * Never negative. An "upgrade" that computes to a credit is billed at
     * zero rather than paying the customer -- IOMS issues no refunds from
     * a plan change, and pretending otherwise in an invoice would be a
     * commitment nothing here can honour.
     */
    public function upgradeProration(Subscription $subscription, Package $target, string $cycle): float
    {
        $end = $subscription->periodEndsAt();
        $start = $subscription->starts_at;

        $newFull = $this->pricing->amountFor($target, $cycle);

        // No usable period to prorate against (a lapsed or never-dated
        // subscription): the upgrade simply buys a fresh full cycle.
        if ($end === null || $end->isPast() || $start === null || $start->gte($end)) {
            return round($newFull, 2);
        }

        $totalDays = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        $remainingDays = max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false));
        $fraction = min(1.0, $remainingDays / $totalDays);

        $currentFull = (float) ($subscription->agreedAmountFor($cycle) ?? 0);

        return round(max(0, ($newFull - $currentFull) * $fraction), 2);
    }

    private function issueUpgradeInvoice(Subscription $subscription, Package $target, string $cycle): Invoice
    {
        $amount = $this->upgradeProration($subscription, $target, $cycle);
        $end = $subscription->periodEndsAt();

        return DB::transaction(function () use ($subscription, $target, $cycle, $amount, $end) {
            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateNumber($subscription->tenant_id),
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'purpose' => Invoice::PURPOSE_PLAN_CHANGE,
                'target_package_id' => $target->id,
                'target_billing_cycle' => $cycle,
                'period_start' => now()->toDateString(),
                'period_end' => $end?->toDateString(),
                'amount' => $amount,
                'currency' => $subscription->agreedCurrency(),
                'status' => Invoice::STATUS_ISSUED,
                'due_date' => now()->addDays((int) config('saas.invoice_due_days', 14))->toDateString(),
                'notes' => "Upgrade to {$target->name}, prorated for the remainder of the current period.",
            ]);

            ActivityLog::record(
                'created',
                "Upgrade invoice {$invoice->invoice_number} issued for {$target->name} ({$cycle}).",
                $subscription,
            );

            return $invoice;
        });
    }

    /**
     * Move the subscription onto a package and re-entitle the tenant.
     *
     * The grant sync here is a TRUE sync and can remove — unlike
     * `tenants:sync-grants`, which is deliberately additive because it is a
     * safety net for under-granted tenants and must never be able to take
     * capability away by accident. A deliberate downgrade is the one case
     * where removal is the correct outcome, so it lives here instead.
     */
    public function applyPlanChange(Subscription $subscription, ?Package $package, ?string $cycle, bool $extendPeriod = true): void
    {
        $subscription->loadMissing(['package', 'tenant']);

        $package = $package ?: $subscription->package;

        if (! $package) {
            return;
        }

        $cycle = $cycle ?: $subscription->billing_cycle;
        $changedPackage = (int) $subscription->package_id !== (int) $package->id;

        $subscription->forceFill([
            'package_id' => $package->id,
            'billing_cycle' => $cycle,
            // A plan change re-agrees the price. Renewing on the SAME plan
            // does not: that is what keeps a customer on the price they
            // bought at when the catalogue moves underneath them.
            'agreed_price_monthly' => $changedPackage ? $package->price_monthly : $subscription->agreed_price_monthly,
            'agreed_price_yearly' => $changedPackage ? $package->price_yearly : $subscription->agreed_price_yearly,
            'agreed_currency' => $changedPackage ? $package->currency : $subscription->agreed_currency,
            'pending_package_id' => null,
            'pending_billing_cycle' => null,
            'pending_requested_at' => null,
        ])->save();

        if ($extendPeriod) {
            $this->extendPeriod($subscription, $cycle);
        }

        if ($changedPackage && $subscription->tenant) {
            $this->syncGrantsToPackage($subscription->tenant, $package);

            ActivityLog::record(
                'updated',
                "Subscription moved to {$package->name} ({$cycle}); entitlements re-synchronised.",
                $subscription,
            );
        }
    }

    /**
     * Set a tenant's workspace and module grants to exactly what a package
     * includes. Removes as well as adds, which is why it is reachable only
     * from a deliberate plan change.
     */
    public function syncGrantsToPackage(Tenant $tenant, Package $package): void
    {
        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        $tenant->modules()->sync(
            Module::whereIn('key', $package->defaultModuleKeys())->pluck('id')
        );
    }

    /* ==================================================================
     * Period arithmetic
     * ================================================================== */

    /** The plan and cycle the NEXT period should be billed at, honouring any scheduled change. */
    public function nextPeriodPlan(Subscription $subscription): array
    {
        $subscription->loadMissing(['package', 'pendingPackage']);

        $cycle = $subscription->pending_billing_cycle ?: $subscription->billing_cycle;
        $package = $subscription->pendingPackage ?: $subscription->package;

        return [$cycle ?: Subscription::CYCLE_MONTHLY, $package];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function nextPeriodWindow(Subscription $subscription, ?string $cycle = null): array
    {
        $cycle = $cycle ?: $subscription->billing_cycle;

        $start = $subscription->ends_at && $subscription->ends_at->isFuture()
            ? $subscription->ends_at->copy()
            : now();

        return [$start, $this->addCycle($start->copy(), $cycle)];
    }

    /**
     * What a renewal costs: the price this customer agreed to, not
     * whatever the catalogue says today — unless the renewal also carries
     * a plan change, which re-agrees the price by definition.
     */
    public function renewalAmount(Subscription $subscription, ?Package $package, string $cycle): float
    {
        $movingPlan = $package && (int) $package->id !== (int) $subscription->package_id;

        if ($movingPlan) {
            return $this->pricing->amountFor($package, $cycle);
        }

        return (float) ($subscription->agreedAmountFor($cycle)
            ?? ($package ? $this->pricing->amountFor($package, $cycle) : 0));
    }

    private function addCycle(Carbon $from, ?string $cycle): Carbon
    {
        return $cycle === Subscription::CYCLE_MONTHLY ? $from->addMonth() : $from->addYear();
    }
}

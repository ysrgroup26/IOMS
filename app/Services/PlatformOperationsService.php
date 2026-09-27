<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * v2.79.0 -- "HOW IS THE PLATFORM OPERATING?", ANSWERED FROM REAL STATE.
 *
 * The Platform Dashboard counted tenants and subscriptions by their STORED
 * status, which cannot answer the question an operator actually has. A
 * subscription whose period ended six weeks ago still stores `active`,
 * because where a subscription sits in TIME is derived on every read
 * (ADR 033 §1). So "expiring soon", "grace" and "lapsed" -- the three
 * states worth acting on -- were invisible on the one screen meant to show
 * them.
 *
 * This service derives them the same way every other surface does, through
 * `Subscription::lifecycleState()`, so the operations console and the
 * customer's own Billing page can never disagree.
 *
 * READ-ONLY. It computes and returns; it writes nothing, changes no state
 * and sends nothing. Every figure is a count or a sum of records that
 * already exist.
 *
 * DELIBERATELY NOT PROVIDER-SPECIFIC. Payment activity is read from
 * `invoices` and `payment_transactions`, which is where every provider's
 * verified result is normalised to (ADR 033 §5). Adding iPaymu beside
 * Midtrans changes nothing here.
 */
class PlatformOperationsService
{
    /** Subscriptions grouped by the lifecycle state they are actually in today. */
    public function subscriptionHealth(): array
    {
        $leadDays = (int) config('saas.renewal_lead_days', 14);

        $counts = [
            'active' => 0,
            'expiring' => 0,
            'grace' => 0,
            'lapsed' => 0,
            'blocked' => 0,
            'lifetime' => 0,
        ];

        // v2.80.0: how the book is made up, which the lifecycle counts
        // above cannot show. A complimentary pilot in perfect health and a
        // paying customer in perfect health are both `active`, and an
        // operator asking "how many organizations actually pay us?" needs
        // them apart.
        $modes = [
            Subscription::BILLING_MODE_PAID => 0,
            Subscription::BILLING_MODE_MANUAL => 0,
            Subscription::BILLING_MODE_COMPLIMENTARY => 0,
        ];

        $subscriptions = Subscription::query()
            ->withoutGlobalScopes()
            ->whereNotNull('tenant_id')
            // `is_demo` is SELECTED, not assumed: a constrained eager load
            // that omits a column makes the method reading it silently
            // return false -- the exact trap docs/CONVENTIONS.md records
            // from the v2.72.0 roleLabel() defect.
            ->with('tenant:id,name,status,is_demo')
            ->get();

        $attention = [];

        foreach ($subscriptions as $subscription) {
            // A demo tenant is never invoiced and never lapses (v2.70.0),
            // so counting it as operational work would be noise.
            if ($subscription->tenant?->isDemo()) {
                continue;
            }

            $modes[$subscription->billingMode()]++;

            if ($subscription->isLifetime()) {
                $counts['lifetime']++;

                continue;
            }

            $state = $subscription->lifecycleState();
            $daysLeft = $subscription->daysUntilPeriodEnd();

            match ($state) {
                Subscription::LIFECYCLE_GRACE => $counts['grace']++,
                Subscription::LIFECYCLE_LAPSED => $counts['lapsed']++,
                Subscription::LIFECYCLE_SUSPENDED, Subscription::LIFECYCLE_CANCELLED => $counts['blocked']++,
                default => is_int($daysLeft) && $daysLeft >= 0 && $daysLeft <= $leadDays
                    ? $counts['expiring']++
                    : $counts['active']++,
            };

            // The working list: everything an operator might have to chase,
            // soonest first. Healthy subscriptions are not in it.
            // A complimentary subscription is never chased for money, so it
            // is not operational work even when its dates look overdue.
            if ($subscription->isComplimentary()) {
                continue;
            }

            if (in_array($state, [Subscription::LIFECYCLE_GRACE, Subscription::LIFECYCLE_LAPSED], true)
                || (is_int($daysLeft) && $daysLeft >= 0 && $daysLeft <= $leadDays)) {
                $attention[] = [
                    'tenant_id' => $subscription->tenant_id,
                    'tenant' => $subscription->tenant?->name,
                    'state' => $state,
                    'billing_mode' => $subscription->billingMode(),
                    'days_left' => $daysLeft,
                    'period_ends_at' => $subscription->periodEndsAt()?->toDateString(),
                    'grace_ends_at' => $subscription->graceEndsAt()?->toDateString(),
                ];
            }
        }

        usort($attention, fn ($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));

        return [
            'counts' => $counts,
            'billing_modes' => $modes,
            'attention' => array_slice($attention, 0, 8),
            'lead_days' => $leadDays,
            'grace_days' => Subscription::graceDays(),
        ];
    }

    /** Money in, and money still owed, across every tenant and every provider. */
    public function paymentActivity(int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $paid = Invoice::withoutGlobalScopes()
            ->where('status', Invoice::STATUS_PAID)
            ->where('payment_date', '>=', $since->toDateString());

        $outstanding = Invoice::withoutGlobalScopes()
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE]);

        return [
            'window_days' => $days,
            'paid_count' => (clone $paid)->count(),
            'paid_amount' => (float) (clone $paid)->sum('amount'),
            'outstanding_count' => (clone $outstanding)->count(),
            'outstanding_amount' => (float) (clone $outstanding)->sum('amount'),
            'overdue_count' => (clone $outstanding)->where('due_date', '<', Carbon::now()->toDateString())->count(),
            // Attempts the provider reported as failed. Not a failure of
            // IOMS, but the thing an operator is asked about.
            'failed_attempts' => PaymentTransaction::where('status', 'failed')
                ->where('updated_at', '>=', $since)
                ->count(),
            'by_provider' => PaymentTransaction::where('status', 'paid')
                ->where('updated_at', '>=', $since)
                ->selectRaw('gateway, COUNT(*) as attempts')
                ->groupBy('gateway')
                ->pluck('attempts', 'gateway')
                ->all(),
        ];
    }

    /** Onboarding still in flight: orders raised but not yet paid or provisioned. */
    public function onboardingPipeline(): array
    {
        return [
            'tenants_total' => Tenant::count(),
            'tenants_suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
        ];
    }
}

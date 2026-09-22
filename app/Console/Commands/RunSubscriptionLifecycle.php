<?php

namespace App\Console\Commands;

use App\Mail\InvoiceIssued;
use App\Mail\SubscriptionLifecycleNotice;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Scopes\UserTenantScope;
use App\Services\NotificationService;
use App\Services\PricingService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * v2.70.0 -- THE ONLY THING IN IOMS THAT WATCHES THE CALENDAR.
 *
 * Before this release nothing did. `ends_at` was written once at
 * provisioning and never read by any job: no renewal invoice was ever
 * raised, no customer was ever told their period was ending, and a
 * subscription that ran out simply sat there with a date in the past.
 *
 * This command does three things and nothing else:
 *
 *  1. ISSUES the renewal invoice once the period end is inside the lead
 *     window, and emails it.
 *  2. REMINDS a customer whose period has ended and whose grace window is
 *     running out, in the product and by email.
 *  3. APPLIES a plan change that was scheduled for the period boundary.
 *
 * WHAT IT DOES NOT DO, deliberately:
 *
 *  - It never changes a subscription's ACCESS. Grace and lapse are
 *    derived from the dates on every read (Subscription::lifecycleState()),
 *    so a customer's access is correct whether this command ran last
 *    night, last month, or has never run at all. A cron that stops is a
 *    monitoring problem here, not a billing incident -- which is the
 *    entire reason the time states are derived rather than stored.
 *  - It never takes money and never marks anything paid. It raises the
 *    invoice; a signature-verified webhook settles it.
 *
 * Safe to run repeatedly. Issuing is idempotent by query (an unpaid
 * renewal invoice for the next period IS the record that one was issued),
 * reminders are guarded by `renewal_reminded_at`, and applying a pending
 * change clears the pending fields in the same write.
 */
class RunSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:lifecycle
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Issue renewal invoices, send renewal reminders, and apply scheduled plan changes.';

    public function handle(SubscriptionLifecycleService $lifecycle, PricingService $pricing, NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $subscriptions = Subscription::with(['package', 'pendingPackage', 'tenant'])
            ->whereNotNull('tenant_id')
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIAL])
            ->get();

        $issued = $applied = $reminded = $emailed = 0;

        foreach ($subscriptions as $subscription) {
            $tenant = $subscription->tenant;

            if (! $tenant) {
                continue;
            }

            // The IOMS Sandbox is a demonstration, not a customer. Invoicing
            // it would produce real billing documents for an account nobody
            // pays for.
            if ($tenant->isDemo()) {
                continue;
            }

            if ($subscription->isLifetime()) {
                continue;
            }

            try {
                // A scheduled downgrade or cycle change whose moment has
                // arrived. Applied FIRST, so a renewal invoice raised in the
                // same run bills the plan the customer will actually be on.
                if ($subscription->isExpired() && $subscription->pending_package_id) {
                    if (! $dryRun) {
                        $lifecycle->applyPlanChange(
                            $subscription,
                            $subscription->pendingPackage,
                            $subscription->pending_billing_cycle,
                            extendPeriod: false,
                        );
                    }

                    $this->line("Applied scheduled plan change for \"{$tenant->name}\".");
                    $applied++;
                }

                if ($lifecycle->renewalIsDue($subscription)) {
                    $before = $lifecycle->outstandingRenewalInvoice($subscription);

                    $invoice = $dryRun ? null : $lifecycle->issueRenewalInvoice($subscription);

                    if ($dryRun && ! $before) {
                        $this->line("Would issue a renewal invoice for \"{$tenant->name}\".");
                        $issued++;
                    } elseif ($invoice && ! $before) {
                        $this->line("Issued {$invoice->invoice_number} for \"{$tenant->name}\".");
                        $this->announce($invoice, $subscription, $pricing, $notifications);
                        $issued++;
                    }
                }

                /*
                 * v2.78.0 -- entering grace, and entering lapse, are each
                 * emailed ONCE per period. The daily in-app reminder below
                 * keeps nudging inside the product; the inbox gets one
                 * message per change of state, which is what makes it worth
                 * reading.
                 */
                if ($event = $this->lifecycleEmailDue($subscription)) {
                    if (! $dryRun) {
                        $this->emailLifecycle($subscription, $event);
                        $subscription->forceFill(['lifecycle_notified' => $this->noticeKey($subscription, $event)])->save();
                    }
                    $this->line("Emailed \"{$tenant->name}\" ({$event}).");
                    $emailed++;
                }

                if ($this->shouldRemind($subscription)) {
                    if (! $dryRun) {
                        $this->remind($subscription, $notifications);
                        $subscription->forceFill(['renewal_reminded_at' => now()])->save();
                    }

                    $this->line("Reminded \"{$tenant->name}\" ({$subscription->lifecycleState()}).");
                    $reminded++;
                }
            } catch (Throwable $e) {
                // One tenant's failure must never stop the rest of the run.
                Log::error('Subscription lifecycle run failed for a tenant.', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("Tenant \"{$tenant->name}\": {$e->getMessage()}");
            }
        }

        $this->info("Invoices issued: {$issued}. Plan changes applied: {$applied}. Reminders sent: {$reminded}. Lifecycle emails: {$emailed}.");

        if ($dryRun) {
            $this->comment('Dry run -- nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Remind once a day at most, and only while it is still useful: from
     * the moment the period has ended until the grace window closes.
     *
     * Once a subscription has lapsed the product itself says so on every
     * page and every write, so a daily email after that point is noise
     * rather than news.
     */
    /**
     * v2.78.0 -- which lifecycle email, if any, this subscription is owed.
     *
     * ONCE PER STATE PER PERIOD. The key is "<state>:<period end>", so a
     * payment -- which moves the period end -- re-arms the notices for the
     * next period without anything having to reset them.
     *
     * A LAPSE THAT IS OLD NEWS IS NOT ANNOUNCED. The first run after this
     * shipped would otherwise email every organization that lapsed months
     * ago. Only a lapse that began inside the last week is emailed; the
     * banner and the in-app notice cover the rest.
     */
    private function lifecycleEmailDue(Subscription $subscription): ?string
    {
        $state = $subscription->lifecycleState();

        $event = match ($state) {
            Subscription::LIFECYCLE_GRACE => SubscriptionLifecycleNotice::EVENT_GRACE,
            Subscription::LIFECYCLE_LAPSED => SubscriptionLifecycleNotice::EVENT_LAPSED,
            default => null,
        };

        if ($event === null) {
            return null;
        }

        if ($event === SubscriptionLifecycleNotice::EVENT_LAPSED
            && $subscription->graceEndsAt()?->lt(now()->subDays(7))) {
            return null;
        }

        return $subscription->lifecycle_notified === $this->noticeKey($subscription, $event) ? null : $event;
    }

    private function noticeKey(Subscription $subscription, string $event): string
    {
        return $event.':'.$subscription->periodEndsAt()?->toDateString();
    }

    private function emailLifecycle(Subscription $subscription, string $event): void
    {
        foreach ($this->administrators($subscription) as $user) {
            try {
                Mail::to($user->email)->send(
                    new SubscriptionLifecycleNotice($subscription, $event, route('subscription.billing'))
                );
            } catch (Throwable $e) {
                Log::error('Subscription lifecycle email failed.', ['to' => $user->email, 'event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }

    private function shouldRemind(Subscription $subscription): bool
    {
        if ($subscription->lifecycleState() !== Subscription::LIFECYCLE_GRACE) {
            return false;
        }

        return $subscription->renewal_reminded_at === null
            || $subscription->renewal_reminded_at->lt(now()->subDay());
    }

    /** Tell the administrators a renewal invoice exists and is payable. */
    private function announce(Invoice $invoice, Subscription $subscription, PricingService $pricing, NotificationService $notifications): void
    {
        $amount = $pricing->format((float) $invoice->amount, $invoice->currency);
        $planName = $invoice->targetPackage?->name ?? $subscription->package?->name ?? 'IOMS';

        foreach ($this->administrators($subscription) as $user) {
            $notifications->notify(
                $user,
                Notification::CATEGORY_INFORMATION,
                "Renewal invoice {$invoice->invoice_number}",
                "Tagihan perpanjangan sebesar {$amount} telah diterbitkan untuk masa aktif berikutnya.",
                route('subscription.billing'),
                $invoice,
            );

            $this->mail($user->email, new InvoiceIssued(
                $invoice,
                $planName,
                $amount,
                route('subscription.pay', $invoice),
                route('subscription.invoices.pdf', $invoice),
            ));
        }
    }

    private function remind(Subscription $subscription, NotificationService $notifications): void
    {
        $graceEnd = $subscription->graceEndsAt()?->toDateString();

        foreach ($this->administrators($subscription) as $user) {
            $notifications->notify(
                $user,
                Notification::CATEGORY_WARNING,
                'Subscription renewal due',
                "Masa aktif langganan telah berakhir. Akses penuh masih tersedia hingga {$graceEnd}. "
                .'Setelah itu data Anda tetap dapat dibuka, namun pencatatan data baru dijeda hingga pembayaran diterima.',
                route('subscription.billing'),
                $subscription,
            );
        }
    }

    /**
     * The accounts that can actually act on a billing notice.
     *
     * UserTenantScope is bypassed BY NAME, not by accident: a console
     * command has no request and therefore no resolved tenant, so the
     * scope is inert here and the tenant filter has to be explicit. This
     * is the same pattern provisioning and the platform surface already
     * use, and the `where('tenant_id')` below is what keeps one
     * organization's notice out of another's inbox.
     */
    private function administrators(Subscription $subscription)
    {
        return User::withoutGlobalScope(UserTenantScope::class)
            ->where('tenant_id', $subscription->tenant_id)
            ->where('is_active', true)
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->get();
    }

    /** Mail is best-effort: a dead SMTP server must not stop the billing run. */
    private function mail(string $to, InvoiceIssued $mailable): void
    {
        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::error('Renewal invoice email failed.', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}

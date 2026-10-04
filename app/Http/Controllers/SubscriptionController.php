<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGatewayInterface;
use App\Mail\InvoiceIssued;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\InvoiceDocumentService;
use App\Services\PdfGeneratorService;
use App\Services\PricingService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * v2.70.0 -- THE CUSTOMER'S OWN BILLING SURFACE.
 *
 * Everything a paying organization does with its own subscription: read
 * where it stands, renew it, pay an invoice, and change plan. Split out
 * of SettingsController, where the read-only half already lived, because
 * this is now a real area with its own lifecycle rather than three
 * display methods. Every route name is unchanged, so nothing that links
 * here had to move.
 *
 * TWO RULES GOVERN EVERY ACTION BELOW.
 *
 * 1. NOTHING HERE ACTIVATES OR EXTENDS ANYTHING. These endpoints issue
 *    invoices and open payment sessions. A subscription's period moves
 *    in exactly one place -- PaymentWebhookController, from a payload the
 *    provider signed and this server verified. A customer who reaches the
 *    "finish" URL without paying gets a page that says the payment is
 *    still pending, because that is the truth.
 *
 * 2. THE AMOUNT IS ALWAYS RECOMPUTED SERVER-SIDE. Nothing the browser
 *    sends influences what is charged. The client picks a plan and a
 *    cycle by id; the price attached to that choice comes from the
 *    packages table and the customer's own agreed pricing.
 *
 * Tenant ownership is asserted on every record this controller touches.
 * `invoices` and `subscriptions` carry no company_id and so inherit
 * nothing from Company's global scopes -- route-model binding would
 * happily hand over another organization's invoice on a guessed id, and
 * the explicit tenant comparison is the only thing preventing it.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    /* ==================================================================
     * READING
     * ================================================================== */

    /**
     * v2.51.0 -- the tenant's own billing area: what they are on, what
     * their plan actually grants against what they are using, and their
     * real invoice and payment history.
     *
     * v2.70.0 -- and now what happens next. The page previously promised
     * that "an invoice is issued at the end of each billing period and
     * your subscription continues once it is paid" while no code path
     * issued one. It states the derived lifecycle instead, and offers the
     * actions that actually exist.
     *
     * Restricted to the Super Admin: seat usage, invoices and payment
     * references are commercial data, unlike the plan CATALOGUE that
     * plans() deliberately shows to everyone.
     */
    public function billing(Request $request): Response
    {
        abort_unless($request->user()->canManageSystemSettings(), 403);

        $tenantId = app(CurrentTenant::class)->id();
        abort_if($tenantId === null, 404);

        $subscription = Subscription::with(['package', 'pendingPackage'])
            ->where('tenant_id', $tenantId)
            ->latest()
            ->first();

        $package = $subscription?->package;

        // Real usage, counted now -- never a stored figure that could drift
        // from the seat limit it is being compared against.
        // v2.82.0: ACTIVE accounts only, and through the entitlement service
        // rather than a second query -- what this page SHOWS and what the
        // server ENFORCES must be one count, or a customer is told they have
        // room they do not have.
        $userCount = app(EntitlementService::class)->usersUsedCount($request->user()->tenant);
        $ptwUserCount = User::where('tenant_id', $tenantId)->where('ptw_access', true)->count();
        $companyCount = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $invoices = Invoice::where('tenant_id', $tenantId)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'purpose' => $invoice->purpose,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'due_date' => $invoice->due_date,
                'payment_date' => $invoice->payment_date,
                'payment_method' => $invoice->payment_method,
                'payment_reference' => $invoice->payment_reference,
                'period_start' => $invoice->period_start,
                'period_end' => $invoice->period_end,
                'created_at' => $invoice->created_at,
                'amount_formatted' => $this->pricing->format((float) $invoice->amount, $invoice->currency),
                'is_payable' => $invoice->isPayable(),
                'is_overdue' => $invoice->isOverdue(),
            ]);

        $outstanding = $subscription ? $this->lifecycle->outstandingRenewalInvoice($subscription) : null;

        return Inertia::render('Settings/Billing', [
            'subscription' => $subscription ? [
                // v2.80.0: every lifecycle fact comes from the ONE snapshot
                // Master Admin also renders. Do not add a lifecycle key
                // below it -- add it to Subscription::stateSnapshot().
                ...$subscription->stateSnapshot(),
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'cancelled_at' => $subscription->cancelled_at,
                // v2.70.0: the DERIVED lifecycle, which is what the page
                // actually needs to decide what to say. `status` alone
                // never told a customer whether their period had ended.
                'package_id' => $package?->id,
                // v2.60.0: the price THIS customer agreed to, not whatever
                // the public catalogue says today. A customer who bought
                // before a price change must keep seeing what they pay.
                'price' => ($amount = $subscription->agreedAmountFor()) !== null
                    ? $this->pricing->format($amount, $subscription->agreedCurrency())
                    : null,
                // True when their agreed price no longer matches the
                // catalogue, so the page can say so rather than leaving a
                // customer to notice the discrepancy themselves.
                'is_legacy_pricing' => $subscription->isOnLegacyPricing(),
                // The published price of the same plan today. Sent only when
                // it actually differs, so the page has something concrete to
                // compare against instead of an unexplained number.
                'catalogue_price' => $subscription->isOnLegacyPricing() && $package
                    ? $this->pricing->format((float) $this->pricing->amountFor($package, $subscription->billing_cycle), $package->currency)
                    : null,
                // A downgrade or cycle change the customer already asked
                // for, waiting for the period they paid for to run out.
                'pending_change' => $subscription->pending_package_id || $subscription->pending_billing_cycle ? [
                    'plan_name' => $subscription->pendingPackage?->name ?? $package?->name,
                    'billing_cycle' => $subscription->pending_billing_cycle ?? $subscription->billing_cycle,
                    'effective_at' => $subscription->periodEndsAt()?->toDateString(),
                ] : null,
            ] : null,
            // The invoice standing between this customer and another
            // period. Null when nothing is owed.
            'outstandingInvoice' => $outstanding ? [
                'id' => $outstanding->id,
                'invoice_number' => $outstanding->invoice_number,
                'purpose' => $outstanding->purpose,
                'amount_formatted' => $this->pricing->format((float) $outstanding->amount, $outstanding->currency),
                'due_date' => $outstanding->due_date?->toDateString(),
                'period_start' => $outstanding->period_start?->toDateString(),
                'period_end' => $outstanding->period_end?->toDateString(),
                'is_overdue' => $outstanding->isOverdue(),
            ] : null,
            'entitlements' => [
                // v2.82.0 -- capacity is an ALLOWANCE plus purchased extras,
                // so the page states all four numbers rather than a bare
                // "used of limit": a customer at 25/25 has to be able to see
                // that 25 is what the plan carries and not where the product
                // stops. `used` counts ACTIVE login accounts only.
                'users' => [
                    'used' => $userCount,
                    'limit' => $subscription?->seatLimit(),
                    'included' => $subscription?->includedUsers(),
                    'additional' => $subscription?->additionalUsers() ?? 0,
                    'additional_price' => (float) config('saas.additional_user_price', 0),
                    'additional_price_formatted' => $this->pricing->format((float) config('saas.additional_user_price', 0)),
                    'additional_charge_formatted' => $subscription
                        ? $this->pricing->format($subscription->additionalUserCharge())
                        : null,
                ],
                // Shown for information -- how many accounts hold PTW Access
                // -- with no limit, because it is not a purchased capacity.
                'ptw_users' => ['used' => $ptwUserCount, 'limit' => null],
                // Capacity is measured in OPERATING UNITS (v2.54.0) -- the
                // same `max_companies` number, called what it actually is.
                'operating_units' => ['used' => $companyCount, 'limit' => $package?->max_companies],
            ],
            'invoices' => $invoices,
            // Whether this deployment can actually take a card at all. When
            // false the page offers a bank transfer against the invoice
            // instead of a button that would fail.
            'onlinePaymentEnabled' => $this->paymentConfigured(),
            'graceDays' => Subscription::graceDays(),
            'billingEmail' => config('ioms.emails.billing'),
        ]);
    }

    /**
     * v2.14.0. The plan comparison, readable by every authenticated tenant
     * user -- knowing what plans exist and what one's own organization is
     * on is not privileged information.
     *
     * v2.70.0: it now also says whether the person looking may act on it,
     * so the page can offer a real plan change to an administrator and a
     * plain comparison to everyone else, rather than showing a button that
     * 403s.
     */
    public function plans(Request $request): Response
    {
        $tenant = $request->user()->tenant;
        $subscription = $tenant?->subscription;
        $currentPackage = $subscription?->package;

        return Inertia::render('Subscription/Plans', [
            'plans' => $this->pricing->publicPlans(),
            'currentPlan' => $currentPackage ? $this->pricing->summarize($currentPackage) : null,
            'currentPlanId' => $currentPackage?->id,
            'currentCycle' => $subscription?->billing_cycle,
            'canManageBilling' => (bool) $request->user()->canManageSystemSettings(),
            // A custom-priced plan has no figure to invoice, so its CTA is a
            // conversation rather than a button.
            'salesEmail' => config('ioms.emails.hello'),
            'changePreview' => $this->changePreview($request, $subscription),
        ]);
    }

    /**
     * v2.78.1 -- WHAT CONFIRMING A PLAN CHANGE WILL ACTUALLY DO.
     *
     * The confirmation dialog used to explain both outcomes ("if this is an
     * upgrade ... if this is a downgrade ...") because, as its own comment
     * said, only the server holds the prices that decide it. So the server
     * now decides it, per plan and per cycle, using the SAME two calls
     * requestPlanChange() uses -- isUpgrade() and upgradeProration() -- so
     * the preview and the outcome cannot disagree.
     *
     *   upgrade    → the exact prorated amount, invoiced now, applied on
     *                verified payment
     *   scheduled  → the date the change takes effect; nothing billed today
     *
     * Read-only: nothing is issued, stored or scheduled by computing this.
     * Custom-priced plans are omitted -- they have no figure to preview and
     * their action is a conversation, not a button.
     *
     * @return array<int, array<string, array>> package_id → cycle → preview
     */
    private function changePreview(Request $request, ?Subscription $subscription): array
    {
        if (! $subscription || $subscription->isLifetime() || ! $request->user()->canManageSystemSettings()) {
            return [];
        }

        $preview = [];
        $periodEnd = $subscription->periodEndsAt()?->toDateString();

        foreach (Package::query()->active()->public()->get() as $package) {
            foreach ([Subscription::CYCLE_MONTHLY, Subscription::CYCLE_YEARLY] as $cycle) {
                $price = $cycle === Subscription::CYCLE_MONTHLY ? $package->price_monthly : $package->price_yearly;

                if ($price === null) {
                    continue;
                }

                if ((int) $package->id === (int) $subscription->package_id && $cycle === $subscription->billing_cycle) {
                    $preview[$package->id][$cycle] = ['kind' => 'current'];

                    continue;
                }

                // Decided in the CURRENT cycle, exactly as requestPlanChange()
                // decides it: the plan upgrade is prorated now, and a cycle
                // switch requested with it takes effect at the period end.
                $currentCycle = $subscription->billing_cycle ?: Subscription::CYCLE_MONTHLY;

                if ($this->lifecycle->isUpgrade($subscription, $package, $currentCycle)) {
                    $amount = $this->lifecycle->upgradeProration($subscription, $package, $currentCycle);

                    $preview[$package->id][$cycle] = [
                        'kind' => 'upgrade',
                        'amount_formatted' => $this->pricing->format($amount, $package->currency ?: 'IDR'),
                        'period_ends_at' => $periodEnd,
                        // The cycle switch, when there is one, and when.
                        'cycle_change_at' => $cycle !== $currentCycle ? $periodEnd : null,
                        'cycle' => $cycle,
                    ];

                    continue;
                }

                $preview[$package->id][$cycle] = [
                    'kind' => 'scheduled',
                    'effective_at' => $periodEnd,
                ];
            }
        }

        return $preview;
    }

    /**
     * v2.55.0 -- the invoice as a downloadable PDF.
     *
     * Built on the EXISTING document architecture -- the same
     * `pdf.partials.{styles,letterhead,footer}` every operational document
     * uses -- with `InvoiceDocumentService` supplying the ISSUER identity
     * rather than DocumentEngine's tenant identity.
     *
     * OWNERSHIP IS CHECKED ON THE INVOICE ITSELF. `invoices` carries no
     * company_id, so it inherits nothing from Company's global scopes:
     * route-model binding would happily hand over another organization's
     * invoice on a guessed id. The tenant_id comparison here is the only
     * thing standing between the two, so it is explicit and it is first.
     */
    public function invoicePdf(Request $request, Invoice $invoice, PdfGeneratorService $pdf, InvoiceDocumentService $documents): \Illuminate\Http\Response
    {
        abort_unless($request->user()->canManageSystemSettings(), 403);
        $this->assertOwned($request, $invoice);

        $invoice->load('subscription.package', 'registration.package', 'tenant');

        return $pdf->streamInline(
            'pdf.invoice',
            $documents->viewData($invoice),
            $invoice->invoice_number.'.pdf'
        );
    }

    /* ==================================================================
     * ACTING
     * ================================================================== */

    /**
     * Renew now. Issues the invoice for the next period if one is not
     * already outstanding, then sends the customer to pay it.
     *
     * `force: true` because this is a deliberate request from the account
     * holder -- somebody who wants to settle three weeks ahead of the lead
     * window should be allowed to. It still cannot produce a second
     * invoice: issueRenewalInvoice() reuses an unpaid one.
     */
    public function renew(Request $request): RedirectResponse
    {
        $subscription = $this->currentSubscription($request);

        if ($subscription->isLifetime()) {
            return back()->with('info', 'Lisensi organisasi Anda berlaku selamanya, sehingga tidak ada masa aktif yang perlu diperpanjang.');
        }

        // A suspended or cancelled subscription is not revived by paying.
        // Say so before taking the customer to a checkout, rather than
        // after -- the decision to reinstate is the platform operator's,
        // and an invoice here would be selling something IOMS will not
        // deliver until they make it.
        if ($subscription->isBlocked()) {
            return back()->with('info', 'Langganan organisasi Anda sedang dihentikan, sehingga belum dapat diperpanjang sendiri. Hubungi '.config('ioms.emails.billing').' untuk mengaktifkan kembali.');
        }

        $invoice = $this->lifecycle->issueRenewalInvoice($subscription, force: true);

        if (! $invoice) {
            return back()->with('info', 'Belum ada tagihan perpanjangan yang perlu diterbitkan untuk saat ini.');
        }

        $this->sendInvoiceEmail($request, $invoice);

        return redirect()->route('subscription.pay', $invoice);
    }

    /**
     * v2.82.0 -- BUY OR RELEASE ADDITIONAL ACTIVE-USER CAPACITY.
     *
     * The included allowance belongs to the plan; this is what the customer
     * holds on top of it, and it is a RECURRING charge -- Rp50.000 per
     * additional active user per month, on every plan, for as long as it is
     * held. It is not a one-off purchase, so nothing is invoiced at the
     * moment of the change: the new quantity is priced into the next
     * renewal invoice, which is the same document, the same lifecycle and
     * the same verified-payment path everything else already uses.
     *
     * WHY NOT AN IMMEDIATE PRORATED INVOICE. A plan upgrade issues one,
     * because it changes what the product IS mid-period. Capacity does not:
     * the customer is buying room in a period they have already paid for,
     * and charging for a partial month of a Rp50.000 line would produce
     * invoices for a few thousand rupiah that cost more to settle than they
     * collect. The quantity is therefore live immediately and billed from
     * the next period -- stated plainly on the page, not implied.
     *
     * RELEASING IS FREE AND IMMEDIATE, but cannot strand accounts: capacity
     * may never be reduced below the accounts currently ACTIVE. The customer
     * deactivates accounts first, which frees the slots, and is the same
     * rule a downgrade already follows. Nothing here ever deactivates a user
     * on the customer's behalf.
     *
     * THE QUANTITY IS THE ONLY THING THE BROWSER SENDS. Every price is
     * recomputed here from configuration and the tenant's own subscription,
     * so a crafted form can change how much capacity is asked for and never
     * what it costs.
     */
    public function updateAdditionalUsers(Request $request): RedirectResponse
    {
        $subscription = $this->currentSubscription($request);

        // Capacity is a commercial change, so it is the same authority that
        // manages the plan -- not every administrator, and never an
        // ordinary member of the organization.
        abort_unless($request->user()->canManageSystemSettings(), 403);

        $validated = $request->validate([
            'additional_users' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $requested = (int) $validated['additional_users'];
        $tenant = $request->user()->tenant;
        $entitlements = app(EntitlementService::class);

        if ($subscription->includedUsers() === null) {
            return back()->with('info', 'Paket organisasi Anda tidak memiliki batas pengguna, sehingga tidak perlu menambah kapasitas.');
        }

        // The floor: what is already in use. Releasing below it would leave
        // active accounts over the limit, which is the one outcome this
        // whole entitlement exists to prevent.
        $minimum = $entitlements->additionalUsersRequiredFor($tenant, $entitlements->usersUsedCount($tenant));

        if ($requested < $minimum) {
            return back()->with('error',
                "Organisasi Anda sedang menggunakan {$entitlements->usersUsedCount($tenant)} pengguna aktif, "
                ."sehingga kapasitas tambahan tidak dapat dikurangi di bawah {$minimum}. "
                .'Nonaktifkan akun yang tidak terpakai terlebih dahulu.');
        }

        if ($requested === $subscription->additionalUsers()) {
            return back()->with('info', 'Jumlah pengguna tambahan tidak berubah.');
        }

        $before = $subscription->additionalUsers();

        $subscription->forceFill(['additional_users' => $requested])->save();

        ActivityLog::record(
            'updated',
            "Additional active-user capacity changed from {$before} to {$requested}.",
            $subscription
        );

        $charge = $this->pricing->format($subscription->fresh()->additionalUserCharge());

        return back()->with('success', $requested > $before
            ? "Kapasitas ditambah menjadi {$requested} pengguna tambahan. Biaya {$charge} per periode akan masuk pada tagihan perpanjangan berikutnya."
            : "Kapasitas tambahan diperbarui menjadi {$requested} pengguna. Perubahan biaya berlaku pada tagihan perpanjangan berikutnya.");
    }

    /**
     * Change plan.
     *
     * An UPGRADE produces a prorated invoice and takes effect when that
     * invoice is paid -- so the customer is sent straight to checkout. A
     * DOWNGRADE or a cycle change is scheduled for the period boundary and
     * costs nothing now, so it simply confirms.
     *
     * The distinction and the arithmetic both live in the lifecycle
     * service; this method's job is to establish who is asking, prove they
     * own the subscription, and refuse a change their current usage cannot
     * survive.
     */
    public function changePlan(Request $request): RedirectResponse
    {
        $subscription = $this->currentSubscription($request);

        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'billing_cycle' => ['required', Rule::in([Subscription::CYCLE_MONTHLY, Subscription::CYCLE_YEARLY])],
        ]);

        if ($subscription->isBlocked()) {
            return back()->with('info', 'Langganan organisasi Anda sedang dihentikan, sehingga paket belum dapat diubah. Hubungi '.config('ioms.emails.billing').'.');
        }

        $target = Package::findOrFail($validated['package_id']);
        $cycle = $validated['billing_cycle'];

        if (! $target->is_active) {
            return back()->withErrors(['package_id' => 'Paket tersebut sedang tidak tersedia.']);
        }

        if ((int) $target->id === (int) $subscription->package_id && $cycle === $subscription->billing_cycle) {
            return back()->with('info', 'Organisasi Anda sudah menggunakan paket dan siklus penagihan tersebut.');
        }

        // A plan the organization has already outgrown must not be
        // selectable. Enforced here rather than by hiding the option,
        // because hiding it is not enforcement -- and enforced BEFORE any
        // invoice exists, so a customer is never charged for a change that
        // then has to be refused.
        if ($blocker = $this->capacityBlocker($request, $target)) {
            return back()->withErrors(['package_id' => $blocker]);
        }

        $invoice = $this->lifecycle->requestPlanChange($subscription, $target, $cycle);

        if (! $invoice) {
            return back()->with('success', "Perubahan ke paket {$target->name} telah dijadwalkan dan akan berlaku saat masa aktif berjalan berakhir.");
        }

        $this->sendInvoiceEmail($request, $invoice);

        return redirect()->route('subscription.pay', $invoice);
    }

    /** Withdraw a scheduled downgrade or cycle change before it takes effect. */
    public function cancelPlanChange(Request $request): RedirectResponse
    {
        $this->lifecycle->cancelPendingChange($this->currentSubscription($request));

        return back()->with('success', 'Perubahan paket yang dijadwalkan telah dibatalkan.');
    }

    /**
     * v2.70.0 -- THE AUTHENTICATED CHECKOUT PAGE.
     *
     * The signed-in twin of the onboarding checkout, and deliberately the
     * same shape: IOMS states what is being bought, for which period, for
     * exactly how many rupiah, on its own page -- then opens the
     * provider's payment interface over it.
     *
     * NOTHING HERE CAN EXTEND A SUBSCRIPTION. The page renders server
     * state; the only thing the browser does with the token is open a
     * payment window. Whatever Snap reports back -- success, pending,
     * error -- results in a navigation to the billing page and nothing
     * else. The period moves only in PaymentWebhookController, from a
     * signed and verified payload.
     *
     * Only the CLIENT key reaches the browser. The server key never leaves
     * MidtransGateway.
     */
    public function pay(Request $request, Invoice $invoice): Response|RedirectResponse
    {
        abort_unless($request->user()->canManageSystemSettings(), 403);
        $this->assertOwned($request, $invoice);

        if ($invoice->status === Invoice::STATUS_PAID) {
            return redirect()->route('subscription.billing')
                ->with('info', "Tagihan {$invoice->invoice_number} sudah lunas.");
        }

        if (! $invoice->isPayable()) {
            return redirect()->route('subscription.billing')
                ->withErrors(['payment' => 'Tagihan ini tidak dapat dibayar.']);
        }

        // No gateway on this deployment. The invoice is real and payable by
        // transfer -- IOMS does not fabricate a checkout it cannot perform.
        if (! $this->paymentConfigured()) {
            return redirect()->route('subscription.billing')->with(
                'info',
                'Pembayaran online belum aktif pada instalasi ini. Tagihan Anda tetap berlaku dan dapat dibayar melalui transfer bank -- silakan hubungi '.config('ioms.emails.billing').'.'
            );
        }

        $transaction = $this->openCheckout($request, $invoice);

        if (! $transaction) {
            return redirect()->route('subscription.billing')
                ->withErrors(['payment' => 'Sesi pembayaran tidak dapat dibuat saat ini. Tagihan Anda sudah diterbitkan -- silakan coba lagi beberapa saat lagi.']);
        }

        $gateway = app(PaymentGatewayInterface::class);
        $subscription = $invoice->subscription;
        $package = $invoice->targetPackage ?? $subscription?->package;

        return Inertia::render('Subscription/Checkout', [
            'order' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'purpose' => $invoice->purpose,
                'organization_name' => $request->user()->tenant?->organizationName(),
                'plan_name' => $package?->name,
                'billing_cycle' => $invoice->target_billing_cycle ?? $subscription?->billing_cycle,
                'period_start' => $invoice->period_start?->format('d M Y'),
                'period_end' => $invoice->period_end?->format('d M Y'),
                'amount' => $this->pricing->format((float) $invoice->amount, $invoice->currency),
                'notes' => $invoice->notes,
            ],
            'payment' => [
                // Snap's own client-side configuration. The client key is
                // public by design; the server key is not sent.
                // v2.80.0: asked through the CONTRACT, not an instanceof chain a
                // second provider would have to be added to.
                ...$gateway->clientConfig(),
                'snap_token' => $transaction->checkout_token,
                // The provider's hosted page, kept as the fallback for a
                // browser where the Snap script cannot load.
                'fallback_url' => $transaction->redirect_url,
            ],
            'billingUrl' => route('subscription.billing'),
            'invoiceUrl' => route('subscription.invoices.pdf', $invoice),
            'billingEmail' => config('ioms.emails.billing'),
        ]);
    }

    /* ==================================================================
     * Internals
     * ================================================================== */

    /**
     * Reuses a live payment session when one exists, and asks the gateway
     * for a new one otherwise.
     *
     * Midtrans rejects a repeat of an order_id that already carries a
     * transaction, so a fresh reference is minted per attempt
     * (`MidtransGateway::orderIdFor()`); the invoice id stays the stable
     * prefix, so an inbound notification is always traceable back to
     * exactly one invoice.
     */
    private function openCheckout(Request $request, Invoice $invoice): ?PaymentTransaction
    {
        // v2.94.0: asked through the shared definition rather than by
        // testing for a Midtrans-shaped token. See PaymentTransaction::liveFor().
        if ($existing = PaymentTransaction::liveFor($invoice)) {
            return $existing;
        }

        try {
            $gateway = app(PaymentGatewayInterface::class);
            $user = $request->user();
            $package = $invoice->targetPackage ?? $invoice->subscription?->package;

            $checkout = $gateway->createPayment($invoice, [
                'plan_slug' => $package?->slug ?? 'subscription',
                'description' => 'IOMS '.($package?->name ?? 'Subscription'),
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'finish_url' => route('subscription.billing'),
            ]);

            return PaymentTransaction::create([
                'invoice_id' => $invoice->id,
                'gateway' => (string) config('payment.gateway'),
                'gateway_reference' => $checkout->gatewayReference,
                'status' => PaymentTransaction::STATUS_PENDING,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'redirect_url' => $checkout->redirectUrl,
                'checkout_token' => $checkout->token,
            ]);
        } catch (Throwable $e) {
            Log::error('Subscription checkout could not be created.', [
                'invoice' => $invoice->invoice_number,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The asking user's own subscription, or a 404.
     *
     * Resolved from the authenticated user's tenant, never from anything
     * the request carries -- there is no subscription id in any of these
     * routes precisely so there is nothing to tamper with.
     */
    private function currentSubscription(Request $request): Subscription
    {
        abort_unless($request->user()->canManageSystemSettings(), 403);

        $tenantId = app(CurrentTenant::class)->id();
        abort_if($tenantId === null, 404);

        $subscription = Subscription::with(['package', 'pendingPackage', 'tenant'])
            ->where('tenant_id', $tenantId)
            ->latest()
            ->first();

        abort_if($subscription === null, 404);

        return $subscription;
    }

    /** An invoice belongs to the asking organization, or it does not exist as far as they are concerned. */
    private function assertOwned(Request $request, Invoice $invoice): void
    {
        abort_unless(
            $invoice->tenant_id !== null && $invoice->tenant_id === $request->user()->tenant_id,
            404
        );
    }

    /**
     * Why this organization cannot move to this plan right now, or null.
     *
     * A downgrade must never leave a tenant over a limit it is already
     * past -- that would either strand data or force the product to pick
     * which users and operating units to cut off. The customer is told
     * exactly what to reduce instead.
     */
    private function capacityBlocker(Request $request, Package $target): ?string
    {
        $entitlements = app(EntitlementService::class);
        $tenant = $request->user()->tenant;
        $subscription = $tenant?->subscription;

        $units = $target->max_companies;

        /*
         * v2.82.0 -- CAPACITY ON THE TARGET PLAN INCLUDES WHAT WAS BOUGHT.
         *
         * `max_users` is the INCLUDED allowance now, not a ceiling, and
         * purchased additional users belong to the subscription rather than
         * to the plan -- so they survive a plan change and count towards
         * the limit on the new plan too.
         *
         * Comparing active users against the bare allowance would block a
         * downgrade the customer has already paid to make possible: a
         * Business tenant with 25 included and 10 purchased holds 35 active
         * accounts legitimately, and moving to Professional (10 included +
         * the same 10 purchased = 20) must be refused for the right reason
         * and with the right number.
         *
         * Nothing is deactivated on the customer's behalf either way. They
         * are told exactly what to reduce.
         */
        $included = $target->includedUsers();
        $seats = $included === null ? null : $included + ($subscription?->additionalUsers() ?? 0);

        if ($seats !== null && ($used = $entitlements->usersUsedCount($tenant)) > $seats) {
            $extra = $subscription?->additionalUsers() ?? 0;
            $capacity = $extra > 0
                ? "{$included} pengguna termasuk paket ditambah {$extra} pengguna tambahan ({$seats} total)"
                : "{$seats} pengguna";

            return "Paket {$target->name} mencakup {$capacity}, sedangkan organisasi Anda saat ini memiliki {$used} pengguna aktif. "
                .'Nonaktifkan akun yang tidak terpakai, atau tambah kapasitas, sebelum berpindah paket.';
        }

        /*
         * v2.86.0 -- THE SAME SAFETY, FOR THE SECOND CLASS.
         *
         * My Work capacity is its own pool, so a downgrade has to be
         * measured against it separately. Without this, a tenant on Business
         * with 45 My Work accounts could move to Starter, whose allowance is
         * 10, and thirty-five accounts would be over the limit the moment
         * the change applied.
         *
         * Purchased packs count towards the target plan exactly as purchased
         * Full Users do -- capacity the customer paid for is not discarded
         * by a plan change, which is the v2.82.0 rule applied to the new
         * pool rather than a second, different answer.
         *
         * Nothing is deactivated on the customer's behalf. They are told
         * what to reduce.
         */
        $myWorkIncluded = $target->max_my_work_users === null ? null : (int) $target->max_my_work_users;
        $myWorkSeats = $myWorkIncluded === null
            ? null
            : $myWorkIncluded + ($subscription?->additionalMyWorkUsers() ?? 0);

        if ($myWorkSeats !== null && ($used = $entitlements->myWorkUsersUsedCount($tenant)) > $myWorkSeats) {
            $purchased = $subscription?->additionalMyWorkUsers() ?? 0;
            $capacity = $purchased > 0
                ? "{$myWorkIncluded} My Work User termasuk paket ditambah {$purchased} tambahan ({$myWorkSeats} total)"
                : "{$myWorkSeats} My Work User";

            return "Paket {$target->name} mencakup {$capacity}, sedangkan organisasi Anda saat ini memiliki {$used} My Work User aktif. "
                .'Nonaktifkan akun yang tidak terpakai, atau tambah kapasitas My Work, sebelum berpindah paket.';
        }

        if ($units !== null && ($used = $entitlements->operatingUnitsUsedCount($tenant)) > $units) {

            return "Paket {$target->name} mencakup {$units} operating unit, sedangkan organisasi Anda saat ini memiliki {$used}. "
                .'Kurangi jumlah operating unit terlebih dahulu sebelum berpindah paket.';
        }

        return null;
    }

    /** A gateway counts as configured only when it is both named AND holds credentials. */
    private function paymentConfigured(): bool
    {
        // v2.80.0: "can this deployment take a payment" is the provider's own
        // question. Naming Midtrans here meant a second gateway would be
        // configured, bound, and still reported as no payments available.
        return app(PaymentGatewayInterface::class)->isConfigured();
    }

    /**
     * Mail is best-effort and never blocks the flow. A failure is logged
     * loudly rather than reported to the customer as a success -- IOMS
     * does not claim to have sent an email it did not send.
     */
    private function sendInvoiceEmail(Request $request, Invoice $invoice): void
    {
        try {
            $package = $invoice->targetPackage ?? $invoice->subscription?->package;

            Mail::to($request->user()->email)
                ->send(new InvoiceIssued(
                    $invoice,
                    $package?->name ?? 'IOMS',
                    $this->pricing->format((float) $invoice->amount, $invoice->currency),
                    route('subscription.pay', $invoice),
                    route('subscription.invoices.pdf', $invoice),
                ));
        } catch (Throwable $e) {
            Log::error('Subscription invoice email failed.', [
                'invoice' => $invoice->invoice_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

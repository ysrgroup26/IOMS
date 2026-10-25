<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceIssued;
use App\Mail\VerifyRegistrationEmail;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\TenantRegistration;
use App\Services\InvoiceDocumentService;
use App\Services\PdfGeneratorService;
use App\Services\PricingService;
use App\Contracts\PaymentGatewayInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * v2.51.0 -- self-service IOMS onboarding.
 * v2.74.2 -- the pay-first HALF of it is gone; the order half remains.
 *
 * REPLACES the mailto handoff. Asking a prospect to open a desktop mail
 * client is not an acquisition flow; it is a dead end wearing a button.
 *
 * WHAT CHANGED IN v2.74.2. The original journey was: choose a plan ->
 * create the account and company in one form -> verify the email -> pay
 * -> (webhook) -> provisioned. There was no account until the payment
 * cleared, so identity, company, plan and password all had to be
 * collected up front by a stranger who could not sign in.
 *
 * Since v2.74.0 an account can exist on its own, so that form no longer
 * has a reason to exist: Get Started creates an ACCOUNT, and the account
 * sets a subscription up afterwards from one page it can return to. The
 * pay-first `create`-form and its `store` endpoint are removed, and
 * `create()` now sends visitors to the account form.
 *
 * WHAT REMAINS HERE, and why: verify, resend, status, invoice, pay and
 * checkout. They are the ORDER half of the flow, still reached by every
 * order (`SubscribeController` hands off to `status`), and still the only
 * way an order raised before this release can be completed. Deleting them
 * would strand any in-flight registration.
 *
 * THE ORDERING RULE, which every method here obeys: nothing that grants
 * access happens before a payment IOMS verified server-side. A prospect
 * who reaches the status page has a registration, not a tenant; there is
 * no user account to sign in with until TenantProvisioningService runs.
 * `status()` deliberately reads state and renders it -- it never
 * activates anything, so refreshing, sharing or forging that URL achieves
 * nothing.
 */
class RegistrationController extends Controller
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Get Started -- which is now ACCOUNT REGISTRATION, and nothing else.
     *
     * A redirect rather than a second copy of the account form, so there
     * is exactly one account registration page and one canonical URL for
     * it. Every public "Get Started" call to action keeps working.
     *
     * A visitor who arrives from a plan card carries `?plan=` and
     * `?cycle=`. That intent is remembered in the SESSION rather than
     * pushed through the account form, because it is not an account
     * field -- the account form asks for four things and must not grow a
     * fifth. `SubscribeController::setup()` reads it back when the
     * account opens subscription setup, so clicking "Choose Starter" on
     * Pricing still lands on Starter three screens later.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        /*
         * Somebody already signed in does not need a signup page. Sent to
         * their OWN landing route rather than hard-coded to the dashboard:
         * an account with no organization has no dashboard to go to, and
         * this route is public, so it is reachable by exactly that account.
         */
        if ($user = $request->user()) {
            return redirect()->route($user->landingRouteName());
        }

        $plans = $this->pricing->publicPlans();

        // Validated against the real catalog, so an arbitrary slug is never
        // remembered and never echoed back into a later page.
        $plan = $plans->firstWhere('slug', (string) $request->query('plan', ''))['slug'] ?? null;

        if ($plan) {
            $request->session()->put('intended_plan', [
                'plan' => $plan,
                'cycle' => $this->pricing->normalizeCycle($request->query('cycle')),
            ]);
        }

        return redirect()->route('register');
    }

    /** Industries IOMS actually serves. A list, not free text, so Master Admin reporting stays consistent. */
    public const INDUSTRIES = [
        'Shipyard & Marine',
        'Construction & Civil',
        'Oil, Gas & Energy',
        'Manufacturing',
        'Mining & Minerals',
        'Engineering & Fabrication',
        'Logistics & Transportation',
        'Industrial Services',
        'Utilities',
        'Other',
    ];

    /*
     * v2.74.2 -- `store()` REMOVED, along with POST /get-started.
     *
     * It created a `TenantRegistration` carrying a hashed password and
     * no `users` row: the pay-first model, where identity, company,
     * plan and password were collected in one form from a stranger who
     * could not sign in until a payment cleared.
     *
     * Nothing renders that form any more, and an endpoint that creates
     * accounts and orders with no page able to reach it is a surface
     * with no purpose. Orders are now raised at POST /subscribe by a
     * signed-in, verified account, and nowhere else.
     *
     * What moved, and where it lives now:
     *   - duplicate-address handling  -> Auth\RegisteredUserController
     *   - server-derived pricing      -> SubscribeController::store()
     *   - plan-slug validation        -> SubscribeController::store()
     *   - email verification          -> the ACCOUNT's own verification
     *
     * Provisioning still supports a null `user_id` (see
     * TenantProvisioningService), because registrations raised before
     * this release must still be able to complete.
     */

    /**
     * Step 3 -- email verification. A signed, single-purpose URL; the token
     * is the registration's own 64-character secret, so it cannot be
     * guessed or enumerated from an id.
     */
    public function verify(string $token): RedirectResponse
    {
        $registration = $this->findOpen($token);

        if ($registration->status === TenantRegistration::STATUS_PENDING_VERIFICATION) {
            $registration->update([
                'status' => TenantRegistration::STATUS_VERIFIED,
                'email_verified_at' => now(),
            ]);
        }

        return redirect()->route('register.status', $token)
            ->with('success', 'Email address confirmed. You can now review your plan and complete payment.');
    }

    public function resendVerification(string $token): RedirectResponse
    {
        $registration = $this->findOpen($token);

        if (! $registration->isVerified()) {
            $this->sendVerificationEmail($registration);
        }

        return back()->with('success', 'Verification email sent again.');
    }

    /**
     * The registration's own status page. READ ONLY -- it renders whatever
     * the server already believes and changes nothing. This is the URL the
     * payment provider redirects back to, which is precisely why it must
     * not be able to activate anything.
     */
    public function status(string $token): Response
    {
        $registration = TenantRegistration::where('token', $token)->with('package', 'invoice')->firstOrFail();
        $this->repriceOpenOrder($registration);

        $package = $registration->package;
        $cycle = $registration->billing_cycle;

        return Inertia::render('Public/RegistrationStatus', [
            'registration' => [
                'reference' => $registration->reference,
                'token' => $token,
                'status' => $registration->status,
                'contact_name' => $registration->contact_name,
                'contact_email' => $registration->contact_email,
                'company_name' => $registration->displayName(),
                'company_legal_name' => $registration->company_legal_name,
                'billing_cycle' => $cycle,
                'is_verified' => $registration->isVerified(),
                'amount' => $this->pricing->format((float) $registration->amount, $registration->currency),
                'plan_name' => $package?->name,
                'invoice_number' => $registration->invoice?->invoice_number,
                'invoice_status' => $registration->invoice?->status,
            ],
            'paymentConfigured' => $this->paymentConfigured(),
            // Billing, not sales -- this page is about an invoice.
            'contactEmail' => config('ioms.emails.billing'),
        ]);
    }

    /**
     * Step 4 -- checkout. Issues the invoice and asks the gateway for a
     * payment session.
     *
     * The amount is recomputed here from the packages table rather than
     * reused from the registration row, so even a tampered-with database
     * row or a stale price cannot bill the wrong figure at the moment of
     * charge.
     */
    public function checkout(Request $request, string $token): RedirectResponse
    {
        $registration = $this->findOpen($token);

        if (! $registration->isVerified()) {
            return back()->withErrors(['payment' => 'Please confirm your email address first.']);
        }

        if ($registration->status === TenantRegistration::STATUS_PROVISIONED) {
            return redirect()->route('login');
        }

        $package = $registration->package;

        if (! $package) {
            return back()->withErrors(['payment' => 'The selected plan is no longer available. Please contact us.']);
        }

        $amount = $this->pricing->amountFor($package, $registration->billing_cycle);

        $invoice = DB::transaction(function () use ($registration, $amount, $package) {
            // Reuse the existing unpaid invoice rather than issuing a new
            // one every time the customer returns to checkout.
            $invoice = $registration->invoice;

            if ($invoice && $invoice->status !== Invoice::STATUS_PAID) {
                // v2.131.0: through the one repricing rule, which refuses
                // to move an invoice the provider is already collecting.
                $this->repriceOpenOrder($registration);

                return $invoice->fresh();
            }

            if ($invoice) {
                return $invoice;
            }

            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateNumber(null),
                'tenant_id' => null,
                'registration_id' => $registration->id,
                'period_start' => now()->toDateString(),
                'period_end' => $registration->billing_cycle === 'monthly'
                    ? now()->addMonth()->toDateString()
                    : now()->addYear()->toDateString(),
                'amount' => $amount,
                'currency' => $package->currency,
                'status' => Invoice::STATUS_ISSUED,
                'due_date' => now()->addDays(7)->toDateString(),
                /*
                 * v2.95.0 -- A FIRST PURCHASE IS AN ONBOARDING INVOICE.
                 *
                 * `purpose` defaults to `renewal` at the schema level, and
                 * this call never set it -- so every first purchase IOMS has
                 * ever issued was filed as a renewal of a subscription that
                 * did not yet exist. Harmless to settlement, which routes on
                 * `registration_id`, and wrong on the document: ADR 033 says
                 * an invoice states what it bought.
                 */
                'purpose' => Invoice::PURPOSE_ONBOARDING,
                'notes' => $this->invoiceNotes($registration, $package),
            ]);

            $registration->update([
                'invoice_id' => $invoice->id,
                'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            ]);

            return $invoice;
        });

        $this->sendInvoiceEmail($registration, $invoice, $package->name, $amount);

        // No gateway configured. The invoice is real and the registration
        // is honestly parked at "awaiting payment" -- IOMS does not
        // fabricate a checkout it cannot perform.
        if (! $this->paymentConfigured()) {
            return redirect()->route('register.status', $token)
                ->with('info', 'Your invoice has been issued. Online payment is not enabled on this deployment yet. Our team will contact you with payment instructions.');
        }

        /*
         * v2.94.0 -- REUSE A LIVE SESSION INSTEAD OF OPENING A SECOND ONE.
         *
         * This path previously created a payment transaction on EVERY call,
         * so a customer returning to checkout opened another real inquiry at
         * the provider each time and left another pending row behind. The
         * invoice was already being reused here; the payment session was not.
         */
        if (PaymentTransaction::liveFor($invoice)) {
            return redirect()->route('register.pay', $token);
        }

        /*
         * v2.95.0 -- A PROVIDER THAT NEEDS A METHOD GETS ASKED FIRST.
         *
         * Duitku's inquiry refuses outright without `paymentMethod`, so
         * there is nothing to create until the customer has chosen. The
         * order summary page does the asking; this step stops at the
         * invoice, which is the honest state -- the invoice is real and
         * nothing has been attempted at the provider yet.
         *
         * A provider that presents its own picker (Midtrans Snap) returns an
         * empty list and keeps the original behaviour: the session is
         * created here and the summary page opens it.
         */
        if (app(PaymentGatewayInterface::class)->getPaymentMethods((float) $invoice->amount, $invoice->currency) !== []) {
            return redirect()->route('register.pay', $token);
        }

        try {
            $gateway = app(PaymentGatewayInterface::class);

            $checkout = $gateway->createPayment($invoice, [
                'plan_slug' => $package->slug,
                'description' => 'IOMS '.$package->name,
                'customer_name' => $registration->contact_name,
                'customer_email' => $registration->billingEmail(),
                'customer_phone' => $registration->contact_phone,
                'finish_url' => route('register.status', $token),
            ]);

            PaymentTransaction::create([
                'invoice_id' => $invoice->id,
                'gateway' => (string) config('payment.gateway'),
                'gateway_reference' => $checkout->gatewayReference,
                'status' => PaymentTransaction::STATUS_PENDING,
                'amount' => $amount,
                'currency' => $package->currency,
                'redirect_url' => $checkout->redirectUrl,
                'checkout_token' => $checkout->token,
            ]);

            // v2.55.0 -- IOMS SHOWS ITS OWN ORDER SUMMARY FIRST.
            //
            // This used to be redirect()->away($checkout->redirectUrl),
            // which sent the customer straight off iomsuite.com the instant
            // they clicked pay. A buyer's last view before entering payment
            // details should state what they are buying, for how long, and
            // for exactly how many rupiah -- on the seller's own site.
            //
            // The payment interface itself is unchanged and still the
            // provider's: the next page opens Snap over the summary, and
            // falls back to the very same redirect URL if it cannot.
            return redirect()->route('register.pay', $token);
        } catch (Throwable $e) {
            Log::error('Onboarding checkout could not be created.', [
                'registration' => $registration->reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('register.status', $token)
                ->withErrors(['payment' => 'We could not start the payment session. Your invoice has been issued. Please try again shortly or contact us.']);
        }
    }

    /**
     * v2.55.0 -- THE IOMS CHECKOUT PAGE.
     *
     * Renders the order the customer is about to pay for -- plan, billing
     * cycle, period, invoice number and the amount in IDR -- on IOMS's own
     * page, then opens the provider's payment interface over it using the
     * Snap token created by `checkout()`.
     *
     * NOTHING HERE CAN ACTIVATE ANYTHING. The page is read-only: it renders
     * server state, and the only thing the browser does with the token is
     * open a payment window. Whatever Snap reports back to the browser --
     * success, pending, error -- results in a navigation to the status
     * page and nothing else. Activation still happens exclusively in
     * PaymentWebhookController, from a payload the provider signed and this
     * server verified. That separation is the whole point and it is
     * unchanged.
     *
     * Only the CLIENT key reaches the browser; it identifies the merchant
     * to Snap and authorises nothing. The server key never leaves
     * MidtransGateway.
     */
    public function pay(string $token): Response|RedirectResponse
    {
        $registration = $this->findOpen($token);

        if ($registration->status === TenantRegistration::STATUS_PROVISIONED) {
            return redirect()->route('login');
        }

        $this->repriceOpenOrder($registration);
        $invoice = $registration->invoice;

        // No invoice yet means checkout was never started -- send them back
        // to the status page, which is the page that offers to start it.
        if (! $invoice || ! $this->paymentConfigured()) {
            return redirect()->route('register.status', $token);
        }

        $gateway = app(PaymentGatewayInterface::class);
        $package = $registration->package;

        $transaction = PaymentTransaction::liveFor($invoice);

        /*
         * v2.95.0 -- the methods this provider wants the customer to choose
         * from. Empty for a provider that picks its own (Snap), in which
         * case a session must already exist for this page to mean anything.
         */
        $methods = $transaction
            ? []
            : $gateway->getPaymentMethods((float) $invoice->amount, $invoice->currency);

        if (! $transaction && $methods === []) {
            return redirect()->route('register.status', $token)
                ->withErrors(['payment' => 'Sesi pembayaran belum tersedia. Silakan mulai pembayaran kembali dari halaman ini.']);
        }

        return Inertia::render('Public/Checkout', [
            'order' => [
                'reference' => $registration->reference,
                'token' => $token,
                'company_name' => $registration->displayName(),
                'contact_email' => $registration->billingEmail(),
                'plan_name' => $package?->name,
                'billing_cycle' => $registration->billing_cycle,
                'invoice_number' => $invoice->invoice_number,
                'period_start' => $invoice->period_start?->format('d M Y'),
                'period_end' => $invoice->period_end?->format('d M Y'),
                'amount' => $this->pricing->format((float) $invoice->amount, $invoice->currency),
                'currency' => $invoice->currency,
                // v2.56.0: the same annual-saving figures the Pricing page and
                // Get Started show, from the one server-side derivation, so a
                // buyer sees the discount restated at the moment they pay.
                // Only meaningful on an annual order.
                'annual_saving' => $registration->billing_cycle === 'yearly' && $package
                    ? $this->pricing->summarize($package)['annual_saving']
                    : null,
            ],
            'payment' => [
                // Snap's own client-side configuration. The client key is
                // public by design; the server key is not sent.
                // v2.80.0: asked through the CONTRACT, not an instanceof chain a
                // second provider would have to be added to.
                ...$gateway->clientConfig(),
                'snap_token' => $transaction?->checkout_token,
                // The provider's hosted page, kept as the fallback for a
                // browser where the Snap script cannot load.
                'fallback_url' => $transaction?->redirect_url,
                // v2.95.0 -- the choice, when the provider needs one made.
                // Codes only; nothing here authorises anything, and the
                // chosen code is re-validated server-side against this same
                // live list before any session is created.
                'methods' => $methods,
                'start_url' => route('register.pay.start', $token),
                // Native form POST, because the response is an external
                // redirect to the provider and an XHR cannot follow one.
                'csrf' => csrf_token(),
            ],
            'statusUrl' => route('register.status', $token),
            'invoiceUrl' => route('register.invoice', $token),
            'billingEmail' => config('ioms.emails.billing'),
        ]);
    }

    /**
     * v2.95.0 -- CREATE THE PAYMENT SESSION FOR A CHOSEN METHOD.
     *
     * Separate from `checkout()` because the provider cannot be asked for a
     * session until the customer has picked a channel, and separate from
     * `pay()` because opening a page must never create anything.
     *
     * THE CHOICE IS RE-VALIDATED AGAINST THE PROVIDER'S OWN LIVE LIST, not
     * against a stored enum and not against what the browser sent. The
     * method decides which instrument the customer is sent to and what fee
     * applies, so a request naming a channel this merchant cannot take, or
     * one disabled since the page rendered, is refused rather than passed
     * through to fail at the provider.
     */
    public function startPayment(Request $request, string $token): RedirectResponse
    {
        $registration = $this->findOpen($token);
        $this->repriceOpenOrder($registration);
        $invoice = $registration->invoice;

        if (! $invoice || ! $this->paymentConfigured()) {
            return redirect()->route('register.status', $token);
        }

        // Already paying. Never open a second session for one invoice.
        if ($existing = PaymentTransaction::liveFor($invoice)) {
            return redirect()->away($existing->redirect_url);
        }

        $gateway = app(PaymentGatewayInterface::class);
        $available = collect($gateway->getPaymentMethods((float) $invoice->amount, $invoice->currency));

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'max:10'],
        ]);

        if (! $available->contains('code', $validated['payment_method'])) {
            return back()->withErrors([
                'payment' => 'Metode pembayaran tersebut tidak tersedia. Silakan pilih metode lain.',
            ]);
        }

        try {
            $checkout = $gateway->createPayment($invoice, [
                'payment_method' => $validated['payment_method'],
                'plan_slug' => $registration->package?->slug,
                'customer_name' => $registration->contact_name,
                'customer_email' => $registration->billingEmail(),
                'finish_url' => route('register.status', $token),
            ]);

            PaymentTransaction::create([
                'invoice_id' => $invoice->id,
                'gateway' => (string) config('payment.gateway'),
                'gateway_reference' => $checkout->gatewayReference,
                'status' => PaymentTransaction::STATUS_PENDING,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'redirect_url' => $checkout->redirectUrl,
                'checkout_token' => $checkout->token,
                // Recorded at CREATION, so an abandoned session still says
                // which channel the customer chose.
                'payment_method' => $validated['payment_method'],
            ]);

            return redirect()->away($checkout->redirectUrl);
        } catch (Throwable $e) {
            Log::error('Onboarding payment session could not be created.', [
                'registration' => $registration->reference,
                'method' => $validated['payment_method'],
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'payment' => 'Sesi pembayaran tidak dapat dibuat. Silakan coba lagi atau pilih metode lain.',
            ]);
        }
    }

    /**
     * v2.95.0 -- what the invoice SAYS it bought.
     *
     * Business annual is twelve months' money for fourteen months of
     * access. The invoice bills the PAID period -- twelve months -- because
     * that is what the customer is being charged for and what their own
     * accounting files. The two free months are stated in the notes rather
     * than folded into the period, so the document is honest about both:
     * the amount covers twelve, the access runs to fourteen.
     *
     * Derived from the plan, never hardcoded: a tier whose service term
     * equals its paid term simply gets no bonus line.
     */
    private function invoiceNotes(TenantRegistration $registration, Package $package): string
    {
        $notes = 'IOMS '.$package->name.' -- '.$registration->billing_cycle.' subscription ('.$registration->reference.')';

        if ($registration->billing_cycle !== 'yearly') {
            return $notes;
        }

        $bonus = $package->annualMonths() - 12;

        if ($bonus <= 0) {
            return $notes;
        }

        $accessUntil = now()->addMonths($package->annualMonths())->translatedFormat('d F Y');

        return $notes
            .'. Tagihan ini mencakup 12 bulan berbayar. Tambahan '.$bonus
            .' bulan akses diberikan gratis, sehingga masa akses berlaku sampai '.$accessUntil.'.';
    }

    /**
     * v2.55.0 -- the invoice PDF for a customer who does not have an
     * account yet.
     *
     * The first invoice is issued at checkout, BEFORE provisioning: there
     * is no tenant and no login, which is the whole reason
     * TenantRegistration lives outside the isolation boundary. So the
     * signed-in route cannot serve it, and the unguessable registration
     * token is the credential -- the same token that already authorises
     * the status page and the checkout itself.
     *
     * Only ever serves the invoice attached to THAT registration, so a
     * token cannot be used to reach any other invoice.
     */
    public function invoicePdf(string $token, PdfGeneratorService $pdf, InvoiceDocumentService $documents): \Illuminate\Http\Response
    {
        $registration = TenantRegistration::where('token', $token)
            ->with('invoice', 'package')
            ->firstOrFail();

        $this->repriceOpenOrder($registration);
        $invoice = $registration->invoice;

        abort_if($invoice === null, 404);

        return $pdf->streamInline(
            'pdf.invoice',
            $documents->viewData($invoice),
            $invoice->invoice_number.'.pdf'
        );
    }

    /** A gateway counts as configured only when it is both named AND holds credentials. */
    private function paymentConfigured(): bool
    {
        // v2.80.0: through the contract -- see SubscriptionController.
        return app(PaymentGatewayInterface::class)->isConfigured();
    }

    private function findOpen(string $token): TenantRegistration
    {
        return TenantRegistration::where('token', $token)->with('package')->firstOrFail();
    }

    /**
     * v2.131.0 -- AN OPEN ORDER IS BILLED AT TODAY'S CATALOGUE, EVERYWHERE.
     *
     * The defect: Master Admin showed Starter at Rp189.000 while a customer's
     * invoice read Rp299.000. The catalogue was right (v2.82.0 repriced it in
     * a migration). The stale figure lived on an order raised BEFORE that,
     * and only `checkout()` ever repriced one -- the status page printed the
     * registration's own snapshot, and `pay()` / `startPayment()` asked the
     * payment provider to collect the invoice's old amount as it stood. So
     * the plan, the page, the invoice and the payment request could name
     * three different prices for one order.
     *
     * The rule now lives here and every entry point asks it:
     *
     *   - a PAID invoice is never touched -- it is a financial record;
     *   - an invoice with a LIVE payment session is not touched either: the
     *     provider was asked for that amount, and the callback's amount
     *     check would (rightly) refuse a settlement that disagreed with it.
     *     The session expires, and the next one is raised at the new price;
     *   - otherwise the registration and its invoice both follow the
     *     catalogue, and the change is written to the activity log.
     *
     * A PROVISIONED customer's agreed price is not this method's business:
     * `subscriptions.agreed_price_*` (ADR 041, v2.60.0) keeps a live customer
     * on what they agreed, deliberately.
     */
    private function repriceOpenOrder(TenantRegistration $registration): void
    {
        $package = $registration->package;

        if (! $package || in_array($registration->status, [
            TenantRegistration::STATUS_PAID,
            TenantRegistration::STATUS_PROVISIONED,
            TenantRegistration::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        $amount = $this->pricing->amountFor($package, $registration->billing_cycle);
        $invoice = $registration->invoice;

        if ($invoice && ($invoice->status === Invoice::STATUS_PAID || PaymentTransaction::liveFor($invoice))) {
            return;
        }

        $was = (float) ($invoice?->amount ?? $registration->amount);

        if ($was === (float) $amount
            && (float) $registration->amount === (float) $amount
            && $registration->currency === $package->currency) {
            return;
        }

        DB::transaction(function () use ($registration, $invoice, $amount, $package) {
            $registration->update(['amount' => $amount, 'currency' => $package->currency]);
            $invoice?->update(['amount' => $amount, 'currency' => $package->currency]);
        });

        if ($was !== (float) $amount) {
            \App\Models\ActivityLog::record(
                'updated',
                sprintf(
                    'Open order %s repriced to the current %s catalogue: %s -> %s.',
                    $registration->reference,
                    $package->name,
                    $this->pricing->format($was, $package->currency),
                    $this->pricing->format((float) $amount, $package->currency),
                ),
                $registration
            );
        }
    }

    /**
     * Mail is best-effort and never blocks the flow. A failure is logged
     * loudly rather than reported to the prospect as a success -- IOMS
     * does not claim to have sent an email it did not send.
     */
    private function sendVerificationEmail(TenantRegistration $registration): void
    {
        try {
            Mail::to($registration->contact_email)->send(
                new VerifyRegistrationEmail($registration, route('register.verify', $registration->token))
            );
        } catch (Throwable $e) {
            Log::error('Registration verification email failed.', [
                'registration' => $registration->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendInvoiceEmail(TenantRegistration $registration, Invoice $invoice, string $planName, float $amount): void
    {
        try {
            Mail::to($registration->billingEmail())->send(new InvoiceIssued(
                $invoice,
                $planName,
                $this->pricing->format($amount, $invoice->currency),
                route('register.status', $registration->token),
                // v2.55.0: the invoice as a PDF. The customer has no account
                // yet, so the registration token is the credential -- the same
                // one that already authorises the status page above.
                route('register.invoice', $registration->token),
            ));
        } catch (Throwable $e) {
            Log::error('Invoice email failed.', [
                'invoice' => $invoice->invoice_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

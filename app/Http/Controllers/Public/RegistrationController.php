<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceIssued;
use App\Mail\VerifyRegistrationEmail;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\PricingService;
use App\Services\Payments\MidtransGateway;
use App\Contracts\PaymentGatewayInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * v2.51.0 -- self-service IOMS onboarding.
 *
 * REPLACES the mailto handoff. Asking a prospect to open a desktop mail
 * client is not an acquisition flow; it is a dead end wearing a button.
 *
 * The journey: choose a plan -> create the account and company -> verify
 * the email -> review the plan -> pay -> (webhook) -> provisioned.
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

    /** Step 1 -- the Get Started page: plan selection plus the onboarding form. */
    public function create(Request $request): Response|RedirectResponse
    {
        // An authenticated tenant user has no business creating a second
        // company registration by accident. Send them where they can
        // actually act instead of showing them a signup form.
        if ($request->user()) {
            return $request->user()->isPlatformAdmin()
                ? redirect()->route('platform.dashboard')
                : redirect()->route('dashboard');
        }

        $plans = $this->pricing->publicPlans();
        $requested = (string) $request->query('plan', '');

        return Inertia::render('Public/GetStarted', [
            'plans' => $plans,
            // Validated against the real catalog so an arbitrary slug is
            // never echoed back into the page.
            'selectedPlan' => $plans->firstWhere('slug', $requested)['slug'] ?? null,
            'billingCycle' => $this->pricing->normalizeCycle($request->query('cycle')),
            'industries' => self::INDUSTRIES,
            'supportEmail' => config('ioms.support_email'),
        ]);
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

    /** Step 2 -- create the pending registration. Creates NO tenant, NO company and NO user account. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'company_legal_name' => ['required', 'string', 'max:255'],
            'company_display_name' => ['nullable', 'string', 'max:255'],
            'company_industry' => ['nullable', Rule::in(self::INDUSTRIES)],
            'company_address' => ['required', 'string', 'max:500'],
            'company_city' => ['required', 'string', 'max:120'],
            'company_province' => ['required', 'string', 'max:120'],
            'company_postal_code' => ['nullable', 'string', 'max:20'],
            'company_country' => ['nullable', 'string', 'max:100'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_tax_id' => ['nullable', 'string', 'max:50'],
            'company_business_id' => ['nullable', 'string', 'max:50'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp', 'max:2048'],

            'plan' => ['required', 'string'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'terms' => ['accepted'],
        ]);

        // The plan and its price are resolved server-side from the real
        // catalog. Nothing about the money comes from the request.
        $package = $this->pricing->resolvePublicPackage($validated['plan']);

        if (! $package) {
            return back()->withErrors(['plan' => 'Please choose one of the available IOMS plans.'])->withInput();
        }

        $cycle = $this->pricing->normalizeCycle($validated['billing_cycle']);
        $email = mb_strtolower(trim($validated['contact_email']));

        // Duplicate identity checks, in both directions: an existing IOMS
        // user, and an open registration for the same address. Both return
        // the SAME message and point at sign-in, so this endpoint cannot be
        // used to enumerate which companies already use IOMS.
        $alreadyKnown = User::where('email', $email)->exists()
            || TenantRegistration::where('contact_email', $email)
                ->whereIn('status', TenantRegistration::OPEN_STATUSES)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();

        if ($alreadyKnown) {
            return back()->withErrors([
                'contact_email' => 'This email address is already registered with IOMS. Please sign in, or use a different address.',
            ])->withInput();
        }

        $logoPath = $request->hasFile('logo')
            ? $request->file('logo')->store('uploads/company', 'public')
            : null;

        $registration = TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_PENDING_VERIFICATION,

            'contact_name' => $validated['contact_name'],
            'contact_email' => $email,
            'contact_phone' => $validated['contact_phone'] ?? null,
            // Hashed here. The plaintext never touches the database and is
            // never emailed -- the administrator signs in with the password
            // they just chose.
            'password' => Hash::make($validated['password']),

            'company_legal_name' => $validated['company_legal_name'],
            'company_display_name' => $validated['company_display_name'] ?? null,
            'company_industry' => $validated['company_industry'] ?? null,
            'company_address' => $validated['company_address'],
            'company_city' => $validated['company_city'],
            'company_province' => $validated['company_province'],
            'company_postal_code' => $validated['company_postal_code'] ?? null,
            'company_country' => $validated['company_country'] ?: 'Indonesia',
            'company_phone' => $validated['company_phone'] ?? null,
            'company_email' => $validated['company_email'] ?? null,
            'company_tax_id' => $validated['company_tax_id'] ?? null,
            'company_business_id' => $validated['company_business_id'] ?? null,
            'company_logo_path' => $logoPath,
            'billing_email' => $validated['billing_email'] ?? null,

            'package_id' => $package->id,
            'billing_cycle' => $cycle,
            'amount' => $this->pricing->amountFor($package, $cycle),
            'currency' => $package->currency,

            // An abandoned checkout stops holding the email address.
            'expires_at' => now()->addDays(7),
            'ip_address' => $request->ip(),
        ]);

        $this->sendVerificationEmail($registration);

        return redirect()->route('register.status', $registration->token);
    }

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
                'is_expired' => $registration->isExpired(),
                'amount' => $this->pricing->format((float) $registration->amount, $registration->currency),
                'plan_name' => $package?->name,
                'invoice_number' => $registration->invoice?->invoice_number,
                'invoice_status' => $registration->invoice?->status,
            ],
            'paymentConfigured' => $this->paymentConfigured(),
            'supportEmail' => config('ioms.support_email'),
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
                $invoice->update([
                    'amount' => $amount,
                    'currency' => $package->currency,
                ]);

                return $invoice;
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
                'notes' => 'IOMS '.$package->name.' -- '.$registration->billing_cycle.' subscription ('.$registration->reference.')',
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
                ->with('info', 'Your invoice has been issued. Online payment is not enabled on this deployment yet — our team will contact you with payment instructions.');
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
            ]);

            return redirect()->away($checkout->redirectUrl);
        } catch (Throwable $e) {
            Log::error('Onboarding checkout could not be created.', [
                'registration' => $registration->reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('register.status', $token)
                ->withErrors(['payment' => 'We could not start the payment session. Your invoice has been issued — please try again shortly or contact us.']);
        }
    }

    /** A gateway counts as configured only when it is both named AND holds credentials. */
    private function paymentConfigured(): bool
    {
        return config('payment.gateway') === MidtransGateway::GATEWAY
            && filled(config('payment.midtrans.server_key'))
            && filled(config('payment.midtrans.client_key'));
    }

    private function findOpen(string $token): TenantRegistration
    {
        return TenantRegistration::where('token', $token)->with('package')->firstOrFail();
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
            ));
        } catch (Throwable $e) {
            Log::error('Invoice email failed.', [
                'invoice' => $invoice->invoice_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

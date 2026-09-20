<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.74.0 -- AN EXISTING ACCOUNT ACQUIRES AN ORGANIZATION.
 *
 *   Account -> Choose Plan -> Organization Details -> Order Summary
 *           -> Payment -> (webhook) -> Subscription Active
 *
 * The counterpart to the legacy public `/get-started` flow, which asks a
 * stranger for identity, company and plan in one form because there is no
 * account to draw on. Here there IS one, so the account's own name and
 * email are read from the session and never asked for again -- the form
 * collects only what belongs to the BUSINESS.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS CONTROLLER DELIBERATELY DOES NOT DO
 * ---------------------------------------------------------------------
 *
 * It does not create a Tenant, a Company, an Operating Unit or a
 * Subscription. It creates an ORDER -- a `TenantRegistration` -- and hands
 * off to the existing, unchanged payment path:
 *
 *   register.checkout -> register.pay -> PaymentWebhookController
 *                     -> TenantProvisioningService::activate()
 *
 * That path already enforces the rule this product is built on: nothing
 * that grants access happens before a payment the server itself verified
 * against a signed webhook. Re-implementing any of it here would mean two
 * ways to activate a tenant, and the second one would be the weaker.
 *
 * The ONE thing that changed downstream is that the order now carries
 * `user_id` from the moment it is created, which tells provisioning to
 * ATTACH this existing account to the new tenant rather than create a
 * second user. See TenantProvisioningService.
 *
 * ---------------------------------------------------------------------
 * WHO MAY BE HERE
 * ---------------------------------------------------------------------
 *
 * Signed in, email confirmed, and without an organization already. The
 * verification requirement is the one gate email verification actually
 * enforces in IOMS: buying a subscription against an address nobody has
 * confirmed means the invoice and the activation notice go nowhere.
 */
class SubscribeController extends Controller
{
    /**
     * Industries offered on the organization form.
     *
     * Deliberately the SAME list the public onboarding form uses, read
     * from that controller rather than copied, so the two flows cannot
     * drift into offering different vocabularies for one field.
     */
    private const INDUSTRIES = \App\Http\Controllers\Public\RegistrationController::INDUSTRIES;

    public function __construct(private readonly PricingService $pricing) {}

    /** Step 1 -- choose a plan. */
    public function plans(Request $request): Response|RedirectResponse
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        return Inertia::render('Subscribe/Plans', [
            'plans' => $this->pricing->publicPlans(),
            'account' => $this->accountSummary($request->user()),
        ]);
    }

    /** Step 2 -- the organization. The account's own identity is NOT asked for again. */
    public function organization(Request $request, string $plan): Response|RedirectResponse
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $package = $this->pricing->resolvePublicPackage($plan);

        if (! $package) {
            return redirect()->route('subscribe.plans')
                ->withErrors(['plan' => 'Please choose one of the available IOMS plans.']);
        }

        $cycle = $this->pricing->normalizeCycle($request->query('cycle'));

        return Inertia::render('Subscribe/Organization', [
            'account' => $this->accountSummary($request->user()),
            'plan' => $this->pricing->summarize($package),
            'cycle' => $cycle,
            'amount' => $this->pricing->amountFor($package, $cycle),
            'amountFormatted' => $this->pricing->format($this->pricing->amountFor($package, $cycle), $package->currency),
            'industries' => self::INDUSTRIES,
        ]);
    }

    /**
     * Step 2 (submit) -- create the ORDER.
     *
     * Nothing is provisioned here and no money moves. The resulting
     * `TenantRegistration` is a pending order that expires if abandoned.
     */
    public function storeOrganization(Request $request, string $plan): RedirectResponse
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $user = $request->user();

        $package = $this->pricing->resolvePublicPackage($plan);

        if (! $package) {
            return back()->withErrors(['plan' => 'Please choose one of the available IOMS plans.'])->withInput();
        }

        $validated = $request->validate([
            // Only what belongs to the BUSINESS. No name, no email, no
            // password: all three already exist on the account.
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
            // Defaults to the account's own address when left blank -- see
            // TenantRegistration::billingEmail().
            'billing_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'terms' => ['accepted'],
        ]);

        $cycle = $this->pricing->normalizeCycle($validated['billing_cycle']);

        /*
         * One live order per account.
         *
         * Returning to this step after abandoning checkout updates the
         * existing order rather than raising a second one -- otherwise a
         * customer who changes their mind about a plan leaves a trail of
         * orphan orders, each holding an invoice.
         */
        $registration = TenantRegistration::where('user_id', $user->id)
            ->whereIn('status', [
                TenantRegistration::STATUS_VERIFIED,
                TenantRegistration::STATUS_AWAITING_PAYMENT,
            ])
            ->latest('id')
            ->first();

        $attributes = [
            ...$validated,
            // `?? null` first: a nullable field the browser did not send
            // is ABSENT from the validated array, not present-and-empty, so
            // reading it directly is an undefined-key error rather than the
            // fallback it looks like. Caught by a test that simply omitted
            // the optional fields, which is what a real form does.
            'company_country' => ($validated['company_country'] ?? null) ?: 'Indonesia',

            // Identity, taken from the ACCOUNT. Never from the request --
            // a form field for these would let somebody raise an order
            // against another person's name.
            'contact_name' => $user->name,
            'contact_email' => $user->email,
            'user_id' => $user->id,

            // No password: the credential already lives on the users row.
            // The column is nullable precisely for this path.
            'password' => null,

            'package_id' => $package->id,
            'billing_cycle' => $cycle,
            'amount' => $this->pricing->amountFor($package, $cycle),
            'currency' => $package->currency,

            /*
             * VERIFIED from the outset. The email-verification step of the
             * legacy flow exists to prove the address belongs to the
             * person ordering; here that was already established when the
             * account confirmed its address, which `guard()` requires
             * before this method can run. Making them confirm the same
             * address twice would be asking them to prove a thing they
             * have proved.
             */
            'status' => TenantRegistration::STATUS_VERIFIED,
            'email_verified_at' => now(),

            'expires_at' => now()->addDays(7),
            'ip_address' => $request->ip(),
        ];

        unset($attributes['terms']);

        if ($registration) {
            $registration->update($attributes);
        } else {
            $registration = TenantRegistration::create([
                ...$attributes,
                'token' => TenantRegistration::newToken(),
                'reference' => TenantRegistration::newReference(),
            ]);
        }

        ActivityLog::record(
            'created',
            "Subscription order {$registration->reference} raised for {$registration->displayName()}.",
            $registration
        );

        return redirect()->route('subscribe.summary', $registration->token);
    }

    /**
     * Step 3 -- the order summary.
     *
     * The last IOMS-owned page before the payment provider. It states what
     * is being bought, for which organization, for how long and for
     * exactly how much, and its only action posts to the EXISTING checkout
     * endpoint -- which raises the invoice and opens the gateway session.
     */
    public function summary(Request $request, string $token): Response|RedirectResponse
    {
        if ($guard = $this->guard($request, allowWithOrganization: true)) {
            return $guard;
        }

        $registration = $this->findOwnOrder($request->user(), $token);

        // Already paid and provisioned: there is nothing to summarise, and
        // the customer wants their workspace, not a receipt page.
        if ($registration->status === TenantRegistration::STATUS_PROVISIONED) {
            return redirect()->route('dashboard');
        }

        $package = $registration->package;

        return Inertia::render('Subscribe/Summary', [
            'account' => $this->accountSummary($request->user()),
            'order' => [
                'token' => $registration->token,
                'reference' => $registration->reference,
                'status' => $registration->status,
                'organization' => $registration->displayName(),
                'legal_name' => $registration->company_legal_name,
                'address' => trim(implode(', ', array_filter([
                    $registration->company_address,
                    $registration->company_city,
                    $registration->company_province,
                    $registration->company_postal_code,
                    $registration->company_country,
                ]))),
                'industry' => $registration->company_industry,
                'billing_email' => $registration->billingEmail(),
                'billing_cycle' => $registration->billing_cycle,
            ],
            'plan' => $package ? $this->pricing->summarize($package) : null,
            'amount' => (float) $registration->amount,
            'amountFormatted' => $this->pricing->format((float) $registration->amount, $registration->currency ?: 'IDR'),
            // The order can still be corrected from here without losing it.
            'editUrl' => $package ? route('subscribe.organization', $package->slug).'?cycle='.$registration->billing_cycle : null,
            // Posts to the existing, unchanged checkout endpoint.
            'checkoutUrl' => route('register.checkout', $registration->token),
        ]);
    }

    /**
     * The account's own identity, for pre-filling and for display.
     *
     * Returned rather than rendered into a form field the user can edit:
     * the subscribe flow READS these, it does not collect them.
     */
    private function accountSummary(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /**
     * Who may proceed, and where they go if they may not.
     *
     * Returns a redirect to send back, or null to continue. Written as one
     * guard called by every step rather than repeated checks, so a new
     * step cannot be added without one.
     */
    private function guard(Request $request, bool $allowWithOrganization = false): ?RedirectResponse
    {
        $user = $request->user();

        // An account that has not confirmed its address cannot buy. This
        // is the one place IOMS actually enforces verification -- see
        // EmailVerificationController on why it is not enforced more
        // broadly.
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('account.overview')->withErrors([
                'email' => 'Please confirm your email address before subscribing. We have sent you a confirmation link.',
            ]);
        }

        // Already has an organization. `summary` allows it so a customer
        // can still see the order that provisioned them; the earlier steps
        // do not, because a second organization is not something this flow
        // creates. (Platform admins are excluded by hasNoOrganization().)
        if (! $allowWithOrganization && ! $user->hasNoOrganization()) {
            return redirect()->route('subscription.billing');
        }

        return null;
    }

    /**
     * The order, scoped to the account that raised it.
     *
     * 404 rather than 403 -- the same pattern used throughout this
     * codebase, because a 403 confirms the order exists. The token is a
     * 64-character secret, but ownership is checked anyway: a secret that
     * leaks should not also be an authorisation.
     */
    private function findOwnOrder(User $user, string $token): TenantRegistration
    {
        $registration = TenantRegistration::where('token', $token)->first();

        abort_unless($registration && $registration->user_id === $user->id, 404);

        return $registration;
    }
}

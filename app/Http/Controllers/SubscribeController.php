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
 *   Account -> Set up subscription -> Payment
 *           -> (verified webhook) -> Subscription Active
 *
 * ---------------------------------------------------------------------
 * THE SUBSCRIPTION SETUP FORM LIVES HERE, AND ONLY HERE
 * ---------------------------------------------------------------------
 *
 * `Public/GetStarted` is the subscription setup form: Your account, Your
 * company, Your plan, Continue to payment. It is the only one.
 *
 * ITS NAME IS HISTORICAL. It was the `/get-started` page, back when the
 * only way to buy IOMS was to hand over identity, company, plan and a
 * password in one form and wait for a payment to clear. Since v2.74.2
 * `/get-started` is account registration and renders none of that; this
 * controller is the ONLY thing that renders the setup form, through two
 * entry points that are the same screen:
 *
 *   "Continue setup"  -- straight after an account is created
 *   "Choose a plan"   -- from the Account area, whenever they decide
 *
 * The page keeps the file name because that is what everyone involved
 * still calls it, and because moving it would churn a route, a test and
 * a component string to fix a word.
 *
 * An earlier cut of this flow built a parallel four-step wizard instead
 * of reusing the form. Two forms selling one product drift: a field gets
 * added to one, a price format corrected in the other, and the version a
 * customer sees depends on which door they came through. There is one.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS CONTROLLER DELIBERATELY DOES NOT DO
 * ---------------------------------------------------------------------
 *
 * It does not create a Tenant, a Company, an Operating Unit or a
 * Subscription. It creates an ORDER -- a `TenantRegistration` -- and
 * hands off to the existing, unchanged payment path:
 *
 *   register.status -> register.checkout -> register.pay
 *                   -> PaymentWebhookController
 *                   -> TenantProvisioningService::activate()
 *
 * That path already enforces the rule this product is built on: nothing
 * that grants access happens before a payment the server itself verified
 * against a signed webhook. Re-implementing any of it here would mean
 * two ways to activate a tenant, and the second one would be weaker.
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
     * Industries offered on the setup form.
     *
     * Read from the public registration controller rather than copied, so
     * the two entry points cannot drift into offering different
     * vocabularies for one field.
     */
    private const INDUSTRIES = \App\Http\Controllers\Public\RegistrationController::INDUSTRIES;

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * The subscription setup page.
     *
     * Reached from "Choose a plan" in the Account area, and from "Continue
     * setup" immediately after an account is created. Both arrive here,
     * because there is one place to set a subscription up.
     */
    public function setup(Request $request): Response|RedirectResponse
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $user = $request->user();
        $plans = $this->pricing->publicPlans();
        $open = $this->openOrder($user);

        /*
         * Which plan to show selected, most specific first:
         *
         *   1. asked for in this URL
         *   2. already chosen by an unfinished order -- coming back here
         *      must not silently reset a decision already made
         *   3. remembered from the plan card the visitor clicked on
         *      Pricing before they had an account at all
         *
         * (3) is what makes "Choose Starter" on the public site survive
         * registration; RegistrationController::create() puts it there.
         */
        $intended = $request->session()->get('intended_plan');

        $requested = (string) $request->query(
            'plan',
            $open?->package?->slug ?? ($intended['plan'] ?? '')
        );

        return Inertia::render('Public/GetStarted', [
            'plans' => $plans,
            // Validated against the real catalog so an arbitrary slug is
            // never echoed back into the page.
            'selectedPlan' => $plans->firstWhere('slug', $requested)['slug'] ?? null,
            'billingCycle' => $this->pricing->normalizeCycle(
                $request->query('cycle', $open?->billing_cycle ?: ($intended['cycle'] ?? null))
            ),
            'industries' => self::INDUSTRIES,
            'contactEmail' => config('ioms.emails.hello'),

            // The signed-in identity, STATED by the page rather than
            // collected. Always present: this form is never rendered to
            // a stranger any more.
            'account' => $this->accountSummary($user),
        ]);
    }

    /**
     * Create the ORDER.
     *
     * Nothing is provisioned here and no money moves. The resulting
     * `TenantRegistration` is a pending order that expires if abandoned.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $user = $request->user();

        $validated = $request->validate([
            /*
             * Note what is NOT here: contact_name, contact_email, password.
             *
             * The page does send the first two and they are IGNORED on
             * purpose -- identity comes from the session below. A validated
             * identity field would be a field somebody can edit, and editing
             * it would raise an order in another person's name.
             */
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
            'logo' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp', 'max:2048'],

            'contact_phone' => ['nullable', 'string', 'max:50'],

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

        /*
         * One live order per account.
         *
         * Returning to setup after abandoning checkout updates the existing
         * order rather than raising a second one -- otherwise a customer who
         * changes their mind about a plan leaves a trail of orphan orders,
         * each holding an invoice.
         */
        $registration = $this->openOrder($user);

        $logoPath = $request->hasFile('logo')
            ? $request->file('logo')->store('uploads/company', 'public')
            : ($registration?->company_logo_path);

        $attributes = [
            ...$validated,
            // `?? null` first: a nullable field the browser did not send is
            // ABSENT from the validated array, not present-and-empty, so
            // reading it directly is an undefined-key error rather than the
            // fallback it looks like. Caught by a test that simply omitted
            // the optional fields, which is what a real form does.
            'company_country' => ($validated['company_country'] ?? null) ?: 'Indonesia',
            'company_logo_path' => $logoPath,

            // Identity, taken from the ACCOUNT. Never from the request.
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
             * public flow exists to prove the address belongs to the person
             * ordering; here that was already established when the account
             * confirmed its address, which `guard()` requires before this
             * method can run. Making them confirm the same address twice
             * would be asking them to prove a thing they have proved.
             */
            'status' => TenantRegistration::STATUS_VERIFIED,
            'email_verified_at' => now(),

            'expires_at' => now()->addDays(7),
            'ip_address' => $request->ip(),
        ];

        // Not columns.
        unset($attributes['terms'], $attributes['plan'], $attributes['logo']);

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

        // The remembered plan card has done its job.
        $request->session()->forget('intended_plan');

        /*
         * Handed to the EXISTING order page, which states what is being
         * bought and for how much, and whose pay button posts to the
         * existing checkout endpoint. It is already the last IOMS-owned
         * screen before the gateway for the public flow; there is no reason
         * for this flow to have a different one.
         */
        return redirect()->route('register.status', $registration->token);
    }

    /**
     * The account's latest unfinished order, if any.
     *
     * Scoped to the account rather than looked up by token: this is used to
     * decide what to pre-select and what to update, never to grant access.
     */
    private function openOrder(User $user): ?TenantRegistration
    {
        return TenantRegistration::where('user_id', $user->id)
            ->whereIn('status', [
                TenantRegistration::STATUS_VERIFIED,
                TenantRegistration::STATUS_AWAITING_PAYMENT,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * The account's own identity, for display on the setup page.
     *
     * Returned rather than rendered into an editable field: the setup form
     * READS these when it is opened by an account, it does not collect them.
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
     * guard called by both entry points rather than repeated checks, so a
     * new one cannot be added without it.
     */
    private function guard(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        // An account that has not confirmed its address cannot buy. This is
        // the one place IOMS actually enforces verification -- see
        // EmailVerificationController on why it is not enforced more broadly.
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('account.overview')->withErrors([
                'email' => 'Please confirm your email address before subscribing. We have sent you a confirmation link.',
            ]);
        }

        // Already has an organization; a second one is not something this
        // flow creates. (Platform admins are excluded by hasNoOrganization().)
        if (! $user->hasNoOrganization()) {
            return redirect()->route('subscription.billing');
        }

        return null;
    }
}

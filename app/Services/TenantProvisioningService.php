<?php

namespace App\Services;

use App\Mail\TenantActivated;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * v2.51.0 -- the ONE place a paid registration becomes a live tenant.
 *
 * This is the authoritative activation event. It is called from exactly
 * one direction: a payment the server itself verified (a signed webhook,
 * or an explicit provider status re-check). It is deliberately NOT
 * reachable from a controller a browser can hit after a redirect -- a
 * customer landing on a success URL proves only that they have a browser.
 *
 * Idempotency is structural, not advisory. `activate()` re-reads the
 * registration inside a locked transaction and returns the existing
 * tenant if one is already attached, so a webhook delivered five times
 * provisions exactly one tenant. That check is inside the same
 * transaction as the writes, which is what makes it safe under two
 * simultaneous deliveries rather than merely unlikely to collide.
 */
class TenantProvisioningService
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    /**
     * Provisions the tenant for a PAID registration.
     *
     * @return Tenant|null the tenant (new or already-existing), or null if
     *                     the registration is not in a state that may be activated
     */
    public function activate(TenantRegistration $registration): ?Tenant
    {
        [$tenant, $isNew] = DB::transaction(function () use ($registration) {
            // Re-read under a row lock: this is the concurrency boundary.
            /** @var TenantRegistration|null $fresh */
            $fresh = TenantRegistration::whereKey($registration->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                return [null, false];
            }

            // Already provisioned -- a duplicate webhook. Return what
            // exists; never build a second tenant for the same customer.
            if ($fresh->tenant_id) {
                return [Tenant::find($fresh->tenant_id), false];
            }

            // Only a payment the server confirmed may activate a tenant.
            if ($fresh->status !== TenantRegistration::STATUS_PAID) {
                return [null, false];
            }

            return [$this->provision($fresh, $this->paidTerms($fresh)), true];
        });

        // Sent only on the transition, so a replayed webhook does not
        // re-email a customer who was activated days ago.
        if ($tenant && $isNew) {
            $this->sendActivationEmail($registration->refresh());
        }

        return $tenant;
    }

    /**
     * v2.93.0 -- THE COMPLIMENTARY GRANT. A verified order becomes a live
     * tenant by an operator's decision instead of by a payment.
     *
     * It is the SAME provisioning body as `activate()`, given different
     * commercial terms. That is the whole design: a complimentary tenant
     * must be indistinguishable from a paying one in every respect except
     * how it is paid for, and the only way to guarantee that is for one
     * piece of code to build both. A second "free provisioning" routine
     * would be a second place for the grant mapping, the company identity
     * copy and the Super Admin attachment to drift.
     *
     * WHAT IT REFUSES, AND WHY EACH REFUSAL MATTERS:
     *
     *   already has a tenant   The tenant exists; making it free is a
     *                          billing-mode change on its subscription, not
     *                          a provision. Provisioning again would be the
     *                          duplicate tenant this must never create.
     *   not verified           The email address is unproven. A grant is a
     *                          decision about a known organization.
     *   already paid           They paid. Converting that to a free grant
     *                          would discard a real payment; the ordinary
     *                          provisioning path owns this case.
     *
     * NO PAYMENT IS SIMULATED ANYWHERE IN HERE. No invoice is raised, none
     * is marked paid, and no payment transaction is written. An invoice the
     * customer was going to settle is VOIDED, because a free account must
     * not leave a live demand for money behind it.
     *
     * @return Tenant|null the new tenant, or null if the registration is not in a grantable state
     */
    public function activateComplimentary(
        TenantRegistration $registration,
        Package $package,
        int $months,
        string $reason,
        User $operator,
    ): ?Tenant {
        $tenant = DB::transaction(function () use ($registration, $package, $months, $reason, $operator) {
            /** @var TenantRegistration|null $fresh */
            $fresh = TenantRegistration::whereKey($registration->getKey())->lockForUpdate()->first();

            if (! $fresh || ! $this->isGrantable($fresh)) {
                return null;
            }

            $starts = now();
            $ends = $starts->copy()->addMonths($months);

            $tenant = $this->provision($fresh, [
                'package' => $package,
                'billing_cycle' => $months >= 12 ? Subscription::CYCLE_YEARLY : Subscription::CYCLE_MONTHLY,
                'billing_mode' => Subscription::BILLING_MODE_COMPLIMENTARY,
                'starts_at' => $starts,
                'ends_at' => $ends,
                // Null on purpose. `agreed_price_*` records what this
                // customer agreed to PAY, and nothing was agreed. Null
                // already means "follow the catalogue" (see
                // Subscription::agreedAmountFor), which is exactly the
                // right behaviour if they later convert to paying.
                'agreed_price_monthly' => null,
                'agreed_price_yearly' => null,
                'agreed_currency' => null,
                'notes' => $reason,
                'created_by' => $operator->id,
                // An unpaid invoice must not be carried into a free
                // account's billing history as an open demand.
                'void_pending_invoice' => true,
            ]);

            ActivityLog::record(
                'updated',
                sprintf(
                    'Complimentary access granted to "%s" (registration %s) by %s: plan %s, %s, %s to %s. Reason: %s',
                    $tenant->name,
                    $fresh->reference,
                    $operator->email,
                    $package->name,
                    $months === 1 ? '1 month' : $months.' months',
                    $starts->toDateString(),
                    $ends->toDateString(),
                    $reason,
                ),
                $tenant,
            );

            return $tenant;
        });

        if ($tenant) {
            $this->sendActivationEmail($registration->refresh());
        }

        return $tenant;
    }

    /**
     * Whether a complimentary grant may provision this registration.
     *
     * Deliberately permissive about the seven-day order expiry: that window
     * exists to stop an abandoned checkout holding an email address
     * forever, and an operator deciding to grant access is the opposite of
     * an abandoned checkout.
     */
    public function isGrantable(TenantRegistration $registration): bool
    {
        return $registration->tenant_id === null
            && $registration->isVerified()
            && in_array($registration->status, [
                TenantRegistration::STATUS_VERIFIED,
                TenantRegistration::STATUS_AWAITING_PAYMENT,
            ], true);
    }

    /** The terms a PAID registration provisions on: whatever the customer bought. */
    private function paidTerms(TenantRegistration $registration): array
    {
        $package = $registration->package;

        $cycle = $registration->billing_cycle === Subscription::CYCLE_MONTHLY
            ? Subscription::CYCLE_MONTHLY
            : Subscription::CYCLE_YEARLY;

        return [
            'package' => $package,
            'billing_cycle' => $cycle,
            'billing_mode' => Subscription::BILLING_MODE_PAID,
            'starts_at' => now(),
            'ends_at' => $cycle === Subscription::CYCLE_MONTHLY ? now()->addMonth() : now()->addYear(),
            // v2.60.0 -- the price this customer actually bought at, taken
            // at the moment of activation. Both cycles are stored so a
            // later monthly<->yearly switch does not silently reprice
            // them. Without this, a future edit to the catalogue would
            // change what an existing customer is billed at renewal --
            // see Subscription::agreedAmountFor().
            'agreed_price_monthly' => $package->price_monthly,
            'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency,
            'notes' => null,
            'created_by' => null,
            'void_pending_invoice' => false,
        ];
    }

    /**
     * Runs inside a transaction. Creates every row a usable tenant needs,
     * in one unit of work, on the commercial terms it is given.
     *
     * `$terms` is the ONLY thing that differs between a paid activation and
     * a complimentary grant. Everything below it -- the company, the grant
     * sync, the Super Admin, the company identity copy -- is identical by
     * construction rather than by two code paths agreeing.
     */
    private function provision(TenantRegistration $registration, array $terms): Tenant
    {
        $package = $terms['package'];

        $tenant = Tenant::create([
            'name' => $registration->displayName(),
            'slug' => $this->uniqueSlug($registration->displayName()),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        // The tenant's first company. Created with the global TenantScope
        // bypassed because the request that triggered provisioning is a
        // gateway webhook with no resolved tenant -- the scope would
        // otherwise fail closed and refuse the insert. tenant_id is set
        // explicitly, so isolation is asserted, not skipped.
        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $registration->displayName(),
            'code' => strtoupper(Str::substr(Str::slug($registration->displayName(), ''), 0, 6)) ?: 'COMP',
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            // Ordinary subscription in both cases, NOT a trial and not a
            // lifetime licence. A complimentary grant has a real end date
            // and lapses on it like any other -- billing mode grants
            // nothing and withholds nothing (ADR 041).
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => $terms['billing_cycle'],
            'billing_mode' => $terms['billing_mode'],
            'starts_at' => $terms['starts_at'],
            'ends_at' => $terms['ends_at'],
            'agreed_price_monthly' => $terms['agreed_price_monthly'],
            'agreed_price_yearly' => $terms['agreed_price_yearly'],
            'agreed_currency' => $terms['agreed_currency'],
            'notes' => $terms['notes'],
            'created_by' => $terms['created_by'],
        ]);

        // The same Package -> Workspace/Module grant mapping
        // PlatformController::storeTenant() uses, so a self-service tenant
        // and an admin-provisioned tenant receive identical entitlements.
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));

        /*
         * v2.74.0 -- ATTACH AN EXISTING ACCOUNT, OR CREATE ONE.
         *
         * The two onboarding flows converge here, and `user_id` is what
         * distinguishes them:
         *
         *   SET   the order was raised by an account that already exists
         *         (Account -> Choose Plan -> Organization -> Payment).
         *         That person is promoted to Super Admin of the tenant
         *         they just paid for. Creating a second user would
         *         duplicate their identity and, because `users.email` is
         *         unique, fail outright.
         *
         *   NULL  the legacy public flow, where there is no account until
         *         payment clears. Unchanged: the user is created here from
         *         the credential captured at registration.
         *
         * Both paths end with exactly one Super Admin attached to exactly
         * one tenant, which is what the rest of the system assumes.
         */
        $admin = $registration->user_id
            ? User::withoutGlobalScopes()->find($registration->user_id)
            : null;

        if ($admin) {
            /*
             * Promotion, not creation. `forceFill` because `tenant_id`
             * and `role` are not mass-assignable on User -- correctly, as
             * they are exactly the fields a request must never set.
             *
             * The email is marked verified if it somehow was not: this
             * account paid, and `SubscribeController` required a confirmed
             * address before it could reach checkout, so an unverified one
             * here would be an inconsistency rather than a state to
             * preserve.
             */
            $admin->forceFill([
                'role' => User::ROLE_SUPER_ADMIN,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'is_active' => true,
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ])->save();
        } else {
            $admin = User::create([
                'name' => $registration->contact_name,
                'email' => $registration->contact_email,
                // Already hashed at registration time -- the plaintext never
                // reached the database and IOMS never emails a credential.
                'password' => $registration->password,
                'role' => User::ROLE_SUPER_ADMIN,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'is_active' => true,
                // v2.74.0: the registration's address WAS confirmed before
                // payment was accepted, so the resulting user is verified.
                // Previously this column was left null on every
                // self-service tenant, which was harmless only because
                // nothing read it.
                'email_verified_at' => $registration->email_verified_at ?? now(),
            ]);
        }

        $this->writeCompanyIdentity($tenant, $registration);

        // The invoice was raised before the tenant existed. Attach it now
        // so the tenant's billing history is complete from its first day.
        if ($registration->invoice_id) {
            $attributes = [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
            ];

            /*
             * A complimentary grant voids the invoice the customer was
             * going to settle. It is NOT marked paid -- nobody paid it --
             * and it is not deleted, because the document was really
             * raised and the void is the honest record of what happened
             * to it. Guarded on being unpaid: a PAID invoice is a
             * historical billing fact and is never rewritten here.
             */
            if ($terms['void_pending_invoice'] ?? false) {
                $invoice = Invoice::withoutGlobalScopes()->find($registration->invoice_id);

                if ($invoice && $invoice->status !== Invoice::STATUS_PAID) {
                    $attributes['status'] = Invoice::STATUS_VOID;
                }
            }

            Invoice::whereKey($registration->invoice_id)->update($attributes);
        }

        $registration->update([
            'status' => TenantRegistration::STATUS_PROVISIONED,
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'provisioned_at' => now(),
        ]);

        ActivityLog::record('created', "Tenant \"{$tenant->name}\" was provisioned from self-service registration {$registration->reference}.");

        // v2.79.0: the platform operator learns that a customer bought
        // something, on the operator's own notification surface.
        app(\App\Services\NotificationService::class)->notifyPlatformAdmins(
            \App\Models\Notification::CATEGORY_INFORMATION,
            'Langganan baru: '.$tenant->name,
            'Organisasi baru aktif dari pendaftaran mandiri '.$registration->reference.'.',
            route('platform.tenants.show', $tenant),
            $tenant,
        );

        return $tenant;
    }

    /**
     * Copies the company identity the prospect supplied into the tenant's
     * OWN company_settings bucket -- the same tenant-scoped store
     * Settings > Company writes and DocumentEngine reads, so a new
     * customer's letterhead is correct on their first generated PDF
     * without anyone re-typing it.
     *
     * CurrentTenant is set for the duration and then restored, because
     * CompanySetting::set() writes to whichever tenant is current, and
     * this runs from a webhook where that is null.
     */
    private function writeCompanyIdentity(Tenant $tenant, TenantRegistration $registration): void
    {
        $previous = $this->currentTenant->get();

        try {
            $this->currentTenant->set($tenant);

            $identity = [
                'company_name' => $registration->displayName(),
                'company_legal_name' => $registration->company_legal_name,
                'company_address' => $registration->company_address,
                'company_city' => $registration->company_city,
                'company_province' => $registration->company_province,
                'company_postal_code' => $registration->company_postal_code,
                'company_country' => $registration->company_country,
                'company_phone' => $registration->company_phone,
                'company_email' => $registration->company_email ?: $registration->contact_email,
                'company_tax_id' => $registration->company_tax_id,
                'company_business_id' => $registration->company_business_id,
                'company_industry' => $registration->company_industry,
                'billing_email' => $registration->billingEmail(),
            ];

            foreach ($identity as $key => $value) {
                if ($value !== null && $value !== '') {
                    CompanySetting::set($key, $value);
                }
            }

            if ($registration->company_logo_path) {
                CompanySetting::set('company_logo_path', $registration->company_logo_path);
            }
        } finally {
            $this->currentTenant->set($previous);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Mail failures must never roll back a paid, provisioned tenant --
     * the customer's account is real whether or not their inbox accepted
     * the message. Logged, not swallowed silently.
     */
    private function sendActivationEmail(TenantRegistration $registration): void
    {
        try {
            Mail::to($registration->contact_email)->send(new TenantActivated($registration));
        } catch (Throwable $e) {
            Log::error('Tenant activation email could not be sent.', [
                'registration' => $registration->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

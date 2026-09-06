<?php

namespace App\Services;

use App\Mail\TenantActivated;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Module;
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

            return [$this->provision($fresh), true];
        });

        // Sent only on the transition, so a replayed webhook does not
        // re-email a customer who was activated days ago.
        if ($tenant && $isNew) {
            $this->sendActivationEmail($registration->refresh());
        }

        return $tenant;
    }

    /** Runs inside activate()'s transaction. Creates every row a usable tenant needs, in one unit of work. */
    private function provision(TenantRegistration $registration): Tenant
    {
        $package = $registration->package;

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

        $cycle = $registration->billing_cycle === Subscription::CYCLE_MONTHLY
            ? Subscription::CYCLE_MONTHLY
            : Subscription::CYCLE_YEARLY;

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => $cycle,
            'starts_at' => now(),
            'ends_at' => $cycle === Subscription::CYCLE_MONTHLY ? now()->addMonth() : now()->addYear(),
        ]);

        // The same Package -> Workspace/Module grant mapping
        // PlatformController::storeTenant() uses, so a self-service tenant
        // and an admin-provisioned tenant receive identical entitlements.
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));

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
        ]);

        $this->writeCompanyIdentity($tenant, $registration);

        // The invoice was raised before the tenant existed. Attach it now
        // so the tenant's billing history is complete from its first day.
        if ($registration->invoice_id) {
            Invoice::whereKey($registration->invoice_id)->update([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
            ]);
        }

        $registration->update([
            'status' => TenantRegistration::STATUS_PROVISIONED,
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'provisioned_at' => now(),
        ]);

        ActivityLog::record('created', "Tenant \"{$tenant->name}\" was provisioned from self-service registration {$registration->reference}.");

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

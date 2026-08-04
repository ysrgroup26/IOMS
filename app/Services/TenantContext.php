<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

/**
 * Multi-Tenant Foundation (Epic 3). Deliberately built as a thin context
 * layer on top of the EXISTING `Company` model rather than a new,
 * parallel "Tenant" model -- every major resource (Employee, Project,
 * Department, KpiCategory, ReportConfiguration...) already scopes to
 * `company_id`, and introducing a second, redundant tenant concept would
 * mean either duplicating that structure or migrating dozens of
 * `company_id` columns to a new `tenant_id`, both a much larger and
 * riskier undertaking than this session's scope, and not something
 * "modify only if required" supports doing casually.
 *
 * What was actually missing: a single, resolved place to answer "what
 * tenant is this request operating as," instead of every controller
 * independently reading `$request->input('company_id')` or
 * `auth()->user()->company_id` by hand. This class is that single place
 * -- `IdentifyTenant` middleware resolves it once per request and binds
 * it into the container; anything downstream (controllers, policies, a
 * future automatic scoping layer) can inject/resolve `TenantContext`
 * instead of re-deriving the same value differently in different places.
 *
 * Deliberately NOT included in this pass: automatically applying tenant
 * scoping to every Eloquent query via a global scope. Doing that
 * retroactively across every existing model/controller in one sweep is
 * exactly the kind of large, high-risk change to already-working modules
 * this session's rules caution against -- see ROADMAP.md for the planned
 * follow-up once this context layer has been exercised in practice.
 */
class TenantContext
{
    private ?Company $tenant = null;

    private bool $resolved = false;

    /**
     * Resolves once per request and caches the result. A Super Admin
     * with no company filter selected has no single tenant (they operate
     * across all companies, matching the existing Dashboard/Settings
     * "All Companies" pattern already in the app) -- resolve() returns
     * null in that case rather than guessing one.
     */
    public function resolve(?User $user, ?int $requestedCompanyId = null): ?Company
    {
        if ($this->resolved) {
            return $this->tenant;
        }

        $this->resolved = true;

        if (! $user) {
            return $this->tenant = null;
        }

        // An explicit company filter (Super Admin switching context via
        // the existing company_id query param already used across
        // Dashboard/PPE/Reports) takes priority when present and valid.
        if ($requestedCompanyId && $user->isSuperAdmin()) {
            $this->tenant = Company::find($requestedCompanyId);

            return $this->tenant;
        }

        // A non-super-admin user's tenant is simply their own company,
        // if they're tied to one -- most users in this app are.
        if ($user->company_id) {
            $this->tenant = Company::find($user->company_id);
        }

        return $this->tenant;
    }

    public function current(): ?Company
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}

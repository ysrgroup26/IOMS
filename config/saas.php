<?php

/**
 * v1.11.0 (SaaS Finalization Pass). Platform-wide SaaS/billing switches --
 * NOT tenant data itself (Package/Subscription/Invoice rows are), this is
 * operational configuration for the platform operator running this
 * install.
 */
return [

    /**
     * v1.11.1 (Final Production Readiness Pass, Part 15): now defaults
     * TRUE -- safe to do so after `Subscription::isBlocked()` was
     * redefined to hard-block ONLY on an explicit `suspended`/`cancelled`
     * status (always the result of a deliberate Platform Admin action),
     * never on an expired-by-date or missing Subscription row. Those two
     * cases are surfaced as a "degraded" warning instead (see
     * EntitlementService::tenantIsDegraded()) rather than a block, which
     * is what makes it finally safe to enable by default -- a stale
     * SubscriptionSeeder-computed date can no longer lock anyone out.
     * Still overridable via SAAS_ENFORCE_ENTITLEMENT=false in .env if a
     * specific install needs it fully off.
     */
    'enforce_entitlement' => env('SAAS_ENFORCE_ENTITLEMENT', true),

    /**
     * v1.11.15 (SaaS Package + Ecosystem pass, Part 26/27): a genuine
     * gap found by auditing, not assumed -- `EntitlementService::
     * tenantCanUseModule()`/`tenantCanUseWorkspace()` (the per-tenant
     * Module/Workspace grant check, backing the existing Platform Admin
     * "Tenant Grants" UI) were fully implemented but never actually
     * CALLED anywhere in the request lifecycle -- confirmed via a
     * whole-codebase grep, not guessed. `EnforceTenantEntitlement` only
     * ever checked subscription usability (active/suspended), never
     * per-workspace grants, so every tenant regardless of Package could
     * reach every department, gated only by role (isHse()/isHrd()/etc.).
     * Wired into that same middleware this pass (see its own doc
     * comment) -- but defaulted OFF here, separate from
     * `enforce_entitlement` above, because this environment has no way
     * to verify the currently-live production tenant's own Module/
     * Workspace grant rows are actually populated correctly before
     * flipping on a NEW enforcement path that could otherwise lock out
     * the one real tenant currently using this system. Enable via
     * SAAS_ENFORCE_WORKSPACE_ENTITLEMENT=true once a Platform Admin has
     * confirmed (via Tenant Grants) that the existing tenant's grants
     * cover what it actually uses -- `TenantGrantSeeder` already grants
     * the default tenant everything, so this is very likely already
     * safe to enable, just not verifiable from here.
     */
    'enforce_workspace_entitlement' => env('SAAS_ENFORCE_WORKSPACE_ENTITLEMENT', false),

    /**
     * Default currency for new Packages/Invoices when none is specified.
     * Purely a UI/creation default, never validated against -- an
     * invoice can be issued in any currency string.
     */
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'IDR'),

];

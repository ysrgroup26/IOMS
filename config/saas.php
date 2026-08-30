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
     * comment).
     *
     * v2.13.0 (SaaS Phase 1 -- Subscription Architecture & Entitlement
     * Enforcement): flipped to default TRUE this pass -- Part 9 of that
     * phase's own directive makes real backend enforcement the P0
     * deliverable ("A Starter tenant must NOT be able to call a
     * Professional/Enterprise endpoint simply by knowing its URL"), and
     * two safety nets now make this safe to enable without live DB
     * verification (which remained unavailable in this environment,
     * same as every prior pass):
     *   1. `EntitlementService::tenantCanUseModule()`/
     *      `tenantCanUseWorkspace()` now treat a tenant with ZERO grant
     *      rows as fully allowed (see that class's own doc comment) --
     *      the exact "legacy tenant predates this feature" case is now
     *      structurally incapable of being locked out, rather than
     *      hoping its grants happen to be complete.
     *   2. The new `php artisan tenants:sync-grants` command (additive
     *      only, `--dry-run` supported) tops up a PARTIALLY-granted
     *      tenant (e.g. one seeded by `TenantGrantSeeder` before a newer
     *      Workspace/Module was added to the app) back up to its
     *      Package's own baseline -- run this once after deploying this
     *      change (dry-run first) to confirm zero unexpected
     *      restrictions before relying on it in production.
     * Still overridable via SAAS_ENFORCE_WORKSPACE_ENTITLEMENT=false in
     * .env if a specific install needs it off.
     */
    'enforce_workspace_entitlement' => env('SAAS_ENFORCE_WORKSPACE_ENTITLEMENT', true),

    /**
     * Default currency for new Packages/Invoices when none is specified.
     * Purely a UI/creation default, never validated against -- an
     * invoice can be issued in any currency string.
     */
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'IDR'),

];

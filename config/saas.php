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
     * Default currency for new Packages/Invoices when none is specified.
     * Purely a UI/creation default, never validated against -- an
     * invoice can be issued in any currency string.
     */
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'IDR'),

];

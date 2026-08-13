<?php

/**
 * v1.11.0 (SaaS Finalization Pass). Platform-wide SaaS/billing switches --
 * NOT tenant data itself (Package/Subscription/Invoice rows are), this is
 * operational configuration for the platform operator running this
 * install.
 */
return [

    /**
     * Whether `App\Http\Middleware\EnforceTenantEntitlement` actually
     * blocks access for a tenant whose Subscription is unusable (expired/
     * suspended/cancelled). Defaults false -- see that middleware's own
     * doc comment for the specific deploy-safety reasoning (stale seeded
     * subscription dates could otherwise lock out an existing production
     * tenant the moment this ships). Flip via SAAS_ENFORCE_ENTITLEMENT=true
     * in .env only after confirming the real tenant's Subscription record
     * (Platform → Tenants → [tenant] → Subscription) has correct,
     * current dates/status.
     */
    'enforce_entitlement' => env('SAAS_ENFORCE_ENTITLEMENT', false),

    /**
     * Default currency for new Packages/Invoices when none is specified.
     * Purely a UI/creation default, never validated against -- an
     * invoice can be issued in any currency string.
     */
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'IDR'),

];

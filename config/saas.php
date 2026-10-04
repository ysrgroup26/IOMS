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

    /*
    |--------------------------------------------------------------------
    | Subscription lifecycle (v2.70.0)
    |--------------------------------------------------------------------
    | How long after a period ends a customer keeps FULL access before
    | dropping to read-only. It is a business decision, not a technical
    | one, so it is configuration rather than a constant.
    |
    | SEVEN DAYS, decided by the product owner in v2.80.0. Fourteen was a
    | placeholder chosen when nothing had been decided; seven is long
    | enough to cross a weekend, a bank transfer and one internal approval
    | step, and short enough that a lapse still means something. Cutting
    | writes off the morning after a period ends would punish a customer
    | who is paying, which is the opposite of what the lapse is for.
    |
    | Setting it to 0 makes writes stop the moment the period ends. Reads
    | are NEVER withdrawn by the passage of time at any setting -- see
    | docs/ADR/033-subscription-lifecycle.md.
    */
    'grace_days' => (int) env('SAAS_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------
    | Additional active users (v2.82.0)
    |--------------------------------------------------------------------
    | Rp50.000 per additional ACTIVE user per month, and deliberately
    | ONE price for every plan.
    |
    | Per-plan add-on pricing was considered and rejected: an extra
    | account is the same thing on Starter as on Business, and charging
    | more for it at the top would make an upgrade read as a penalty for
    | the customers who had grown.
    |
    | It lives here rather than on `packages` for the same reason: a
    | column would invite four different answers to a question that has
    | one. If that decision is ever reversed, the column is the change --
    | not a second config key beside this one.
    |
    | A USER is an active login account. Not a device (one account may
    | sign in from several), and not an employee record (an employee
    | without a login is not a user). A deactivated account frees its
    | slot. See App\Services\EntitlementService::usersUsedCount().
    */
    'additional_user_price' => (float) env('SAAS_ADDITIONAL_USER_PRICE', 50000),

    /*
    |--------------------------------------------------------------------
    | Additional My Work capacity, sold in PACKS (v2.86.0)
    |--------------------------------------------------------------------
    | Rp100.000 per pack of ten My Work users per month.
    |
    | Sold as a pack rather than per user on purpose, and the two add-ons
    | are therefore priced in different units: a Full User is bought one at
    | a time because each one is a named person with real authority, and My
    | Work capacity is bought in tens because a crew arrives in tens. A
    | surface quoting these must say the unit, or Rp100.000 reads as the
    | price of one account.
    */
    'my_work_pack_size' => (int) env('SAAS_MY_WORK_PACK_SIZE', 10),
    'my_work_pack_price' => (float) env('SAAS_MY_WORK_PACK_PRICE', 100000),

    /*
    |--------------------------------------------------------------------
    | PTW top-up packs (v2.86.0)
    |--------------------------------------------------------------------
    | One-off purchases. Quota carries forward until consumed and is not
    | refundable, so these are priced per document with a discount for
    | volume: Rp1.000, Rp800, Rp600.
    |
    | Keyed by document count so a purchase request names a PACK rather than
    | an amount. The browser sends the key; the price is read here. Nothing
    | in the purchase path ever trusts an amount that arrived in a request,
    | which is the same rule the subscription checkout already follows.
    */
    'ptw_topup_packs' => [
        50 => ['documents' => 50, 'price' => 50000.0],
        150 => ['documents' => 150, 'price' => 120000.0],
        500 => ['documents' => 500, 'price' => 300000.0],
    ],

    /*
    |--------------------------------------------------------------------
    | When to warn a customer that PTW capacity is running low
    |--------------------------------------------------------------------
    | A fraction of the tenant's own total available quota. At or below
    | this, surfaces show a warning rather than a plain figure.
    */
    'ptw_low_quota_threshold' => (float) env('SAAS_PTW_LOW_QUOTA_THRESHOLD', 0.2),


    /*
    | How many days before a period ends the renewal invoice is issued --
    | the reminder the customer receives. SEVEN (H-7), decided alongside
    | the grace window above so the two numbers read as one policy: the
    | customer is told a week before, and has a week afterwards. Long
    | enough to pay inside their own process, short enough that it is
    | obviously about the period they are in.
    */
    'renewal_lead_days' => (int) env('SAAS_RENEWAL_LEAD_DAYS', 7),

    /*
    | How long an unpaid renewal invoice stays payable before it is
    | considered overdue. Purely presentational -- nothing voids an
    | invoice automatically, because an invoice a customer is slowly
    | getting approved internally is not a mistake to clean up.
    */
    'invoice_due_days' => (int) env('SAAS_INVOICE_DUE_DAYS', 14),

    /*
    | v2.93.0 -- COMPLIMENTARY ACCESS: THE DURATIONS AN OPERATOR MAY GRANT.
    |
    | A closed allow-list, not a minimum and a maximum. Master Admin picks
    | from these and the server accepts nothing else, so a free grant can
    | never be for an arbitrary length -- a mistyped duration is a rejected
    | request rather than a decade of free service nobody notices.
    |
    | Twelve is the ceiling on purpose. A complimentary arrangement meant to
    | outlive a year is a different commercial decision, and it should be
    | re-made deliberately at the end of the period rather than granted once
    | and forgotten. Extending is the existing subscription edit, which is
    | already audited.
    |
    | A genuinely perpetual free account is NOT expressed here: that is
    | `type = lifetime`, which has no period to run out (ADR 041).
    */
    'complimentary_durations' => [1, 3, 6, 12],

];

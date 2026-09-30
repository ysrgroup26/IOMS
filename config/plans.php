<?php

use App\Models\Workspace;

/**
 * v2.60.0 -- THE PLAN CATALOGUE, IN ONE PLACE.
 *
 * What a plan COSTS lives in the `packages` table (a Platform Admin edits
 * it, and a migration sets it). What a plan GRANTS lived in a hardcoded
 * `match` inside Package, with a `default => []` arm — so a plan created
 * through the admin UI, or one whose slug was mistyped, silently resolved
 * to ZERO departments while still having a price. That is the exact shape
 * of the v2.58.0 empty-sidebar defect, one layer up.
 *
 * This file replaces that match. It is configuration rather than a table
 * because a plan's SCOPE is a product decision that ships with the code —
 * the department a tier includes changes in a release, not from an admin
 * form — and keeping it here means adding a fifth tier is one entry plus a
 * migration, with no new schema.
 *
 * THE PROGRESSION IS OPERATIONAL COMPLEXITY, NOT MODULE COUNT.
 *
 *   Starter       Digitalize HSE
 *   Professional  HSE + Workforce
 *   Business      Cross-functional operational visibility
 *   Enterprise    Full IOMS
 *
 * TWO THINGS DELIBERATELY ABSENT FROM EVERY TIER LIST:
 *
 *   `reports` and `administration` are GLOBAL-tier workspaces — the
 *   application's own chrome (Reports, Analytics, Report Center, Settings,
 *   Users, Audit Logs). No plan may withhold them; `Package::
 *   defaultWorkspaceKeys()` unions them in for every tier. Listing them
 *   here would make them look sellable, which is what broke v2.58.0.
 *
 *   `warehouse` and `finance` are SHELL workspaces — a Dashboard and an
 *   Overview and nothing else. The real warehouse capability (items,
 *   stock, warehouses, goods receipt) lives under `logistics`, which is
 *   why Business buys Logistics / PPIC and not "Warehouse". They are
 *   granted only at Enterprise, and are never named as a tier's selling
 *   point.
 */
return [

    /*
    |--------------------------------------------------------------------
    | Department workspaces granted by each plan
    |--------------------------------------------------------------------
    | Keys are `packages.slug`. Values are `workspaces.key` values of
    | tier `department`. Enterprise is resolved dynamically so a newly
    | added department is included without editing this file.
    */
    /*
    | v2.82.0 -- THE APPROVED THREE-TIER SCOPE.
    |
    | Business narrows: Project Management and Procurement leave the sold
    | scope, and Warehouse joins Logistics so the tier is described the
    | way it is sold -- "Logistics / Warehouse", one operational domain.
    | Warehouse is therefore no longer a shell (see `shells` below).
    |
    | Enterprise keeps its entry because tenants are still subscribed to
    | it. It is retired from SALE (`is_public = false`), not deleted --
    | removing it here would strip the departments those customers
    | already have.
    |
    | v2.83.0 -- `management` joins Business, and it is the reason ADR 043
    | left an open question rather than inventing an answer. The approved
    | scope has always read "... + Management"; there was no such
    | workspace, and granting a key that does not exist is the v2.58.0
    | empty-sidebar defect. The capability is now BUILT (ADR 044), so the
    | word finally names something. Enterprise picks it up through `*`.
    */
    'workspaces' => [
        'starter' => ['hse'],
        'professional' => ['hse', 'hr'],
        'business' => ['hse', 'hr', 'logistics', 'management'],
        // v2.84.0: Enterprise is no longer '*'. A retired plan must not
        // be the one place a department nobody sells can still be
        // granted -- that is how Procurement, Maintenance and Quality
        // Control stayed reachable after the catalogue narrowed. It now
        // grants exactly what Business does; its remaining difference is
        // capacity, not scope.
        'enterprise' => ['hse', 'hr', 'logistics', 'management'],
    ],

    /*
    |--------------------------------------------------------------------
    | Module grants
    |--------------------------------------------------------------------
    | Deliberately narrower than the workspace layer -- see
    | config/modules.php: most HSE functionality has no Module key at all
    | and is gated by the workspace grant plus a role check. Business adds
    | the modules its new departments actually use.
    */
    'modules' => [
        'starter' => ['employees', 'ppe', 'kpi_input', 'reports'],
        'professional' => ['employees', 'ppe', 'kpi_input', 'reports'],
        // Material Request spans Logistics and Warehouse, so it stays.
        'business' => ['employees', 'ppe', 'kpi_input', 'reports', 'material_requests'],
        // v2.84.0: narrowed with the workspace grant above, for the same
        // reason -- a module whose workspace is not sold has nowhere to
        // be reached from.
        'enterprise' => ['employees', 'ppe', 'kpi_input', 'reports', 'material_requests'],
    ],

    /*
    |--------------------------------------------------------------------
    | What an UNKNOWN slug resolves to
    |--------------------------------------------------------------------
    | A paid plan must never silently receive zero departments. An
    | unrecognised slug therefore falls back to the ENTRY TIER rather than
    | to nothing: the customer gets a working, clearly-bounded product and
    | the operator sees an obviously wrong plan, instead of a signed-up
    | customer staring at an application with no navigation.
    |
    | This is the same fail-open direction EntitlementService already takes
    | for an ungranted tenant, not a second, contradictory policy.
    */
    'fallback' => 'starter',

    /*
    |--------------------------------------------------------------------
    | Shell workspaces -- granted, but never advertised
    |--------------------------------------------------------------------
    | v2.61.0. `warehouse` and `finance` are a Dashboard and an Overview
    | and nothing else. Enterprise really does receive them and they stay
    | in every entitlement answer -- this list ONLY removes them from the
    | marketing copy, so a pricing card never presents an empty room as a
    | reason to buy the tier.
    |
    | The real warehouse capability (Item Master, Inventory, Goods
    | Receipt, Stock Movement) lives under `logistics`, which is why
    | Business buys Logistics / PPIC and not "Warehouse".
    |
    | When one of these grows into a real workspace, delete it from this
    | list -- nothing else has to change.
    |
    | v2.82.0: `warehouse` is no longer listed here. Business sells
    | "Logistics / Warehouse" as ONE domain, so naming Warehouse in the
    | card describes what the customer buys rather than advertising an
    | empty room -- the stock, goods-receipt and movement capability it
    | fronts is granted with it.
    */
    'shells' => [],

    /*
    |--------------------------------------------------------------------
    | Positioning line for each tier
    |--------------------------------------------------------------------
    | The one-line answer to "who is this tier for". Kept beside the scope
    | it describes so the two cannot drift, and served through
    | PricingService so every pricing surface says the same thing rather
    | than each page keeping its own copy.
    */
    'positioning' => [
        'starter' => 'Digitalize HSE',
        'professional' => 'HSE + Workforce',
        'business' => 'Operations end to end',
        'enterprise' => 'Full IOMS',
    ],

    /*
    |--------------------------------------------------------------------
    | Included active users, for the PRICING COPY only (v2.82.0)
    |--------------------------------------------------------------------
    | The authoritative allowance is `packages.max_users`, which the
    | entitlement layer reads and an operator can override per tenant.
    | This list exists so a pricing surface can state the allowance for a
    | tier it is describing without a database row -- and a test asserts
    | the two agree, so it cannot become a second answer.
    */
    'included_users' => [
        'starter' => 3,
        'professional' => 10,
        'business' => 25,
    ],

    /*
    |--------------------------------------------------------------------
    | Included My Work users, for the PRICING COPY only (v2.86.0)
    |--------------------------------------------------------------------
    | The same arrangement as `included_users` directly above, for the same
    | reason: the authoritative allowance is `packages.max_my_work_users`,
    | which the entitlement layer reads and an operator can see, and this
    | list exists so a pricing surface can state the allowance for a tier it
    | is describing without a database row. A test asserts the two agree, so
    | it cannot become a second answer.
    */
    'included_my_work_users' => [
        'starter' => 10,
        'professional' => 30,
        'business' => 50,
    ],

    /*
    |--------------------------------------------------------------------
    | Included PTW documents per MONTH (v2.86.0)
    |--------------------------------------------------------------------
    | Mirrors `packages.ptw_included_monthly`, pinned by the same test.
    |
    | PER MONTH ON EVERY BILLING CYCLE. An annual subscription does not
    | receive twelve times this figure on day one; it receives this figure
    | twelve times, once per monthly window. The distinction is the whole
    | reason PtwQuotaService anchors windows to the subscription start day
    | rather than to the invoice.
    */
    'ptw_included_monthly' => [
        'starter' => 50,
        'professional' => 200,
        'business' => 500,
    ],

    /*
    |--------------------------------------------------------------------
    | How many included PTW allocations one ANNUAL term may receive
    |--------------------------------------------------------------------
    | Twelve. Business annual is sold as fourteen months of platform ACCESS
    | for twelve months of payment, and the approved model is explicit that
    | the extra access does not create extra PTW entitlement -- the usage
    | entitlement stays on the normal twelve billing periods.
    |
    | A monthly subscription is not capped by this: every month is separately
    | paid for, so every month earns its allocation.
    */
    'ptw_annual_allocations' => 12,


    /*
    |--------------------------------------------------------------------
    | The customer-facing operational workspaces -- ALL of them (v2.84.0)
    |--------------------------------------------------------------------
    | IOMS sells FOUR operational workspaces and nothing else. This list
    | is what makes that true rather than aspirational: the navigation
    | layer intersects every answer with it, so a workspace that is not
    | named here cannot appear in a sidebar, a switcher or a plan card no
    | matter what a grant row, a stale catalogue row or an unprovisioned
    | tenant's fail-open default would otherwise allow.
    |
    | It exists because `EntitlementService::grantedWorkspaceKeys()`
    | deliberately treats "no grant rows recorded" as UNRESTRICTED (the
    | v2.13.0 safety net that makes enforcement safe to switch on). That
    | is right for ACCESS and wrong for NAVIGATION: it meant a legacy
    | tenant was offered Procurement, Maintenance and Quality Control --
    | departments nobody sells -- in the department selector.
    |
    | Administration (Admin Space) and Reports are NOT here. They are
    | global-tier chrome, never sold, and never offered as a department.
    |
    | Procurement, Project Management, Asset Management, Maintenance,
    | Quality Control, Finance and the standalone Warehouse shell are
    | deliberately absent. Their code, routes and data are untouched --
    | they are simply not customer-facing. See ADR 045.
    */
    'operational' => ['hse', 'hr', 'logistics', 'management'],

    /*
    |--------------------------------------------------------------------
    | Which plans include the GLOBAL COMPANY DASHBOARD (v2.84.0)
    |--------------------------------------------------------------------
    | The cross-workspace company snapshot is a BUSINESS capability, and
    | it is the one part of IOMS that is sold by plan without being a
    | workspace -- so it cannot be expressed as a workspace grant and
    | needed somewhere honest to live.
    |
    | The reasoning is product, not technical: a company-wide dashboard is
    | only meaningful when a customer has more than one operational area
    | AND the tier that sells cross-functional visibility. Starter has one
    | workspace, so a "company overview" would be the HSE Overview with a
    | different title. Professional has two, and summarising two areas is
    | what the two Overviews already do.
    |
    | A plan not listed here does not get a locked page or an upsell
    | screen -- `/dashboard` simply redirects to where that customer
    | actually works. A paywall on the landing route would greet a Starter
    | customer with a refusal every time they sign in.
    */
    'global_dashboard' => ['business', 'enterprise'],

    /*
    |--------------------------------------------------------------------
    | The recommended tier
    |--------------------------------------------------------------------
    | v2.27.0 refused to print "Most Popular" because no such signal
    | existed in the data and the pass's own rule was "if unsure, do not
    | invent". That reasoning was right, and the answer is not to keep
    | guessing in the UI -- it is to put the signal where the UI can read
    | it honestly.
    |
    | Business earns it on the structure of the ladder rather than as
    | decoration: it is the largest scope jump in the catalogue (three
    | departments) at the middle price, which is the tier most
    | organizations running more than one site will land on. Set to null to
    | remove the emphasis entirely.
    */
    'popular' => 'business',

];

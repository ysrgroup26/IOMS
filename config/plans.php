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
    'workspaces' => [
        'starter' => ['hse'],
        'professional' => ['hse', 'hr'],
        'business' => ['hse', 'hr', 'project-management', 'logistics', 'procurement'],
        // Every department that exists, resolved at call time.
        'enterprise' => '*',
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
        'business' => ['employees', 'ppe', 'kpi_input', 'reports', 'projects', 'daily_reports', 'material_requests'],
        'enterprise' => '*',
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
        'business' => 'Cross-functional operational visibility',
        'enterprise' => 'Full IOMS',
    ],

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

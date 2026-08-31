<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IOMS Application Configuration
    |--------------------------------------------------------------------------
    |
    | Single source of truth for version/release/developer information
    | shown throughout the UI -- About dialog, sidebar footer, login
    | footer, and the Home page "What's New" banner all read this via the
    | shared Inertia prop (see HandleInertiaRequests::share()). Bump
    | `version` and update `release_date` + `whats_new` together whenever
    | a release ships, and add the previous release to `version_history`.
    | Nothing else in the codebase needs to change.
    |
    */

    'version' => '2.22.0',

    // Tester / Beta / Stable -- tracks the release stage explicitly.
    // Previously only implied in conversation, never actually stored.
    'stage' => 'Beta',

    'edition' => 'Enterprise Edition',

    'build' => '2026.08.31.8',

    'release_date' => '2026-08-31',

    'developer' => 'Yofhanza Shultona Rizqi S.',

    'company' => 'YSR Systems',

    'copyright_year' => '2026',

    'license' => 'Commercial Enterprise License',

    'website' => 'www.iomsplatform.com',

    'support_email' => 'support@ioms.local',

    'documentation_url' => 'docs.iomsplatform.com',

    'whats_new' => [
        'New mobile bottom navigation bar -- on phones, the app now has a fixed Home / (your top modules) / More bar instead of only a hamburger menu, with "More" opening the full menu you already know',
        'Fixed the browser tab and app name showing a stale pre-rebrand name in some environments -- IOMS is now shown as the product name consistently',
        'Incident list is easier to scan -- consolidated into fewer, grouped columns on desktop',
    ],

    /*
    |--------------------------------------------------------------------------
    | Version History
    |--------------------------------------------------------------------------
    |
    | Shown on the About page below "What's New" as a scrollable release
    | history. Newest first. Kept intentionally short (a one-line summary
    | per release) -- CHANGELOG.md remains the detailed record.
    |
    */

    'version_history' => [
        ['version' => '2.22.0', 'date' => '2026-08-31', 'summary' => 'Complete Product UI/UX Transformation pass: new fixed mobile bottom navigation bar (Home + up to 3 authorized items + More, reusing the exact same RBAC/department-filtered nav array and drawer the sidebar already uses -- no second permission system); "IOMS" restored as the primary product name in the browser tab title, app config fallback, and public site footer (was drifting toward the full "Integrated Operations Management System" expansion, and this environment\'s own .env had a stale pre-rebrand "Shipyard Management System" title); Incidents list got the same identity-first table consolidation PTW Index introduced last pass. No business logic, RBAC, tenant isolation, SaaS entitlement, or database changes.'],
        ['version' => '2.21.0', 'date' => '2026-08-31', 'summary' => 'Final Visual Polish pass (P0 scope): PTW Index desktop table consolidated from 9 equal-weight columns to 6 identity-first cells (Permit = number+type, Project/Location, Requester/PIC, each a two-line grouped unit) -- no data dropped, real visual hierarchy instead. Filter bar unboxed from its Card. Presentation only. Broader multi-module polish (Dashboard/HSE/PPE/etc.) intentionally left for a follow-up pass given this pass\'s own P0-first priority and proportionate scope.'],
        ['version' => '2.20.0', 'date' => '2026-08-31', 'summary' => 'PTW Experience & Visual Polish pass: redesigned PTW Document/PDF around a numbered information architecture (01 Work Information -> 05 Supporting Documents) with a dominant document-title block and PIC/Workforce shown via a new reusable PersonChip avatar component instead of plain text; PTW Show page gained a more prominent identity/status header; PTW Create form gained numbered step badges. Presentation only -- no schema, workflow, RBAC, tenant isolation, SaaS entitlement, or quota changes. Same PdfGeneratorService/DocumentEngine pipeline, no new PDF engine.'],
        ['version' => '2.19.0', 'date' => '2026-08-31', 'summary' => 'PTW Access Management Correction pass: fixed settings.users.ptw-access being gated role:super_admin only at the route level -- STRICTER than SettingsController::updatePtwAccess()\'s own canManageHse() check, meaning HSE could never actually reach it despite the controller already allowing it. Moved to its own role:super_admin,hse route group. Settings > Users tab now opens for HSE too, but split into two independent cards -- User Management (create/edit/delete/role changes) stays Super-Admin-only; Field & PTW Access (toggle + quota) opens to HSE. Requester/PIC/Workforce/quota logic re-audited and confirmed already correct, no changes needed. No HSE Dashboard, Field Home, PTW workflow, or database changes.'],
        ['version' => '2.18.0', 'date' => '2026-08-31', 'summary' => 'Public Website / Landing Page Foundation: `/` is now a genuinely public route (previously redirected straight to /login for anyone not signed in) showing a real product website -- hero, platform capabilities, PTW-to-HSE workflow story, Field/HSE/People/Data sections, industries, FAQ, and a Pricing section driven live from Package data (never a hardcoded price). New PublicLayout (separate from AuthenticatedLayout, no sidebar). No customer names/logos/counts/testimonials invented anywhere. Authenticated users hitting `/` are still redirected into the app exactly as before. No RBAC, tenant isolation, PTW workflow, or database changes.'],
        ['version' => '2.17.1', 'date' => '2026-08-31', 'summary' => 'PTW Field Workflow Verification & Correction pass: fixed the max_users(10)/max_ptw_users(15) contradiction on Starter by raising max_users to 15, and PlatformController::validatePlan() now server-enforces max_ptw_users <= max_users for any future Plan edit; PTW Index (HSE workspace) now shows Project/PIC/Workforce headcount on desktop and mobile, via N+1-free eager loading. Re-audited Requester/PIC/Workforce/security -- all confirmed correct, no changes needed there. No HSE Dashboard, Field Home scope, PTW state machine, pricing, billing, or checkout changes.'],
        ['version' => '2.17.0', 'date' => '2026-08-31', 'summary' => 'PTW Field Workflow Foundation + Controlled PTW Access: new per-user "PTW Access" grant (users.ptw_access) lets specific non-HSE Field/Operations users create PTWs, capped by a new package-level PTW user quota (packages.max_ptw_users, server-enforced with race-safe locking); Field Home\'s Create PTW tile now reflects this instead of HSE-role-only; PTW gained optional PIC/Supervisor and Workforce (real Employee references, tenant-scoped) shown consistently on Show/Document/PDF. Requester was already correctly server-derived -- confirmed, not changed. No HSE Dashboard, PTW state machine, RBAC, tenant isolation, or subscription state machine changes.'],
        ['version' => '2.16.0', 'date' => '2026-08-31', 'summary' => 'Global Mobile UX Hardening pass: fixed ModuleTabNav (PPE\'s tab bar) causing page-level horizontal scroll on mobile -- the root cause of a reported PPE screenshot regression -- by containing the scroll to the tab row itself; fixed two PPE list rows (Employees, Replacement Due) whose fixed-width columns exceeded narrow viewports; sidebar drawer now closes explicitly on mobile nav-item tap. Audited broadly (tabs, tables, dialogs, grids, fixed widths) -- most tables were already safely self-scrolling via the shared Table component\'s own wrapper, so no table changes were needed. No business logic, HSE Dashboard, Field Home, PTW workflow, RBAC, tenant isolation, subscription, or database changes.'],
        ['version' => '2.15.0', 'date' => '2026-08-30', 'summary' => 'Product UI/UX Finalization: fixed the dialog component app-wide so tall forms no longer clip off-screen on mobile (max-h + internal scroll + safe margin, previously only one page had opted in); PageHeader now stacks title-above-actions on mobile instead of squeezing both onto one row; added a warning Badge variant so "Overdue"/"High priority" read distinctly from "Rejected"/"Critical"; Incident list converted to the proven PTW mobile-card pattern. Audit-first, shared-components-first pass -- no business logic, dashboard widgets, sidebar structure, or Field Home scope changed.'],
        ['version' => '2.14.0', 'date' => '2026-08-30', 'summary' => 'SaaS Productization / Pricing Foundation: Package confirmed as the canonical Plan entity (currency/trial_days/is_public/is_custom added, no new plans table); new PricingService is the single source of formatted plan pricing (never hardcoded in a component); new tenant-facing Plans comparison page; trial-days-to-trial_ends_at wiring; upgrade/downgrade and billing-ready architecture documented for a later phase. No checkout, payment gateway, or final pricing decision -- explicitly out of scope.'],
        ['version' => '2.13.0', 'date' => '2026-08-30', 'summary' => 'SaaS Phase 1 -- Subscription Architecture & Entitlement Enforcement: server-side per-workspace entitlement enforcement enabled by default (previously built, never wired on); a tenant with no grant rows is now safely treated as fully allowed instead of fully denied, so this cannot lock out a pre-existing tenant; new `tenants:sync-grants` command additively tops up a partially-granted tenant to its Package baseline; entitlement-denied messages now in Bahasa Indonesia. No new table, no payment/billing/pricing/checkout work (explicitly out of scope for this phase).'],
        ['version' => '2.12.0', 'date' => '2026-09-07', 'summary' => 'Product Finalization pass: fixed three real cross-tenant leaks found by a fresh security audit (Material Request full IDOR across 9 controller methods, PPE Replacement Request view/PDF IDOR, KpiRecordController default-state full leak), fixed a hardcoded-branding PDF footer, added two missing delete confirmations, improved the Employees empty state. No database change, no new route.'],
        ['version' => '2.11.0', 'date' => '2026-09-06', 'summary' => 'Field/Foreman Experience pass, Phase 3E-3H: Digital Checklist reworked into one-tap OK/Not OK/N/A cards (no more sideways scrolling), Safety Observation/JSA/HIRADC mobile grid fixes, Incident investigation Recommendations now visible read-only, a real "New LOTO" quick action added (previously had zero entry point). No database change, no new route.'],
        ['version' => '2.10.0', 'date' => '2026-09-05', 'summary' => 'PTW Document Polish pass (Phase 3D): fixed a real PDF/browser-document parity gap (rejection reason was missing from the PDF), HIRADC/JSA now show title/job_title not just the reference number, print output made dark-mode-safe with proper A4 page setup and break-inside protection. No database change, no new route.'],
        ['version' => '2.9.0', 'date' => '2026-09-04', 'summary' => 'Field/Foreman Experience pass (Phase 3C -- My PTW): new requester-scoped, card-based My PTW view (permits-to-work.mine) with real status filter counts and inline Resubmit for rejected permits; Field Home\'s My PTW tile now links here with live pending/active counts. No new table, no new role, no database change.'],
        ['version' => '2.8.0', 'date' => '2026-09-03', 'summary' => 'PTW Mobile / Task-First pass (Phase 3B): mobile card list for PTW Index (status always visible, no horizontal scroll), a real "resubmit" action for rejected permits (the state machine already allowed it, the UI never exposed it), rejection reason surfaced on the Show page, remaining mobile grid fixes.'],
        ['version' => '2.7.0', 'date' => '2026-09-02', 'summary' => 'Field/Foreman Experience pass (Phase 3A): the universal dashboard route now branches to a new task-first Field Home page for Department Users -- large action tiles (Create PTW/My PTW/Checklist/Observation/Incident/My Tasks), reusing existing routes and WorkCenterService counts, no new RBAC, no database change.'],
        ['version' => '2.6.0', 'date' => '2026-09-01', 'summary' => 'PTW Document View pass: new in-browser, printable PTW document presentation (company header, work info, hazards/HIRADC/JSA, gas test, authorization, signatures), reached via a new primary "View PTW Document" action on the PTW detail page; Download PDF unchanged, reusing the existing PdfGeneratorService.'],
        ['version' => '2.5.0', 'date' => '2026-08-31', 'summary' => 'Field HSE Experience pass (Phase 2): CAPA Open/Overdue/In Progress/Closed summary + Overdue filter, Global Search gained HIRADC/JSA, PPE Master vs Operations made visually distinct, mobile-responsive fixes across HSE Inspection/Incident/PPE, natural-Indonesian empty states with primary actions.'],
        ['version' => '2.4.0', 'date' => '2026-08-30', 'summary' => 'PTW UX + Field Operations pass (Phase 1): PTW PDF document generation, Create form progressive disclosure, Submit now reaches Pending Approval in one step, Reject requires a reason, mobile/responsive fixes across the module.'],
        ['version' => '2.3.0', 'date' => '2026-08-29', 'summary' => 'HSE Operations pass: new Waste Container Inventory (physical drum/IBC/jumbo-bag stock, separate from waste material records), Man-Hour 500 fix re-verified, PPE Master Edit audited and confirmed correct, Quick Actions gained a Waste entry.'],
        ['version' => '2.2.0', 'date' => '2026-08-25', 'summary' => 'IOMS OS Ecosystem pass: Man-Hour 500 root-caused and fixed (ambiguous SQL column), Quick Actions and Work Center Action Center extended across every department, Global Search extended (PPE/CAPA/PTW/Assets/Vendors) and its cross-tenant scoping gap fixed, a second cross-tenant leak fixed in Work Center approvals/PPE-alerts, sidebar navigation language reverted to English.'],
        ['version' => '2.1.0', 'date' => '2026-08-25', 'summary' => 'HSE Starter package made genuinely operational: Man-Hour opened to HSE (entitlement-dependency-rule fix), Package-to-Workspace/Module grant mapping wired into tenant creation, PPE Dashboard "+" action fix, optional per-workspace entitlement enforcement (off by default).'],
        ['version' => '2.0.0', 'date' => '2026-08-16', 'summary' => 'Milestone 2: Tenancy Foundation, Platform Super Admin, Package/Subscription structure, RBAC (spatie/laravel-permission), DB-driven Module and Workspace catalogs, Platform Super Admin console.'],
        ['version' => '1.6.10', 'date' => '2026-08-11', 'summary' => 'Material Request complete workflow (HasWorkflow trait); RBAC options evaluated (Spatie Permission recommended, docs/ADR/006); new Warehouse role.'],
        ['version' => '1.6.9', 'date' => '2026-08-09', 'summary' => 'Universal Approval Engine and Activity Timeline viewer -- verified ActivityLog already existed before building anything new.'],
        ['version' => '1.6.8', 'date' => '2026-08-08', 'summary' => 'Employee Import from Excel; Report Export architecture prepared; two severe runtime bugs found and fixed via verification, not assumption.'],
        ['version' => '1.6.7', 'date' => '2026-08-06', 'summary' => 'ModuleTabNav reusable navigation; several desktop density passes; Material Request and PPE Replacement Request MVPs; reusable PdfGeneratorService.'],
        ['version' => '1.6.6', 'date' => '2026-08-03', 'summary' => 'PPE employee-centric restructure; navigation redesign; Browser QA stabilization (white flash, Select bug sweep).'],
        ['version' => '1.6.5', 'date' => '2026-08-02', 'summary' => 'About Dialog rebuilt from scratch; sidebar branding hierarchy fixed; six shared foundation components.'],
        ['version' => '1.6.4', 'date' => '2026-08-01', 'summary' => 'Universal Task Engine foundation; QA stabilization pass across Dashboard, Sidebar, Dark Mode.'],
        ['version' => '1.6.3', 'date' => '2026-07-28', 'summary' => 'Dashboard/Sidebar/TopNav/Theme/Company Branding usability pass; real Global Search; Forgot/Reset Password.'],
        ['version' => '1.6.2', 'date' => '2026-07-27', 'summary' => 'Root-cause fix for watermark clipping; exact 240px/70px/16px/24px branding specs; searchable Combobox.'],
        ['version' => '1.6.1', 'date' => '2026-07-26', 'summary' => 'Fixed a real PHP parse error; Dashboard Today\'s Summary; Top Department Workload; About fields expanded.'],
        ['version' => '1.6.0', 'date' => '2026-07-25', 'summary' => 'Enterprise UI refresh: centralized config/ioms.php, About dialog scrolling fixed, branding sizing pass.'],
    ],

];

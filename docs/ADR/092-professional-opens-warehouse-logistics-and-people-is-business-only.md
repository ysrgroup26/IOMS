# ADR 092 — Professional opens Warehouse Logistics; People / HRD and Management are Business only

**Status:** Accepted (2026-11-20, the owner). Branch `ticorp/paket-professional-logistik`, v2.143.0.
**Related:** `config/plans.php`, `Package::defaultWorkspaceKeys()/defaultModuleKeys()`, `EntitlementService`,
migration `2027_01_21_100000_professional_opens_logistics_not_people`, ADR 043 (pricing & included users).

## Context

The owner re-cut the ladder:

| Tier | Departments |
|---|---|
| Starter | HSE |
| Professional | HSE + Warehouse Logistics |
| Business | HSE + Warehouse Logistics + People / HRD + Management |

HRD and Management only in Business, Warehouse Logistics down to Professional, HSE everywhere. Prices,
included users, My Work users, PTW allowances, operating units and annual terms stay exactly as they are.

## Decision

1. `config/plans.php` is the only place the scope changes: Professional `['hse','logistics']`, and the
   Material Request module (the Logistics module) moves down with it. `employees` stays in every tier —
   HSE issues PPE to and counts man-hours for people; it is the shared register, not the HRD department.
   Every grant path (self-service activation, operator-created tenant, plan change), the route/API gate,
   navigation and the pricing/checkout copy already read that file, so nothing else needed new logic.
2. Existing Professional tenants are moved by migration, **surgically**: for tenants whose *current*
   subscription (`latestOfMany`) is Professional, remove the `hr` workspace grant and add `logistics` +
   `material_requests`. Other grant rows (a Platform Admin's hand adjustment) survive; a full `sync()`
   would have erased them. A tenant that upgraded from Professional to Business is not touched.
3. No data is deleted. HRD records stay in their tables; closing the workspace removes the route, not the
   rows, and an upgrade to Business shows them again. Subscriptions, agreed prices, invoices and payments
   are neither read nor written. `down()` restores the previous scope.
4. Package descriptions (catalogue copy) are rewritten in the same migration; prices are not touched.

## Tests

`ProfessionalLogisticsLadderTest` (catalogue scope, unchanged prices/capacities, pricing card copy,
migration on old/upgraded tenants incl. reversibility). Tests that pinned the old Professional scope
(`AccessControlBoundaryTest`, `StarterWorkforceAndAdminSpaceTest`, `TenantNavigationTest`,
`WorkspaceArchitectureTest`, `ManagementAndAdminSpaceTest`, `PublicReadinessTest`, `ApprovedCatalogue`)
now pin the new one.

## Found while doing this: the Document Library did not ask the plan

Every `DocumentDefinition::authorize()` returned true, so any organization could export a register whose
screen its plan closes (a Starter could download the leave register, stock position/movements, Material
Requests and project manpower). `DocumentLibrary` now asks the plan once for every definition: the screen
a register mirrors must pass `tenantCanUseRoutePrefix`, and the Material Request register needs the
`material_requests` module. Pinned in `ProfessionalLogisticsLadderTest::test_the_document_library_follows_the_plan`.

Full suite after the change: 1835 passed. Pint clean on every changed file.

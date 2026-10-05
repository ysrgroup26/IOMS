---
title: Settings Configuration Reachability
type: backlog
status: COMPLETED (v2.99.0) — both root causes fixed and runtime-verified
updated: 2026-10-05
tags: [kb/backlog, status/verified]
---

# Settings Configuration Reachability

**Source:** owner report, "Approval Flow terkadang tiba-tiba menjadi halaman putih/blank", and the
instruction to find the real root cause rather than add a workaround ·
**Board:** [[Project Board]] · **Release:** [[Release Index]]

## What the report turned out to be

Two unrelated defects that happened to meet on one screen. Neither produced a console error, a
failed request or a log line, which is why both survived so long and why a static read of any
single file looks correct.

| | Symptom the customer saw | Actual cause |
|---|---|---|
| 1 | Configuring an approval flow "did nothing" | A tenant-wide configuration row was unreachable the instant it was saved |
| 2 | The tab "suddenly goes white" | An unavailable `?tab=` value selects no Radix panel at all |

## 1. A null owner meant two different things

Approval Flow, Documents and Import/Export Mapping all store `company_id = null` to mean **applies
to the whole tenant**. That is the documented design, not an accident of a nullable column:
`ApprovalFlowResolver` states "company-specific flows win over tenant-wide defaults" and queries
`whereNull('company_id')`; `FieldMappingService::resolve()` is documented "tenant-wide only".

v2.62.0's `CompanyOwnedScope` then gave a null owner its safe default reading, **"visible to
nobody"** — correct for an ordinary table, wrong for these three. `NULL IN (...)` is never true in
SQL, so:

- Every flow, template and mapping a customer created **disappeared on save.** The success message
  appeared and the list stayed empty. Creating it again only added another invisible row; there is
  no unique constraint to object.
- **Route model binding runs through the same scope**, so `{approvalFlow}`'s own delete and
  save-steps endpoints answered **404** for a row that plainly existed.
- Worst, and visible on no screen: the resolver's `whereNull('company_id')` branch became **dead
  code**. `resolve()` returned null for every tenant-wide flow, so **every module silently fell
  back to legacy single-step approval** while Settings presented the feature as configured.

**Fixed** by `App\Models\Concerns\IsTenantWideConfiguration` on the three models. It implements the
scope's existing `companyScopeNullOwnerQuery()` hook to require `tenant_id` = the current tenant, so
a tenant-wide row is reachable inside its own tenant, structurally unreachable outside it, and
invisible with no tenant resolved.

Deliberately **not** `companyScopeAllowsGlobalRows()`, which means "shared with every tenant" and is
correct only for the platform-wide defaults in `NumberingFormat`, `NumberingSequence` and
`KpiCategory`, whose null rows carry no tenant at all. These rows do carry one, so that hook would
have been a cross-tenant configuration leak — the fix would have been worse than the bug.

## 2. An unavailable tab renders no panel

Settings fed the query parameter straight into `Tabs.Root`'s `defaultValue`. The `||` fallback only
catches a *missing* parameter, and **Radix renders no panel when the active value matches no
`Tabs.Content`**. Every Settings panel is gated by the same `canSystem` / `canPtwAccess` check as
its trigger, so an HSE or PTW-only user opening a bookmarked, shared or back-button
`/settings?tab=approval-flows` link got the chrome, the tab strip, no selected tab and an empty
body. Any stale or misspelled value did the same. `defaultValue` is read only on mount, so it
stayed blank until another tab was clicked.

**Fixed** by resolving the parameter through `availableTabs` — the tabs this user actually has a
panel for, in display order, so `[0]` doubles as the fallback and the previous behaviour is
unchanged for everyone who was already fine.

**Amplifier worth remembering:** there is **no ErrorBoundary anywhere in `resources/js`**. Any
render throw, from any page, presents as a blank page rather than a message.

## Verified

Runtime, not reasoned:

- **Browser, before:** `?tab=does-not-exist` → body text 1554 → **639** characters, all 16
  tabpanels empty, `aria-selected` on nothing. **After:** falls back to Branding, one filled panel.
- **Browser, controlled comparison** in a real request context: a flow with `company_id = null`
  (what the store path writes) → prop `approvalFlows: []`; the same flow with a real `company_id`
  → visible. After the fix the null-company flow is visible **with its steps**, and a second
  tenant's null-company flow is correctly absent.
- **`tests/Feature/SettingsConfigurationReachabilityTest.php` — 11 tests.** Confirmed to **fail
  without** the fix (including "a configured tenant-wide flow must resolve, not fall back to
  single-step approval"), so they are regressions rather than descriptions.
- **Full suite 866 passed / 4618 assertions**, up from 855 with no collateral damage. Vite build
  clean, ESLint 0 errors.

## Not done here

The customer-visible symptom is fixed, but the sequence that created it leaves a question this
change does not answer: **flows created before v2.99.0 are now visible again**, which is correct,
but a tenant that re-created the same flow several times while it was invisible will now see all
of those duplicates. There is no unique constraint on `(tenant_id, company_id, module_key)` and
`ApprovalFlowResolver` resolves ties by `priority` then insertion order, so the behaviour is
defined but the list may look untidy. Worth a look at real tenant data before deciding whether to
de-duplicate; nothing was changed in production data as part of this.

## Links

- [[Current State]] · [[Requirements Register]] · [[Working with This Knowledge Base]]
- `docs/CONVENTIONS.md` § *a NULL owner means two different things* and § *a Radix tab value nobody
  can see renders NO panel*
- `docs/ARCHITECTURE.md` § *A tenant-wide configuration row*

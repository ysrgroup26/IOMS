# ADR 058 — A shared catalogue is overridden, never rewritten, and that rule is the installation's, not PPE's

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.119.0
- **Extends:** ADR 050 (company-specific PPE template overrides). Same rule, stated once for every shared catalogue.

## Context

IOMS ships reference data every customer starts from. Four tables use the same shape — a nullable
`company_id` where **null is the shared built-in** and a value is that customer's own row:
`ppe_types`, `kpi_categories`, `numbering_formats`, `numbering_sequences`.

v2.106.0 found that one customer's administrator could rewrite a shared **PPE** row that every other
customer reads. ADR 050 answered it: the built-in is never modified, and an edit creates a
company-owned override carrying `source_default_id`, so the customer's version appears *where* the
built-in was rather than beside it.

That reasoning was applied to one table and filed as a PPE decision. **The identical write path on
`kpi_categories` was never guarded.** `SettingsController::assertCompanyInTenant()` returns early for
a null company — correct for "this row has no owner to compare against", and read in practice as a
licence to write it. So any tenant administrator could rename, re-weight, deactivate or delete a
KPI category every other customer scores against.

### Why it was worse on KPI than on PPE

`kpi_records.kpi_category_id` points at the category by id, and the delete guard asks a
**tenant-scoped** question — *does MY company have records in this category?* On a shared row that is
the wrong question. The customer deleting it legitimately has no records; another customer's entire
scoring history hangs off that id. The guard written to prevent exactly this failure would have
answered "no records, go ahead".

A refusal would have been the wrong remedy for the same reason ADR 050 records: a customer whose KPI
weighting differs from the shipped default would simply be stuck, which is the complaint that
started this whole line of work.

## Decision

**The ADR 050 rule is the installation's rule for every shared catalogue, not a PPE feature.**

1. `kpi_categories` gains `source_default_id`, the same column with the same meaning and the same
   `unique (company_id, source_default_id)` guarantee as `ppe_types`, so there is **one** override
   pattern in this codebase rather than two.
2. Editing a built-in is **accepted** and lands on a company-owned override. Deleting one
   **deactivates** that company's own copy. The built-in is never written and never removed.
3. The replacement is applied where the catalogue is **read**, in one place per question:
   - `scopeVisibleForCompany()` — already the single point the Dashboard, Reports, KPI input and
     report configurations read through, so they cannot disagree about which row is in force;
   - `scopeWithoutSupersededTemplates()` — for the surfaces that are deliberately cross-company
     (Quick Attendance, Settings, the Management workspace). It judges "overridden" against the
     companies the **current query can already see**, so another tenant's override can never hide a
     built-in from anybody.
4. The confirmation dialog says *deactivate for this Operating Unit*, because that is what the
   server does. A dialog promising a delete that does not happen is its own defect.

### The other two tables were checked, not assumed

`numbering_formats` and `numbering_sequences` carry **`tenant_id` as well**, and
`updateNumberingFormats()` keys its write on `tenant_id` + `company_id = null`. A null company there
means "every company *within this tenant*", not "shared across the installation", and the write
cannot reach another tenant's row. **No equivalent hole exists**, so nothing was changed there.

## Consequences

- A customer can adapt a shipped KPI category — the configurability the product promises — without
  any customer being able to reach another's catalogue.
- An override is a real row, so it appears in Settings owned by its Operating Unit rather than
  labelled Global. That is deliberate: the customer should be able to see that this category is now
  theirs.
- The built-in keeps its own `code`, and so does the override. The uniqueness rule on `code` is
  per-company and already was, so an override does not collide with its template.
- **The read-side change is the part to be careful with in future.** A new surface that reads the
  catalogue without either scope will show a built-in and its override side by side, with the wrong
  one selectable. `SharedKpiCategoryOwnershipTest` pins the four current surfaces; a fifth needs the
  same treatment.
- Verified by removing the guard and re-running: six of the twelve new tests fail without it,
  including all four that assert the hole. In a browser on MySQL 8, editing the built-in "Drill"
  leaves row 6 (`drill`, shared) untouched and creates row 9 owned by the Operating Unit; deleting
  "HSE Campaign" leaves row 7 active and shared and creates an inactive row 10.

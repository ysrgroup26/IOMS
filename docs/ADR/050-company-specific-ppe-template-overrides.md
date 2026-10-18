---
title: 050 — Company-specific PPE template overrides
type: adr
status: accepted
decided: 2026-10-13
version: 2.108.0
tags: [adr, status/implemented, ppe, tenancy, history]
---

# ADR 050 — PPE defaults are templates; company edits are overrides

## Status

Accepted and implemented in **v2.108.0**.

## Context

The six IOMS-provided PPE rows are shared templates. Editing a template in place would change the
catalogue and future replacement interval for every company. Creating an unrelated same-name row
would duplicate the visible item and would not reliably carry the configured interval into issues.
Existing `employee_ppe` rows also preserve the type reference and expiry calculated when issued.

## Decision

- Keep shared template rows (`company_id = NULL`) immutable to tenant users.
- On an authorized edit, create or update one company-owned row linked by `source_default_id`.
- Resolve the effective catalogue for the selected Operating Unit: an override replaces its source
  template for that company only; other companies continue to use the shared row.
- Issue PPE using the effective company row. Existing records keep their original type reference and
  stored expiry date; only new issues use the current interval.
- Deactivating a template creates or updates an inactive company override. Delete an unused custom
  type, but refuse deletion when any issuance history references it.
- Validate the target Operating Unit through the current request's Company scopes. Never accept a
  client-supplied company outside the user's tenant and authorized units.

## Consequences

The schema permits same-name template and company-owned rows, but keeps names unique inside one
company and permits one override per template per company. Seeders explicitly target global rows so
they cannot overwrite customer customizations. Rolling back the schema is refused if names have
become non-unique, rather than deleting or rewriting customer data.

The approved My Work user classes and allowances remain unchanged; they are independent of PPE
catalog ownership. See [[043-pricing-included-users-and-add-ons|ADR 043]] and [[Data Ownership and
Boundaries]].

## Verification

Regression coverage asserts a 12-to-6-month Safety Shoes override without a duplicate, an unchanged
shared template, company isolation, inactive local overrides and preservation of historical expiry.
Full release test and browser evidence are recorded in [[Verification Status]].

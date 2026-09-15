---
title: Security Decisions and Lessons
type: reference
updated: 2026-09-15
tags: [kb/security]
---

# Security Decisions and Lessons

The boundaries, and the incidents that produced them. **The mechanics are in
[[Data Ownership and Boundaries]]; the pitfalls are authoritative in `CONVENTIONS.md`.** This note
is the pattern library — the *shapes* these failures keep taking, so the next one is recognisable
before it ships.

---

## The arc

Tenant isolation in IOMS moved through three stages, and knowing which stage a piece of code comes
from tells you how much to trust it:

1. **Conventional** — every query was expected to remember to filter. It mostly did.
2. **Structural** (v2.62.0) — ownership became a global scope on the model, so a query cannot escape
   it. `TransitiveTenantIsolationTest` asserts that every model is either isolated or explicitly
   declared global with a reason.
3. **Transitive** (v2.63.0) — closed the gap stage 2 left: tables owned *one join away* had no
   `company_id`, so they received no scope at all.

> [!important] The most valuable single lesson
> **v2.63.0 was found by auditing v2.62.0's own coverage test, not by auditing the code.** The test
> only examined models carrying a `company_id`, so it passed *vacuously* for every model that did
> not have one. When you write an assertion about "everything", ask what your source of truth cannot
> see.

---

## The recurring failure shapes

### 1. A check scoped to where its author happened to be looking

This is the single most repeated shape in the project's history, and it has appeared in security,
copy and coverage:

| Instance | What the check missed |
|---|---|
| v2.62.0 coverage test | Models with no `company_id` — the exact ones at risk |
| v2.61.0 language test | Copy arriving as a **server prop**; it only read the JSX file, and passed for three releases while the page said something else |
| v2.53.0 language policy | The five department Overview pages, still running the policy it replaced — for fifteen releases |

**Rule: a test that claims to cover "the page" or "every model" must be able to see every source
that feeds it.**

### 2. Trusted roles silently getting more than intended

`MaterialRequest::scopeVisibleTo()` gave Super Admin an unconditional bypass — correct when there
was only one tenant, and a **cross-tenant leak** once there were several (fixed v2.12.0). The same
class of leak was found in Global Search and Work Center (v2.2.0) and in `company_settings`
(v2.40.0, a P0).

**Rule: the tenant boundary is an unconditional floor applied *before* any role-based widening.**

### 3. Route groups that are stricter — or looser — than intended

Material Request and PPE Replacement routes were once nested inside a `role:super_admin` group,
silently locking out every non-admin user. Recorded three times in `CONVENTIONS.md` because it is so
easy to repeat.

**Rule: adding a route near an existing group is not a safe edit. Check which group it lands in.**

### 4. Ordering that looks cosmetic and is not

`ResolveTenant` ran *after* `SubstituteBindings`, so every route-model binding in the application
resolved with no tenant in context. Invisible until `CompanyOwnedScope` existed, at which point it
returned 404 for records the user legitimately owned.

**Rule: if a middleware establishes context, everything that reads that context must run after it.**

### 5. Authorization performed after serialization

An Inertia page ships its props to the browser whether or not a component renders them. Hiding a
card in React leaves the data in the page source.

**Rule: resolve sensitive props only for a viewer entitled to them.** Applied in v2.69.0 for
employee disciplinary data, and asserted by a test.

### 6. Widening a renderer's reach without tightening what it renders

v2.68.0 made the friendly error page reachable for full-page requests — correct, but it meant the
message reached more readers. Framework text like
`No query results for model [App\Models\Employee] 99999` was being rendered as the whole explanation
of a 404.

**Rule: widening *reach* and tightening *content* belong in the same change.**

### 7. A state machine that reinstates on the wrong signal

Found in v2.70.0's own security review, before the code shipped. `extendPeriod()` force-set a
subscription's status to `active` on payment — which is right for `trial` and for a lapse, and
wrong for `suspended`. Combined with the (correct) decision to let a blocked tenant reach the
billing page so they can see why and pay, it meant **a suspended customer could lift their own
suspension by raising an invoice and paying it**.

Nothing was bypassed and no boundary leaked; the escalation came from one field being written
unconditionally in a method whose name suggested it only touched dates.

**Rule: a state machine must only advance on the signal that legitimately causes that transition.**
Money buys time. Reinstatement is a person's decision, and only that person's signal may write it.
The period still extends and the payment is still recorded, so nothing is lost when the operator
does reinstate.

Worth noting how it was found: not by a scanner, but by asking "who can now reach this route that
could not before, and what is the most valuable thing they could do with it?" after *widening an
allow-list*. Allow-list changes deserve that question every time.

---

## Standing security positions

| Position | Why |
|---|---|
| Tenant isolation is **fail-closed** | `TenantScope` with no resolved tenant returns nothing, not everything |
| A row nobody owns is visible to nobody | `companyScopeAllowsGlobalRows()` is opt-in for exactly three reference tables |
| Segregation of duties is config, not code | `config/workflow.php` keeps approve / process / override reviewable in one file |
| Payment activation is never client-driven | Nothing the browser does can activate OR EXTEND a subscription; `PublicReadinessTest` and `SubscriptionLifecycleTest` both pin it |
| A payment buys time, never reinstatement | `suspended`/`cancelled` are an operator's decision; a blocked subscription cannot even invite its own payment. ADR [[033-subscription-lifecycle|033]] |
| Expiry withdraws writing, never reading | IOMS holds the compliance evidence an organization answers a regulator with. A billing dispute must not become a safety one |
| The webhook exemption is a single literal path | Never a wildcard, and the provider signature is verified before a field is read |
| Trusted proxies are opt-in | Hardcoding `*` would let a client spoof its own scheme and host |
| The Sandbox is a real tenant | Bounded by the same scopes and RBAC as a customer, plus read-mostly — not a separate code path |
| Confidential HR data is narrower than its workspace | See [[Data Ownership and Boundaries]] |

---

## When you touch anything here

1. Read the relevant `CONVENTIONS.md` pitfall — it exists because it happened.
2. Add or extend a test in `tests/Feature/` — the isolation suite is the product's memory.
3. If the change is security-relevant, run the repository's security review before committing.
4. Record the outcome in [[Known Issues and Limitations]] if you found something you are not fixing.

---

See also: [[Data Ownership and Boundaries]] · [[Verification Status]] · [[Decision Register]]

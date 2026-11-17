# ADR 089 — The Sandbox is opened from an account

**Status:** Accepted (2026-11-17, the owner). Branch `ticorp/sandbox-account`. Amends ADR 053's v2.53.0
Sandbox ("no sign-up").
**Related:** `SandboxController`, `PlatformSandboxController`, `DemoTenantSeeder`, `Public/Sandbox.jsx`,
`Auth/AccountCreated.jsx`, Agent API `GET /api/agent/v1/akun-calon` (ADR 086), migration
`2027_01_20_100200_a_sandbox_visit_is_an_account_visit` (additive).

## Context

The Sandbox let anyone walk into a populated demo with one click and leave without a trace. The owner
wants the opposite: a prospect creates an IOMS account first, so (1) the team can follow up by e-mail,
(2) the product is not studied and copied anonymously, and (3) the same account later subscribes.
Separately, production has never had a Sandbox at all: deploys do not seed and `ioms-ops` does not
allow `db:seed`, so `/sandbox` answered "not available" while the public site promised it. And
`DemoTenantSeeder` would have failed there anyway, because `departments.code` is unique across all
tenants and a customer may already use `HSE`.

## Decision

1. **No account, no Sandbox.** `POST /sandbox` from a guest stores `/sandbox` as the intended page and
   sends them to `/register`; an account whose address is unconfirmed is told to confirm it first; a
   Master Admin is refused. A confirmed account enters as before (the shared, read-mostly demo user),
   and the visit is recorded on the account (`users.sandbox_visits`, `sandbox_last_entered_at`) and in
   the Activity Log. The public page shows the right action for the visitor's state; the
   account-created page offers "Try the Sandbox".
2. **Master Admin → Ringkasan → Aktifkan Sandbox** runs `DemoTenantSeeder` in a transaction. It is
   idempotent, so the same button refreshes.
3. **The seeder no longer collides with customers**: where another company holds a department code it
   uses `<CODE>-DEMO` instead; the customer's department is untouched.
4. **Follow-up**: `GET /api/agent/v1/akun-calon` (scope `growth`) lists accounts that belong to no
   organization (staff and the token-only AI Workforce account excluded) with verification, last
   sign-in, Sandbox visits and registration status, so TICorp's customer-service agent can draft
   follow-up e-mails (sending stays behind the owner's approval in TICorp).

## Tests

`tests/Feature/SandboxAccountTest.php` (5) plus the existing Sandbox suites (ProductConsistencyV253,
DemoTenant*): Master Admin creates the Sandbox while a customer holds `HSE`, idempotently; a guest is
sent to register; an unconfirmed account is refused and nothing is recorded; a confirmed account enters
and the visit is recorded; the page reports the visitor's state; prospects are listed without staff.

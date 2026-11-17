# ADR 086 — The Agent API reads, by a token, for a scope

**Status:** Accepted (2026-11-17, the owner). Built on branch `ticorp/agent-api`.
**Related:** ADR 073 (capability follows reversibility), ADR 008 (tenancy), `routes/api.php`,
`app/Http/Middleware/AuthenticateAgentToken.php`, `app/Http/Controllers/Agent/AgentApiController.php`,
`app/Http/Controllers/PlatformAgentAccessController.php`, `resources/js/Pages/Platform/AgentAccess.jsx`.

## Context

TICorp — Tahada's AI Workforce (customer support, finance, quality, security, operations agents,
coordinated from a separate VPS) — needs to read what Master Admin shows: tenants and their
subscription state, payments, registrations and the support queue. The owner wants agents to have
enough access to genuinely work (read, analyse, find problems), with one account separate from his own.

Until now the only way in was a Master Admin **login**, read by scraping Inertia pages. That is
fragile (any page change breaks it), and wrong in principle: the session can do everything Master
Admin can, including `GET /platform/database/backup`, which downloads the whole database, and every
write (plan changes, mark-paid, tenant status, ticket replies).

## Decision

1. **A separate, stateless, read-only surface: `/api/agent/v1/*`** in `routes/api.php` (the `api`
   group, so none of the web group's session, tenant or department middleware runs). Eight GET
   endpoints: `ringkasan`, `paket`, `tenant`, `tenant/{id}`, `pembayaran`, `tiket`, `tiket/{id}`,
   `pendaftaran`. They return the SAME facts Master Admin renders, from the same models and derived
   snapshots (`stateSnapshot()`, `PlatformOperationsService`), curated: option lists for forms are
   left out, and so is anything that grants rather than describes (`license_key`, password hashes,
   URL tokens). The database backup, exports and every write are simply not part of this surface.
2. **A Sanctum personal access token, never a session.** `statefulApi()` lets a signed-in browser
   reach `/api` with its cookie, and Sanctum then reports a `TransientToken` that `can()` anything.
   `AuthenticateAgentToken` accepts only a real `PersonalAccessToken`.
3. **Scopes.** A token carries `agent:read` plus one or more of `agent:ops|tenants|billing|support|growth`;
   each route names the one it needs. Finer, per-agent filtering (which agent sees which endpoint,
   masking of e-mail and phone before a language model reads anything) is TICorp's job, because only
   TICorp knows which agent is asking.
4. **Owned by an active platform admin, normally the dedicated AI Workforce account.** Deactivating
   or demoting that account stops every token it owns. GET/HEAD only, enforced in the middleware too.
   120 requests per minute per caller.
5. **Master Admin > Akses Agent** issues and revokes these tokens. The plaintext is shown once (the
   flash of the redirect) and never stored; only `agent:read` tokens are listed or revocable there.
   Issue and revoke are written to the Activity Log (`created` / `deleted`, `meta.kind = agent_token`).
6. **No migration.** `personal_access_tokens` already exists (2024_01_01_000010), so this release is
   deployable under ADR 076's rule without a person approving a schema change.

## Consequences

- TICorp replaces page scraping with this API; a Master Admin page change no longer breaks agents.
- An agent that needs to CHANGE something (reply to a ticket, extend a trial) gets a separate,
  deliberately designed endpoint, approved through TICorp's Tier 2 path, never a widening of this group.
- Customer personal data leaves IOMS only to TICorp, over HTTPS, to a token the owner issued; TICorp
  masks e-mail and phone before any model sees it (owner decision 2026-11-17).

## Tests

`tests/Feature/AgentApiTest.php`: no token and a browser session are refused; scopes are enforced per
route; a token without `agent:read`, an inactive owner and a tenant administrator's token are refused;
POST/PUT/DELETE answer 405; tenant detail carries no license key; ticket detail carries its messages;
Master Admin issues a token, shows it once, refuses to revoke a non-agent token, revokes an agent token;
an owner must be an active platform admin and a tenant administrator cannot open the page.

## Addendum (2026-11-17) — the AI Workforce account, and JSON errors

- IOMS has no screen that creates a platform admin (only `PlatformAdminSeeder`), so Akses Agent gains
  **Buat akun AI Workforce**: one platform admin, `User::AGENT_WORKFORCE_EMAIL`
  (`ai-workforce@agents.iomsuite.invalid`), created once (idempotent), recorded in the Activity Log.
  It exists only to own Agent API tokens, so what agents read is attributed to it rather than to the
  owner. It **cannot sign in**: its password is 64 random characters nobody ever sees; the `.invalid`
  TLD is reserved, so no reset mail can reach it; the login form refuses it with the same words as a
  wrong password; and `EnsureAccountIsActive` ends any web session it somehow has. The token form
  defaults to it as owner.
- `api/*` errors render as Laravel's JSON (`shouldRenderJsonWhen`), never the friendly Inertia error
  page, which `wantsJson()` used to select for a program's 401 (CI-16 in TICorp).
- Tests: AgentApiTest 12; full suite 1809/1809.

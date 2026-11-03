---
title: IOMS | Duitku Production Activation & Sandbox Retirement
type: runbook
status: Pending Activation
priority: Critical
trigger: Duitku Production Activation
owner: IOMS Technical Authority
mandatory: true
created: 2026-11-03
production-activation-date: null
sandbox-retirement-date: null
tags: [runbook, payments, duitku, mandatory-trigger]
---

# IOMS | Duitku Production Activation & Sandbox Retirement

> [!danger] MANDATORY TRIGGER: read and work through this whole document before any Duitku Production activation
> Applies to any work that sets production Duitku credentials, sets `DUITKU_IS_PRODUCTION=true`, or
> "goes live" with Duitku. The whole checklist in §8 is part of the activation. Activation is not
> complete until §8 and the completion report in §9 are done.

**Status: Pending Activation.**

On 2026-11-03, production (iomsuite.com, commit `664e9bf`) runs Duitku **Sandbox** on purpose, for
Duitku's merchant review:
- `PAYMENT_GATEWAY=duitku`
- merchant code `DS36132` (sandbox)
- `DUITKU_IS_PRODUCTION=false`

**Do not run the cleanup yet.** It starts only when every trigger condition in §2 is true.

Related: [[PAYMENTS-DUITKU]] (integration runbook, §7 production cutover),
[[085-the-invoice-is-issued-under-the-brand-and-a-return-is-never-believed|ADR 085]],
[[RELEASE-LOOP]] and [[076-github-deploys-through-one-forced-command|ADR 076]] (production deploys),
[[STAGING-CI]] (staging, where Sandbox stays).

---

## 1. Objective

When Duitku Production integration is officially activated and verified, disable, block or remove every
Sandbox configuration, access path, testing mechanism, credential and temporary component wherever
technically possible. The live environment must use Duitku Production only. No public or internal
workflow may accidentally keep using Sandbox.

This is a mandatory post-activation task.

## 2. Mandatory trigger conditions

Start the cleanup only when **all** of these are true:

- [ ] Duitku has approved IOMS for Production.
- [ ] Production Merchant Code and API Key have been issued.
- [ ] IOMS production credentials are configured.
- [ ] `DUITKU_IS_PRODUCTION=true` is applied.
- [ ] Production connectivity and payment processing are confirmed.

Receiving the production credentials alone is **not** a trigger. The cleanup is coordinated with a
successful production activation.

## 3. Sandbox retirement scope

### A. Application configuration
- Remove the Sandbox merchant code and API key from production.
- Remove temporary Sandbox configuration and fallback mechanisms.
- Make sure production cannot silently fall back to Sandbox.
- Make sure `DUITKU_IS_PRODUCTION=true`.
- Prevent Sandbox credentials from being loaded into the production runtime.

### B. Payment gateway access
- Block Sandbox endpoints from the production payment workflow.
- All payment creation and status inquiries use the official Duitku Production endpoints.
- Users cannot switch between Sandbox and Production.
- Callback validation uses the production configuration.

### C. Sandbox testing components
- Remove or disable Sandbox-only payment simulator links from customer-facing interfaces.
- Remove temporary testing shortcuts, staging preview mechanisms and Sandbox-specific customer-facing
  messages from production.
- Remove obsolete Sandbox testing routes or components that are no longer required.
- No test payment flow can activate a real customer subscription.

### D. Credentials and secrets
- Remove obsolete Sandbox credentials from the production environment configuration.
- Review GitHub Secrets, server environment variables and deployment configuration for Sandbox credentials
  that are no longer needed.
- Never expose credentials in logs, source code, public documentation or customer-facing interfaces.
- Keep only the credentials still required by isolated staging or development environments.

### E. Cloudflare and callback security
- Confirm the production Cloudflare **Skip** rule for the official Duitku Production callback IPs.
- The rule applies only to `POST /webhooks/payment/duitku` and the approved production IP addresses.
- Keep application-level HMAC signature validation, amount validation and duplicate-callback protection.
- Do not add unnecessary IP restrictions inside the application.

### F. Documentation and repository
- Update `docs/PAYMENTS-DUITKU.md`, `ROADMAP.md` and the deployment and operational runbooks.
- Record the Sandbox retirement date and the Production activation date (front matter of this document).
- Clearly separate retained staging Sandbox infrastructure from retired production Sandbox configuration.

## 4. Staging and development policy

Do **not** remove Sandbox from staging or development automatically. Sandbox may remain in isolated
non-production environments for future development and regression testing. However:

- Sandbox must never be reachable through the public production payment workflow.
- Staging credentials stay isolated from production credentials.
- No staging configuration or credential may be copied into production.
- Sandbox simulator access must not be exposed to production customers.

## 5. Verification and completion

After activation and retirement, verify with the existing deployment and operational mechanisms:

1. Production reports `DUITKU_IS_PRODUCTION=true`.
2. Production uses the official Duitku Production endpoint.
3. Production Sandbox credentials are no longer configured.
4. Production checkout cannot reach Sandbox.
5. Production callbacks are accepted only with valid signatures.
6. Duplicate-callback and amount validation protections remain active.
7. Customer-facing interfaces contain no temporary Sandbox testing components.
8. Existing subscription, invoice and payment records remain intact.
9. Staging Sandbox remains isolated and functional, if retained.

Reuse existing successful test results. Do not repeat the full test suite or add unnecessary testing.

## 6. Execution authority and safety

Claude is the technical authority that plans and executes this retirement. However:

- Do not disable the working payment gateway before the production credentials are verified.
- Do not delete historical payment records, invoices, audit trails or financial evidence.
- Do not remove shared code that staging still needs.
- Do not delete Sandbox credentials from staging or development unless separately approved.
- Do not do destructive cleanup without confirming its scope and rollback implications.
- Keep production payments available throughout the transition.

If a component cannot be deleted safely, disable or isolate it and document why.

## 7. What exists today, and where (inventory, 2026-11-03)

| Component | Where | Production today | At retirement |
|---|---|---|---|
| Sandbox credentials | `/srv/ioms/app/.env` (`DUITKU_MERCHANT_CODE=DS36132`, `DUITKU_API_KEY`, `DUITKU_IS_PRODUCTION=false`) | **Active** (Duitku review) | Replace with production values. No sandbox value stays in this file. |
| Endpoint selection | `DuitkuGateway::baseUrl()`: one boolean, `passport.duitku.com` vs `sandbox.duitku.com`. There is no third switch and no fallback. | sandbox | `true` → passport. Confirm there is no other `DUITKU_*` override. |
| Sandbox purchases activate real workspaces | Any visitor can "pay" with Duitku's public sandbox simulator | **Open risk while on Sandbox** | Gone once credentials are production. Then review the registrations created during the Sandbox period at `/platform/registrations` (do **not** delete records; decide per tenant). |
| `ioms:duitku-check` | Artisan command; refuses to run when `DUITKU_IS_PRODUCTION=true` | usable | Keep. It is a sandbox-only diagnostic, harmless in production. Use the production verification in §8 instead. |
| Staging confirmation link ("Staging preview") | `App\Support\StagingPreview`: renders only when `APP_ENV != production` **and** `MAIL_MAILER=log` | inert | Keep (staging needs it). Verify it is absent on iomsuite.com. |
| `staging.sh callback open/close`, `review-check` sandbox section, `staging-callback-probe.yml` | Staging tooling only; cannot reach production (ADR 084) | not present in production | Keep for staging. |
| Duitku simulator links / test cards | Only in `docs/PAYMENTS-DUITKU.md` §6a (repository docs, not served) | none in the UI | Confirm none were added to a customer page. |
| Cloudflare Skip rule | Cloudflare dashboard (owner) | not confirmed | Add or confirm with the **production** IPs (list in [[PAYMENTS-DUITKU]] §4) before switching. |
| GitHub secrets | `IOMS_DEPLOY_*` (production deploy) and `IOMS_STAGING_*` (staging). Neither holds a Duitku credential. | — | Confirm no Duitku credential was ever added as a GitHub secret. |
| Duitku Sandbox project callback URL | Duitku sandbox dashboard | may point at staging | Leave on staging for staging use. |

## 8. MANDATORY CHECKLIST: work through it when production activation starts

**Before switching** (production stays on Sandbox until all of these pass):
- [ ] Every trigger condition in §2 is true. The production merchant code identifier is recorded; the API key is never recorded.
- [ ] Cloudflare Skip rule for `POST /webhooks/payment/duitku` from the 10 **production** Duitku IPs is live (§3E).
- [ ] Duitku **production** project callback URL = `https://iomsuite.com/webhooks/payment/duitku`.
- [ ] Read-only production status (Deploy workflow, `action=status`) is healthy before the change.
- [ ] Note the current registrations, invoices and payment counts. This is evidence for §5.8; nothing is deleted.

**Switch** (owner, as root on Tencent; credentials never pass through chat or Git):
- [ ] `sudoedit /srv/ioms/app/.env`: production `DUITKU_MERCHANT_CODE` and `DUITKU_API_KEY`, `DUITKU_IS_PRODUCTION=true`. No sandbox value remains (§3A, §3D).
- [ ] `sudo ioms-ops artisan config:cache`.
- [ ] Rollback if anything below fails: restore the previous `.env` lines and `config:cache` (payment availability is preserved, §6).

**Verify** (§5; reuse earlier results, no full suite):
- [ ] Deploy `action=status`: ALL CHECKS PASSED, unsigned callback **403** (§5.5).
- [ ] The endpoint is `passport.duitku.com` (`DUITKU_IS_PRODUCTION=true`, §5.1–5.2). `ioms:duitku-check` now refuses to run, which is the expected confirmation.
- [ ] The first real production payment, smallest amount, owner's own card or VA: callback received, attempt and invoice paid once, subscription active (§5.4–5.6). This is the only new test.
- [ ] iomsuite.com shows no "Staging preview", no simulator link and no sandbox wording (§5.7).
- [ ] Existing records intact: counts match the "before" note (§5.8).
- [ ] Staging `review-check` still passes on Sandbox (§5.9, §4).

**Record** (§3F):
- [ ] Set `status: Completed`, `production-activation-date` and `sandbox-retirement-date` in this document's front matter.
- [ ] Update [[PAYMENTS-DUITKU]] (§7 and the production state), `ROADMAP.md` (Duitku track), and [[RELEASE-LOOP]] if the operational steps changed.
- [ ] Write the completion report in §9.

## 9. Completion report (fill in at activation)

| Item | Value |
|---|---|
| Production activation date | |
| Production Merchant Code identifier (never the API key) | |
| Sandbox components disabled or removed | |
| Sandbox components intentionally retained in staging | |
| Cloudflare callback security status | |
| Final production gateway configuration | |
| Verification results (§5.1–5.9) | |
| Remaining risks or exceptions | |

**Final acceptance criterion:** IOMS production operates only through Duitku Production. No Sandbox
credential, endpoint, simulator or temporary testing workflow is reachable through the production
customer payment experience.

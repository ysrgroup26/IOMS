---
title: Verification Status
type: register
updated: 2026-09-20
tags: [kb/verification]
---

# Verification Status

What has actually been **run**, versus what has been carefully **reasoned about**. This distinction
is a first-class concept in IOMS, not documentation pedantry.

**Authoritative procedure: `LOCAL-VERIFICATION.md`.**

---

## Why this note exists

For most of this project's history, *nothing* was confirmed by running the application. MySQL was
unreachable in the development environment, so every claim about the UI came from reading source.
`CLAUDE.md` still says it plainly.

A single 30-minute browser session then found **four defects that months of code review had missed**,
including a wordmark showing the wrong product name on the login screen and a header that overflowed
on every phone width.

> [!important] The lesson is not "review harder"
> Some classes of defect are **only** visible when the application is running. Compilation is not
> verification: `npm run build` succeeding tells you the JavaScript parsed, nothing more.

---

## The levels

| Level | Means | Claim you may make |
|---|---|---|
| **Reasoned** | Read carefully; brace/balance checks, route cross-references, prop-contract matching | "The code looks correct" |
| **Test-covered** | A test exercises it and would fail on regression | "It behaves correctly under the suite" |
| **Browser-verified** | Exercised in a running application against realistic data | "It works" |
| **Measured** | A number was taken from the running app (overflow widths, query counts) | "It is *this* much better" |

`#status/verified` in the registers means **browser-verified or measured**, not merely test-covered.

---

## Current verification state

### Automated

| | |
|---|---|
| Suite | **508 tests, 2615 assertions — all passing** (2026-09-22) |
| Database | In-memory **SQLite**, so no MySQL or external service is needed |
| Lint | `npm run lint` — 0 errors (4 pre-existing warnings in `GasTestRecords/Index.jsx` and `Settings/Index.jsx`) |
| Build | `npm run build` — clean, with the known bundle-size warning |

> [!warning] SQLite is not MySQL
> The suite deliberately runs on SQLite so it needs nothing external. That means it does **not**
> cover MySQL-specific behaviour — notably `lockForUpdate` row/gap locking, which SQLite serialises
> globally, so a "concurrency test" there proves nothing. It also does not cover real concurrency,
> production data volumes, or email/queue side effects.
>
> It also means **migrations must stay driver-aware**: avoid `ALTER TABLE … MODIFY COLUMN`,
> multi-table `UPDATE … JOIN` and anything reading `information_schema`, or guard it with
> `DB::getDriverName() === 'mysql'`. Six migrations had to be made portable before the suite could
> provision at all.

### Browser-verified

Exercised against **MySQL with real tenant data** (3 tenants, 68 employees, 29 departments), which
matters — several defects only appear at realistic scale.

| Area | Version | What was verified |
|---|---|---|
| Account → Subscription, end to end | 2.74.0 | Against MySQL: registered through `/register`; confirmed the account carried `role=account`, null tenant/company, **`isPlatformAdmin()` false**, and that no tenant, company or subscription row was created; read the verification email out of the mail log and followed its signed link; raised an order through the subscription setup page (identity taken from the account, never re-asked); drove checkout, which honestly parked at `awaiting_payment` with a real invoice because no gateway is configured; then activated through `TenantProvisioningService` — the same entry point the verified webhook uses — and confirmed the **user count did not change**, the account was promoted to `super_admin` on the new tenant, and an active subscription with agreed pricing was created |
| Man-Hour calculation | 2.77.0 | `ManHourTest` (calculation, whole-period total across pages, inclusive bounds, empty period, project breakdown sums to total, 24h cap, replace-same-day, reversed period, tenant isolation). **Against MySQL in the browser** with a throwaway tenant (35 people, 45 records over two table pages): page showed exactly the expected 365.0 total / 35 headcount / 10.0 OT / 355.0 regular / 2 work days, breakdown Hull Block 12 170.0 + No Project 195.0. Mobile 375px: no overflow. Throwaway tenant deleted afterwards |
| Subscription lifecycle UX & read-only | 2.77.0 | `SubscriptionReadOnlyEnforcementTest`: every write route refused while lapsed with all table counts unchanged; real-record create/edit/delete refused; reads intact; own password change allowed; full renew → signed webhook → same subscription extended → writes restored, no duplicate tenant/company/subscription/account. **Against MySQL in the browser**: expiring, grace and lapsed banners and Billing notices with correct dates and a single Renew action; a real POST refused 403 while lapsed while reads stayed 200; Renew from lapsed raised a real invoice on the same tenant (bank-transfer copy, as no gateway is configured locally); settling it through `applyPaidInvoice()` extended the same row and a write then succeeded, with entity counts identical. Billing at 375px: no overflow, Renew on the first screen. **Not verified:** a live Midtrans payment (no production credentials here) |
| Landing positioning & brand icons | 2.76.0 | `LandingPositioningTest` + `BrandIconsTest`. In the browser after hydration: title *IOMS — Industrial Operations Platform*, exactly one H1 (*One platform for complex industrial operations.*), exactly **one** meta description and one `og:title` (the duplicates are gone), canonical https://iomsuite.com/, JSON-LD Organization + WebSite + SoftwareApplication, icon links /favicon.ico, 48px, SVG, 32px, apple-touch and manifest, `/favicon.ico` served as `image/vnd.microsoft.icon`, eight story `<h3>`s with working internal links. Layout measured rather than eyeballed: at 1440px the rows alternate visual-left/visual-right, and at 375px every row stacks visual-above-text; no horizontal overflow at either width. **Not verified:** how Google renders the result or favicon; that depends on deployment and crawling |
| Email logo | 2.75.0 | Rendered the real verification email with a **development** `APP_URL`: the image is `https://iomsuite.com/branding/ioms-logo-email.png` regardless, the verification link is untouched, alt text and descriptor remain. Viewed in a browser: identical design; with the header background stripped (the webmail failure) the logo still reads as a navy tile instead of an empty box. Asset served 200 `image/png`, opaque, background #0f2747. Sender still `noreply@iomsuite.com` / `IOMS` (EmailIdentityTest). **Not** verified in a real inbox — the PNG reaches production only when deployed |
| Public search identity | 2.75.0 | `PublicSearchIdentityTest` drives https://iomsuite.com in the production environment: robots.txt, a well-formed sitemap of exactly the ten public pages at canonical URLs (even when served from ioms.web.id), unique title/description/one canonical/OG/Twitter per page, valid JSON-LD with every URL on iomsuite.com, `lang=id` on legal pages, noindex on login/register/get-started/account/subscribe/dashboard/sandbox, noindex on the legacy host and in non-production. In the browser: tab titles correct through client-side navigation, local robots.txt `Disallow: /`. **Google Search Console not configured** — manual steps in ADR 039 |
| Get Started = account registration | 2.74.2 | **Browser-exercised against MySQL, starting from a plan card**: `/get-started?plan=professional&cycle=monthly` rendered ONLY the account form — full name, "Your email", password, confirm, consent — with no company field, no plan card, no price and no payment anywhere on the page; registering landed on the `register.welcome` fork; "Continue setup" opened subscription setup with **Professional and Monthly already selected**, proving the plan chosen before the account existed survived registration through the session; the account section stated the name and a Confirmed email with **zero password inputs**; submitting produced one order at Rp799.000 on the existing `register.status` page. Also confirmed: a signed-in org-less account opening `/get-started` is sent to `/account` (not `/dashboard`), `/dashboard` and `/incidents` both end at `/account`, and "Choose a plan" reopens the same setup page. Test data removed afterwards |
| One setup form, two entry points | 2.74.0 | **Browser-exercised against MySQL**: registered at `/register` (label now reads "Your email"), landed on the `register.welcome` fork rather than in either branch; confirmed `/subscribe` bounces an unverified account to `/account`; verified the address and reopened `/subscribe` — it rendered the **existing** Get Started page with its own hero, plan cards, billing toggle and company fields intact, with "Your account" **stating** the name and a Confirmed email and **no password fields**; submitted and landed on the existing `register.status` order page showing the account's own identity; confirmed in the database that exactly ONE order was created, carrying `contact_name`/`contact_email` from the account, `password = NULL`, `company_country` defaulted, and that **no tenant, company or subscription row** appeared; reopened `/subscribe` and confirmed the open order's plan and cycle were pre-selected rather than reset; confirmed Account → "Choose a plan" points at `/subscribe` and "Resume" at the existing order page. Test data removed afterwards |
| Unsubscribed access boundary | 2.74.0 | **Measured**: every one of `/dashboard`, `/incidents`, `/investigations`, `/permits-to-work`, `/employees`, `/settings`, `/my-work`, `/work-center`, `/subscription/billing` redirected to `/account`; `/platform` 404'd. No tenant data reachable |
| Incident → Investigation → CAPA | 2.73.0 | End to end against MySQL: a 5W1H initial report filed through the real endpoint (injury, treatment, facility, chronology, witnesses, claim reference all persisted; an empty witness row correctly dropped); an investigation opened from it, which moved the incident to `investigating`; the analysis, an interview and a corrective action saved; the workflow driven draft → in progress → under review → completed → closed, with an **illegal jump back to draft correctly refused** by the state machine; reviewer and closer stamps written server-side; closing the investigation closed the incident; the full audit trail read back |
| Record chain | 2.73.0 | Rendered from BOTH ends and confirmed to agree — the incident shows INC (current) → INV → CAPA, the investigation shows the same chain looking back |
| PTW required vs confirmed PPE | 2.73.0 | A permit raised with three required PPE types from the existing master plus one free-text item and two hazards; approved confirming only two, and the **Harness correctly surfaced as unconfirmed** on the Show page, the document view and the generated PDF alike |
| PWA installability | 2.73.0 | Manifest served and valid (name, short_name, standalone, start_url, 192+512 icons in both `any` and `maskable`); service worker registered and active at origin scope |
| **PWA cache safety** | 2.73.0 | **Measured**: after signing in and visiting /dashboard, /incidents, /investigations, a permit, /settings and downloading a PDF, the cache held **3 entries — 2 build assets and 1 brand asset, zero authenticated responses**. This is the assertion ADR 037 exists for |
| PDF weight | 2.73.0 | **Measured**: the PPE tick/box glyphs embedded a DejaVu subset and took the permit PDF from 8 KB to 886 KB. Replaced with CSS-drawn marks; back to 9 KB |
| Migrations | 2.73.0 | All four rolled back and re-applied against **MySQL**, not just the suite — which is how the over-length index name and an undroppable FK-backing index were both found |
| PTW approval stamp | 2.72.0 | End to end against MySQL: a permit raised through the real `store()` route showed **no** stamp while submitted; approving it as the HSE Officer through the real transition endpoint produced exactly one seal carrying the approver, their role and the moment, on the Show page, the Document view and the generated PDF alike. A seeded permit with status `approved` but no `hse_approver_id` correctly stamps nothing |
| Screen-vs-PDF agreement | 2.72.0 | **Measured**: stamp reads `17 Sep 2026 15:09` in both renderers, and the validity window `18 Sep 15:00 / 19 Sep 00:00` in both. Before the fix the screen said 16:09 — see the `display_timezone` pitfall in `CONVENTIONS.md` |
| Brand rollout | 2.72.0 | The official lockup renders and loads on every surface: login (dark variant on navy), the app rail (dark, 123x32), the public header and footer (light, on white and graphite-50), the About dialog (**both**, switched by the `dark` class). Favicon, apple-touch-icon, canonical, og:image and Organization JSON-LD read back correctly from the live `<head>`; `robots.txt` and `sitemap.xml` served with 11 correct absolute URLs |
| Operational form identity | 2.72.0 | `FormDocumentHeader` rendered on PTW (`PTW-2026-00001`, Draft, Requester, Permit Type, workflow line) and on Material Request (`MR-2026-00001`, Draft, Requested By, Items). `/ppe/master` confirmed to carry **no** document header — the master/operational distinction holds |
| Field & PTW Access | 2.72.0 | The PTW flow block and the per-account outcome sentence render; toggling My Work for an account left its PTW Access untouched in the database (`field=1 ptw=0`), which is the independence claim, exercised rather than asserted. Test state reverted afterwards |
| Responsive | 2.72.0 | **Measured** `scrollWidth` vs `clientWidth` at 375 / 768 / 1280 on the PTW form, PTW Show, PTW Document, Material Request form, Settings and the public home page: **no horizontal overflow anywhere**. The About dialog was found overflowing 406px inside a 334px panel at 375 and fixed |
| Capability reach | 2.71.0 | Material Request opened from the HSE rail without the sidebar jumping to Logistics; the HSE Administration row rendered on the HSE Overview; the My Work toggle, which previously 403'd for HSE, exercised end to end as the HSE Officer |
| Sidebar scroll memory | 2.71.0 | Offset restored exactly (240 -> 240) across a real navigation where it previously reset to 0; clamped to the maximum when the stored value exceeded a shorter list; an HSE offset did not leak into the global menu |
| Sidebar hierarchy | 2.71.0 | **Measured** from the compiled stylesheet: level-1 rows and group headers both 13px/500/navy-300, children 13px/400/navy-400, active 600/white; exactly one entry divider, after Overview |
| Master vs operational | 2.71.0 | The Master Data chip and its consequence sentence appear on switching to a reference-data tab and disappear on a register tab; chip contrast measured at 11.3:1; at 375px the tab strip scrolls inside its own wrapper and the page does not |
| Material Request lifecycle | 2.71.0 | The stage sentence rendered for submitted, consolidating and completed -- the last of which previously showed no Progress panel at all; the consolidating tone measured as steel, not a warning |
| Subscription lifecycle | 2.70.0 | Active, grace and lapsed states rendered and read; a renewal invoice raised at the customer's grandfathered price for the correct next period; a write refused with the real explanation while every read still worked; a downgrade scheduled rather than applied; no horizontal overflow at 375px |
| Demand consolidation | 2.69.0 | Hold with reason, consolidating two requests into one purchase, the automatic move to Processing, and the link back from the request — end to end |
| Material Request aging | 2.69.0 | Outstanding filter, aging emphasis, no overflow at 375px |
| Employee Cases | 2.69.0 | Case list, case record, derived standing, issued SP with validity window |
| Migrations | 2.69.0 | Rolled back and re-applied to confirm they reverse cleanly |
| Language hierarchy | 2.68.0 | Department Overviews and public pages, plus zero truncated labels at 375 / 430 / 768 / 1024 / 1440 |
| Error page | 2.68.0 | Full-page 404 renders the in-product page; framework messages suppressed |
| Dashboard charts and KPI labels | 2.68.0 | Zero truncation; ranked bars replace the 14-slice pie |
| Application shell layout | 2.67.0 | Overflow **measured** at 13 widths from 320 to 1920, before and after, against the real compiled stylesheet |

### Reasoned or contract-tested only — **not** interaction-verified

Stated plainly because the distinction matters:

| Area | Why it is not browser-verified |
|---|---|
| **Shell keyboard and screen-reader behaviour** (focus trap, `inert`, skip link, accessible names, `aria-current`) | Covered by contract tests over the shipped source and by review. Signing in could not be automated at the time, and the shell is React that never runs server-side — an Inertia response is a prop bag containing none of that markup. `FormExperienceSystemTest` records the same limit |
| **MySQL-specific concurrency** | See the SQLite note above |
| **Email and queue side effects** | Not exercised by the suite |
| **Live payment flow** | Requires external provider configuration — see [[Known Issues and Limitations]] |
| **Google OAuth (v2.74.0)** | Architecturally complete and **never executed**. No `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` exists in this environment, so the consent screen, the `state` check, the token exchange and the three linking branches have not been exercised against Google. What IS verified: the button is hidden and both routes 404 when unconfigured, and the linking logic's inputs (`sub`, `email_verified`) are read only from Socialite's server-to-server response. See `docs/ADR/038` for the exact configuration a deployment needs |
| **A real Midtrans notification** | Every webhook test signs its own payload with a test server key, so what is verified is that IOMS honours the contract *as documented*. Their actual signature over a live transaction, their full `transaction_status` vocabulary, and their retry timing have never been observed. This is the gap a sandbox transaction against real credentials would close, and it is the one remaining item in the payment path that carries genuine unknown risk — [[Requirements Register]] |
| **The scheduled run in production** | `subscriptions:lifecycle` is test-covered and was exercised by hand, but no deployment has yet run it from cron over a real period boundary |
| **The sidebar scroll WRITE, by a human hand** (v2.71.0) | The restore path was exercised fully (exact restore, clamping, per-department isolation). The write could not be driven by script: the embedded verification browser does not dispatch `scroll` for a programmatic `scrollTop` assignment while the window is not painting — a listener attached by hand in the console never fired either, so this is the tool, not the product. The handler is three lines, attached to the element the restore reads from, and pinned by a test that forbids the rAF regression that actually broke it |

---

## How to verify

The loop, from `LOCAL-VERIFICATION.md`:

```
code → tests → build → browser → responsive check → report
```

1. `php vendor/bin/phpunit` (PHP lives under Laragon — see [[Known Issues and Limitations]])
2. `npm run build` — **always**, or the browser serves a stale bundle and you "verify" code you did
   not change
3. Run the app; seed **realistic** data, including deliberate duplicate names — real industrial
   workforces are full of them, and they have exposed genuine defects
4. Exercise the behaviour, watch the network panel and the console
5. **Measure** responsive overflow at 320 / 375 / 390 / 430 — screenshots hide it
6. Report the boundary honestly: say what you ran and what you did not

---

See also: [[Known Issues and Limitations]] · [[Working with This Knowledge Base]] · [[Security Decisions and Lessons]]

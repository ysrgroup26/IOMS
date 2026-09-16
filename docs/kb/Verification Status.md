---
title: Verification Status
type: register
updated: 2026-09-16
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
| Suite | **417 tests, 1721 assertions — all passing** (2026-09-16) |
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

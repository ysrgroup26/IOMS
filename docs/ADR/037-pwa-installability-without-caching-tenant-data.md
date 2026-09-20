# ADR 037 — IOMS is installable, and its service worker caches almost nothing

**Status:** Accepted and implemented (v2.73.0).
**Date:** 2026-09-20

---

## The decision

IOMS ships a **web app manifest and a service worker so browsers will offer to install it**, and the
service worker **caches exactly two public prefixes — `/build/` and `/branding/` — and nothing
else**.

There is no offline mode, no navigation fallback, and no offline page. That is not an unfinished
implementation. **It is the decision.**

## Why a service worker exists here at all

One reason: a browser will not offer to install a web application without one. It is an
*installability* requirement, not an offline strategy — and conflating the two is how multi-tenant
platforms leak data.

## Why the obvious PWA recipe is unsafe for this product

Every PWA tutorial reaches for cache-first over application shells and pages. In IOMS that produces
a concrete, reachable data breach:

1. A Super Admin of **Tenant A** opens `/dashboard`. The HTML — carrying that tenant's data in an
   Inertia prop bag — is written to the cache under the key `"/dashboard"`.
2. They log out. A different user, from a **different tenant**, signs in on the same device. This is
   *normal* on a shared site terminal, which is exactly the hardware IOMS is used on.
3. They open `/dashboard`. The service worker serves the cached response **before the network is
   consulted**.

Tenant A's data is now on Tenant B's screen.

**No server-side scope was bypassed and no authorization check failed — the request never reached
the server.** `TenantScope`, `BelongsToCompany`, the 404-not-403 pattern, `EnforceTenantEntitlement`:
all of it is enforced server-side, and a cache that answers without asking renders every bit of it
irrelevant at once.

> [!important] A service-worker cache has no concept of identity
> It is keyed by URL and shared across everything in that browser profile. It does not know who was
> signed in when an entry was written, and it cannot be made to know.

The same reasoning rules out caching PDFs (a permit, an invoice), Inertia/JSON responses, uploaded
evidence photographs, and exports. All of them are private operational records.

**Clearing the cache on logout does not fix it.** That only helps if the logout actually happens on
that device — which is precisely the case that fails.

## What is cached, and why it is safe

One class of thing: **build artefacts and brand assets**. Files under `/build/` and `/branding/`,
which are

- identical for every tenant and every user,
- served without authentication,
- content-hashed by Vite (`app-CqyoCrW4.js`), so a new deploy is a new URL and a stale entry can
  never shadow a new build.

The worker is an explicit **allow-list**, not a deny-list: a deny-list would be one new route away
from leaking. Requests carrying `credentials: 'include'` are refused outright unless they match the
allow-list, non-GET methods are never touched, and for anything else the worker does not call
`respondWith` at all — so the browser performs its ordinary network fetch and the server authorises
it exactly as if no service worker existed.

## Verification

Browser-verified, not merely reasoned: after signing in and visiting `/dashboard`, `/incidents`,
`/investigations`, a permit, `/settings`, and downloading a PDF, the cache contained **three
entries** — two build assets and one brand asset. Zero authenticated responses.

`tests/Feature/PwaInstallabilityTest.php` pins the allow-list and asserts the worker has no
navigation handling. Those tests assert over the worker's **source**, because PHPUnit cannot run a
service worker; that limit is stated in the test file itself. Their job is to stop the one change
that would be catastrophic and is very easy to make by accident — somebody adding an offline
fallback "so the app works on site".

## The rest of the installability surface

- **The manifest is a route, not a static file.** `start_url` and `scope` depend on the deployed
  host, which is only known from the live request behind the hosting proxy — the same reasoning as
  `robots.txt` and `sitemap.xml` (v2.72.0). It also lets the manifest read `config/ioms.php`, so the
  installed app is named by the same single source of truth as the browser title.
- **`start_url` is `/dashboard`, not `/`.** `/` is the public marketing site. Someone who installed
  IOMS wants the application; a landing page inside a standalone window with no address bar is a
  worse first impression than the login screen `/dashboard` redirects to.
- **`scope` is the whole origin.** A narrower scope would open Settings, a PDF or the pricing page
  in a separate browser window, which reads as the app throwing the user out.
- **`any` and `maskable` icons are two different drawings.** A maskable icon keeps its artwork
  inside the middle ~80% so Android's circular and squircle crops cannot cut the mark. Declaring one
  icon `"purpose": "any maskable"` forces that padding everywhere, rendering the mark needlessly
  small on every platform that does not crop.
- **iOS Safari gets instructions, not a button.** Apple implements no installation API — no
  `beforeinstallprompt`, no `prompt()`. `InstallAppAction` therefore detects the *platform* (normally
  a mistake; correct here precisely because the capability is absent by design) and names the actual
  Share-sheet steps. It never fakes an installer, downloads a file, or disguises a bookmark.
- **`beforeinstallprompt` is captured at boot**, in `resources/js/lib/pwa.js`, not in a component.
  Chromium fires it once and early; a component that mounts later has already missed it.

## If offline support is ever wanted

It needs a design, not a cache setting: per-identity cache names keyed to the authenticated user
*and* tenant, explicit eviction on logout **and** on identity change, an allow-list of records the
user is known to be entitled to, and a product decision about what a stale permit on a phone at a
gate is allowed to claim. That is its own ADR.

## What would make this ADR wrong

If IOMS ever needs genuine offline field capture — a permit raised in a hold with no signal — this
policy is insufficient rather than incorrect. The answer would be a deliberate, identity-scoped
offline store for a *named* set of records, not a relaxation of the rule above.

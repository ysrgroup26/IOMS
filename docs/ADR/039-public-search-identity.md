# ADR 039 — Public search identity, and the email logo

**Status:** Accepted and implemented (v2.75.0); positioning, content and favicon added in v2.76.0.
**Date:** 2026-09-21
**Builds on:** the v2.72.0 site identity (favicons, robots.txt, sitemap, Organization markup).
**Related:** ADR 038 (why /login, /register, /account and /subscribe are not public pages).

---

## One canonical origin, separate from APP_URL

```
IOMS_PUBLIC_URL=https://iomsuite.com      config('ioms.public_url')
```

`APP_URL` is where **this deployment** lives: `http://localhost:8000` on a dev machine, possibly the
legacy `ioms.web.id`. It still builds every **working** link: sign-in, email verification, checkout,
invoices. Those have to point at the host that can actually serve them, so they were not touched.

`public_url` builds only the addresses that must point at production whoever produced them:

- canonical URLs, `og:url`, and every URL inside structured data;
- `sitemap.xml` entries and the `Sitemap:` line in robots.txt;
- images inside emails.

## The email logo

**The problem.** The IOMS header in the verification email rendered as an empty or pink box in
webmail. There were two causes, and either one would produce it:

1. **The image URL came from `asset()`, meaning from `APP_URL` of whichever host sent the email.**
   Mail providers fetch images through their own proxy. That proxy cannot reach `localhost`, and it
   should not depend on the legacy domain.
2. **The artwork was a near-white wordmark on transparency**, laid over a navy table cell. Several
   webmail clients and dark-mode rewriters drop cell backgrounds. White on white is an empty box.

**The fix.** It is the smallest one that removes both causes, and it leaves the email design, copy,
typography and footer unchanged:

- The image URL is `config('ioms.public_url')` plus `config('branding.assets.logo_email_png')`, so it
  is always `https://iomsuite.com/branding/ioms-logo-email.png`.
- `ioms-logo-email.png` is the **official** `ioms-logo-dark.png` flattened onto the header navy
  (`#0f2747`), at the same 480×125 size. No path of the artwork was altered. It looks identical where
  the navy survives, and it still reads as a navy tile where the navy does not.

**Rejected:**
- **`data:` URI.** Gmail and Outlook block it.
- **CID attachment.** Shows as a paperclip, and in some clients as a downloadable file.
- **SVG.** Outlook renders email through Word, which has no SVG support.

**Kept:** explicit `width`/`height` attributes (for Outlook), `alt="IOMS"`, and the descriptor set as
text below the image. A client that blocks images still shows "IOMS · Industrial Operations
Platform".

**The sender is unchanged:** `noreply@iomsuite.com`, display name `IOMS`. `EmailIdentityTest` pins it.

**Deployment consequence:** the new PNG must exist on the production host. It ships in
`public/branding/` with the release.

## Search: an allow-list, with noindex by default

`config/seo.php` is **the one list of indexable pages**. Each page has its title, its description,
its sitemap priority and frequency, and a `lang` of `id` for the Indonesian legal documents.
`App\Services\SearchIdentity` is the only thing that reads it. The layout, the sitemap, robots.txt,
the `X-Robots-Tag` middleware and the browser-tab title all ask that service, so whether a page is
indexable and how it describes itself cannot disagree.

**A response is indexable only if all three hold:**
1. its route is in `config/seo.php`;
2. the request came to the canonical host;
3. `APP_ENV=production`.

Everything else gets `X-Robots-Tag: noindex, nofollow` from `SetRobotsHeader`, plus a robots meta
tag on HTML pages.

The middleware is **global**, not on a route group. `docs/CONVENTIONS.md` records repeated incidents
of routes missing from group lists. With a global default, a route written tomorrow stays private
until someone lists it.

| Surface | Behaviour |
|---|---|
| Home, Platform, Solutions, How It Works, Pricing, FAQ, Contact, Privacy, Terms, Refund Policy | indexable, own title and description, canonical on iomsuite.com, in sitemap |
| /login, /register, /forgot-password | crawlable, **noindex**, no canonical, no structured data |
| /get-started | redirects to /register; noindex |
| /account, /subscribe, /register/welcome, the whole authenticated app | noindex; the private prefixes are also disallowed in robots.txt |
| /sandbox | noindex (a demo entry point, not content) |
| the same pages on ioms.web.id | noindex, with canonical pointing at iomsuite.com |
| any non-production environment | robots.txt `Disallow: /`, everything noindex |

**Why /login and /register are not in robots.txt `Disallow`.** A crawler can only obey a `noindex`
on a page it is allowed to fetch. A disallowed URL can still be indexed from links alone, as a bare
address with no description, which is the opposite of what we want.

## Metadata

The metadata is rendered by the server in `resources/views/app.blade.php`, so crawlers and link
previews that never run JavaScript still see it. Each public page gets:

- a unique `<title>` and meta description (under about 60 and 160 characters);
- one canonical;
- Open Graph tags: type, site_name, locale, title, description, url, and image with its size and alt;
- a Twitter `summary_large_image` card.

The browser tab keeps the same title after client-side navigation. `seoTitle` is shared as an Inertia
prop, and `app.jsx` reads it from `router.page`, which Inertia updates before it renders the next
page.

**Structured data** is one JSON-LD `@graph` per page:
- **Organization** on every public page. Its `logo` is the light-surface PNG, because a search
  engine composites it onto white.
- **WebSite** and **SoftwareApplication** on the home page only. The SoftwareApplication `offers` is
  an `AggregateOffer` built from the published catalogue. Custom-priced plans are left out rather
  than quoted as zero.
- **No BreadcrumbList.** Every public page sits one level below home, and a two-item trail describes
  no real hierarchy.

**Language.** `<html lang>` is `en`, except `id` on the legal documents.

**Headings.** Checked, not changed. Every public page already has exactly one `<h1>`, through
`PublicPageHero` or its own hero.

## v2.76.0: positioning, content and the favicon

### The definition is visible content
The landing page H1 said *"One platform for how your whole operation actually runs"*. It never said
what kind of operation, and no domain or industry appeared above the fold.

It now reads:
- eyebrow: **IOMS · Industrial Operations Platform**
- H1: **One platform for complex industrial operations.**
- a paragraph naming the eight domains and six industries
- eight domain stories, each with an `<h3>`

The Industries band had an eyebrow and no heading. It now has one (*Complex industrial operations*),
so every section's subject is stated as a heading. The home meta description and the
SoftwareApplication `featureList` restate the same content. They add nothing the page does not say.

### Duplicate metadata removed
`Welcome.jsx` rendered its own `<meta name="description">` and `og:*` tags inside `<Head>`. Inertia
appends those after hydration, so the live DOM carried **two** meta descriptions and two `og:title`
tags, and the pairs disagreed. Removed. The server's `config/seo.php` metadata is the only source,
and `LandingPositioningTest` fails on any page-level `<meta>`. Checked in the browser after
hydration: one of each.

### No server-side rendering, and what that means
Inertia SSR is off. It needs a persistent Node process, which the current shared hosting does not run,
so page bodies are drawn by JavaScript:
- **Google** renders JavaScript and indexes the full page.
- **Non-JavaScript clients** receive the head: title, description, canonical, Open Graph and JSON-LD.

For those clients, public pages also carry a server-rendered `<noscript>` block containing:
- the page title as `<h1>`
- its one-sentence summary
- links to every public page

The block is never shown with JavaScript on and makes no claim the page does not.

If IOMS moves to hosting that can run the SSR process, enabling it in `config/inertia.php` supersedes
the `<noscript>` block.

### No AI-specific content
There is no `llms.txt`, no hidden text and no copy written for machines. How Google and its AI
summaries describe IOMS follows from the page being clear; it is not configured.

### Favicon
The favicon family is described in [[UX and Design Principles#Brand asset hierarchy (v2.76.0)]].
Two SEO-relevant gaps were closed:

- `/favicon.ico` did not exist. Crawlers and older clients request it without reading any `<link>`.
  It is now a real 16/32/48 icon.
- There was no favicon of at least 48px, the minimum Google prefers. `ioms-favicon-48.png` now exists.

Both are derived from the official art without redrawing it. The favicon stays the cyan mark on a
solid navy square: it is high-contrast on light search surfaces, so no lighter container was needed.

## Bug found while building this

Route names such as `legal.privacy` contain a dot. `config('seo.pages.legal.privacy')` reads that dot
as nesting and returns null, so every legal page silently counted as "not public". The lookups index
the array instead, and `PublicSearchIdentityTest` would catch a regression.

## v2.81.0 addendum — www, and the invoice that said its own name twice

### One address, and the request that must not be moved

Everything that *describes* the site already named the canonical origin: canonical tags, the
sitemap, Open Graph, Twitter cards and structured data are built from `config('ioms.public_url')`
rather than from the request, and `SearchIdentity::isCanonicalHost()` already marked any other host
`noindex`.

What was missing was the **redirect**. A visitor or a crawler arriving on `www.iomsuite.com` was
served the page, saw a canonical tag pointing somewhere else, and stayed on the wrong address —
which splits links, makes analytics read as two sites, and leaves a second host that must be kept
identical forever.

`App\Http\Middleware\RedirectToCanonicalHost` now answers it: **301, GET/HEAD only, production
only**, path and query preserved.

Four decisions worth keeping:

| Decision | Why |
|---|---|
| **Only `www.` + the canonical host** is moved | Not "any host that is not canonical" — that would bounce a health check by IP, an internal hostname, a preview domain and the legacy `ioms.web.id`, which is a separate decision with its own consequences and is deliberately left alone (it already serves, and is already noindex with a canonical pointing here) |
| **Unsafe methods pass straight through** | This is the dangerous half. A client is permitted to convert a 301 on a POST into a GET and drop the body, so a payment provider posting a webhook to the www host would have its settlement notification silently discarded and IOMS would never learn the payment succeeded. `CanonicalHostRedirectTest` asserts the webhook POST reaches verification and is rejected there, rather than being bounced |
| **Production only** | A redirect in development would send a developer to the live site |
| **Prepended** to the global stack | It answers before session, tenant resolution or entitlement do work a redirect throws away |

Switchable with `SEO_REDIRECT_WWW=false` if the www host is ever genuinely needed as its own
address — which would then have to be made *consistent* rather than redirected.

> [!warning] This is the half that lives in the repository
> It only fires for requests the hosting layer actually routes to this application. A DNS or
> vhost level redirect is still the right place for the rest, and remains a manual production
> step — see below. Nothing here has been applied to production.

### The invoice header

Not search identity, but the same brand-consistency question and worth recording beside it: the
subscription invoice rendered through `pdf/partials/letterhead.blade.php`, which is built to print
a **tenant's** identity. Fed the issuer identity it drew the IOMS lockup — artwork that already
reads "IOMS" — directly beside the text "IOMS", so the document announced itself twice.

The shared letterhead is deliberately **unchanged**, because every tenant document depends on it.
The invoice carries its own header instead, matching the identity customers already know from IOMS
email: navy band, the mark **alone**, `IOMS` as type with `INDUSTRIAL OPERATIONS PLATFORM` beneath
it, and a cyan rule under the band. The issuer block prints only when a registered identity is
actually configured — with none, it would have been a third repetition of the name in a box whose
whole purpose is registered detail.

### The logo asset

Audited against the designer's master (`SVG_Icon 1.svg`): the shipped `ioms-icon.svg` already
carries the six official paths **byte-identical**, with the master's full-bleed `#f7fafc` rect
removed and the viewBox cropped to the artwork's own bounds — the v2.72.0 derivation, still
correct. One dead `.st1 { fill: #f7fafc }` rule was left behind by that removal and is now gone;
it styled nothing.

The `#00004f` rect in `ioms-favicon.svg` and `ioms-og.svg` is **not** that background: it is the
brand ground those two assets deliberately need (a favicon must stay legible against light and
dark browser chrome; an OG card is composited onto an unknown surface). Left as designed.

The public navbar was rendering the lockup at `h-6` — 92×24 in a 64px header. Correct in shape
and proportion, but small enough beside 14px nav type to read as an afterthought. Now `h-8`
(123×32, half the header height). Measured in the browser at desktop and 375px: ratio 3.853
against the artwork's own 3.846, so it is neither cropped nor stretched.

> [!important] Google shows what it last crawled
> Every asset and every piece of metadata in the repository now points at the current mark. A
> search result still showing an older logo is a **crawl** state, not a repository state, and
> changes only when Google re-crawls and re-indexes. Nothing here can make that happen, and this
> release does not claim it has.

## Manual steps that remain (owner, outside the repository)

Nothing here has been submitted to Google, and nothing here makes Google index anything. It makes
the site eligible and correctly described.

1. **Deploy**, and set these in production `.env`:
   - `APP_ENV=production`
   - `IOMS_PUBLIC_URL=https://iomsuite.com` (this is also the default)
   - `APP_URL=https://iomsuite.com` if it is not already

   Then run `php artisan config:clear`.
2. **Check live:**
   - https://iomsuite.com/robots.txt shows `Allow: /` and the sitemap line.
   - https://iomsuite.com/sitemap.xml lists ten URLs.
   - https://iomsuite.com/branding/ioms-logo-email.png loads.
3. **Google Search Console → Add property.** A **Domain** property (`iomsuite.com`) is preferred; it is
   verified by a DNS TXT record at the domain's DNS host. Alternatively, a **URL-prefix** property
   (`https://iomsuite.com/`) verified by an HTML file or meta tag. Tell the developer which method you
   choose if you want the meta tag added.
4. **Sitemaps → submit** `https://iomsuite.com/sitemap.xml`.
5. **URL Inspection → Request indexing** for `/`, `/platform-overview`, `/solutions`, `/pricing`,
   `/how-it-works` and `/faq`.
6. **Legacy domain.** Ideally a hosting-level **301 redirect** from `ioms.web.id` to `iomsuite.com`
   for public pages. Until then, the legacy host is noindex with a canonical pointing at iomsuite.com.
7. **Later:** use Search Console's Page indexing report to confirm that /login and /register show as
   "Excluded by noindex". That is the expected result, not an error.

### Added in v2.81.0

8. **`www` at the hosting layer.** The application now 301s `www.iomsuite.com` → `iomsuite.com` for
   GET/HEAD, but only for requests cPanel actually routes to it. Confirm in cPanel that the `www`
   subdomain resolves to the same document root (otherwise it never reaches PHP), and preferably
   add the redirect at the web-server level too so it costs no PHP boot. **Not done by this
   release — nothing touched cPanel.**
9. **Verify once deployed:** `curl -I https://www.iomsuite.com/pricing` should answer
   `301` with `Location: https://iomsuite.com/pricing`, and
   `curl -I https://iomsuite.com/pricing` should answer `200`.
10. **Re-crawl for the logo.** After deploying, use Search Console → URL Inspection → Request
    indexing for `/` so the Organization `logo` and the OG image are re-fetched. Until Google
    re-crawls, an older mark can still appear in results; that is expected and is not a fault in
    the site.

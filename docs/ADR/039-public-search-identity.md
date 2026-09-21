# ADR 039 — Public search identity, and the email logo

**Status:** Accepted and implemented (v2.75.0).
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

## Bug found while building this

Route names such as `legal.privacy` contain a dot. `config('seo.pages.legal.privacy')` reads that dot
as nesting and returns null, so every legal page silently counted as "not public". The lookups index
the array instead, and `PublicSearchIdentityTest` would catch a regression.

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

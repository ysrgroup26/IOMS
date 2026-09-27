---
title: Website and Brand Consistency
type: backlog
status: COMPLETED (v2.81.0) — two steps wait outside the repository
updated: 2026-09-27
tags: [kb/backlog, status/verified]
---

# Website and Brand Consistency

**Source:** owner request, 2026-09-27 (invoice redesign + domain/logo) · **Board:** [[Project Board]] ·
**Decision:** ADR [[039-public-search-identity|039]] v2.81.0 addendum

## What was asked

Three things, from looking at the live product rather than at the code: an invoice that did not look
like a SaaS invoice and printed IOMS twice in its header; two addresses (`iomsuite.com` and
`www.iomsuite.com`) where there should be one; and an older logo still appearing in search results
and previews.

## What the audit found

| Reported | Actual cause |
|---|---|
| "Duplicate IOMS text/logo in the invoice header" | The invoice rendered through `pdf/partials/letterhead.blade.php`, which is built to print a **tenant's** identity — logo, company name, legal name — because every other PDF in IOMS is written *by* a customer. The invoice is the one document that runs the other way, so it was fed the **issuer** identity, and the partial drew the IOMS lockup (artwork that already reads "IOMS") beside the text "IOMS" |
| "`www` and non-www both live" | Everything that *describes* the site already named the canonical origin — canonical tags, sitemap, OG, Twitter, structured data — and any other host was already `noindex`. **No redirect existed**, so a crawler on the `www` host was served the page and stayed there |
| "Old logo in Google / metadata / previews" | Nothing stale in the repository. Every asset and every piece of metadata already pointed at the current mark and the canonical origin. What a search result shows is what Google **last crawled** |
| The supplied master SVG has a `#f7fafc` background rect | Already handled in v2.72.0: the shipped `ioms-icon.svg` carries the six official paths **byte-identical** with the rect removed and the viewBox cropped to the artwork's bounds. Only a dead CSS rule remained |

## What was built — `#status/verified`

- **The invoice has its own header.** Navy band, the mark *alone*, `IOMS` as type with
  `INDUSTRIAL OPERATIONS PLATFORM` beneath it, cyan rule under the band — the identity customers
  already know from IOMS email. Hierarchy rebuilt underneath: a meta strip (issue date, due date,
  status), parties side by side, totals aligned under the amount column, payment information as a
  bordered note. The shared letterhead is **untouched**, because every tenant document depends on it.
  The issuer block prints only when a registered identity is configured.
- **`www` → canonical, 301, GET/HEAD only, production only**, path and query preserved.
  `RedirectToCanonicalHost`, prepended to the global stack.
- **The mark**: dead `.st1` rule removed; navbar lockup `h-6` → `h-8` (92×24 → 123×32 in a 64px
  header), ratio measured at 3.853 against the artwork's own 3.846.

## The decision worth remembering

**A 301 is never applied to an unsafe method.** A client is permitted to convert a 301 on a POST into
a GET and drop the body, so a payment provider posting a webhook to the `www` host would have its
settlement notification silently discarded — and IOMS would simply never learn the customer had paid.
`CanonicalHostRedirectTest` asserts the webhook POST reaches signature verification and is rejected
*there*, rather than being bounced.

The second: only `www.` + the canonical host is redirected. "Anything that is not canonical" would
bounce a health check by IP, an internal hostname, a preview domain and the legacy `ioms.web.id` —
which is a separate decision and is already handled by being `noindex` with a canonical pointing here.

## What still waits outside the repository

1. **cPanel**: confirm `www.iomsuite.com` routes to the same document root (otherwise the request
   never reaches PHP and the application redirect cannot fire), and preferably add the redirect at
   the web-server level too. **Nothing in this release touched cPanel.**
2. **Search Console**: request re-indexing so the Organization logo and OG image are re-fetched.
   Until Google re-crawls, an older mark can still appear in results. That is expected, and this
   release does not claim otherwise.

Both are written up in ADR [[039-public-search-identity|039]] § *Manual steps that remain*, items 8–10.

## Related

ADR [[039-public-search-identity|039]] · [[Verification Status]] · [[Release History]]

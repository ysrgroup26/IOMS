# ADR 070 — Type has a voice, and a badge counts one thing

- **Status:** Accepted
- **Date:** 2026-10-20
- **Release:** v2.130.0
- **Related:** [[069-a-notification-arrives-without-being-asked-for]],
  [[068-a-job-title-is-configured-and-a-role-is-not-one]],
  [[065-a-stamp-is-an-action-record-and-its-title-belongs-to-the-company]]

---

## 1. A badge counts one thing

**The reported defect:** "the badge still shows 2 after the notifications have been opened."

It was not a styling problem, a sync problem or a caching problem. The badge was

```js
const badgeCount = unreadCount + ppeAlertCount;
```

and `ppeAlertCount` is **not a notification**. It is a live computed count of PPE items expiring or
expired — it cannot be read, it belongs to nobody, and it does not go away when anybody opens
anything. A tenant with two expiring items had a bell showing 2 permanently, so reading every
notification in the product changed nothing and the number stopped meaning anything at all.

A badge on a bell answers one question: *how many things have I not seen?* The only honest answer
is the unread notification count. The PPE alert keeps its row **inside** the menu, where it is a
link to the list somebody would act on and where its number sits next to the words that explain it.

## 2. The 419 that made it look stuck anyway

Fixing the arithmetic was not enough, and the reason took a browser to find: **marking a
notification read answered 419 for anybody who had signed in during that visit.**

`<meta name="csrf-token">` is rendered once, with the document. Signing in **regenerates the
session and its token** — and Inertia does not reload the document when it navigates: it swaps the
page component inside the *same* document. So after login the meta tag still carries the token from
the login page, which the session no longer accepts.

It passed every server-side test, because Laravel disables CSRF verification in tests. It passed
review, because reading the meta tag is what every tutorial says to do. It failed the moment
somebody clicked a notification.

`lib/csrf.js` reads the `XSRF-TOKEN` **cookie**, which Laravel rewrites on every response and which
is therefore current by construction — the same source axios, and so Inertia itself, uses, which is
why Inertia's own requests kept working while these did not.

**And it sends one token, not both.** `VerifyCsrfToken::getTokenFromRequest()` reads `X-CSRF-TOKEN`
first and falls back to `X-XSRF-TOKEN` only when it finds nothing, so sending both means the stale
meta always wins. Measured: with both headers, still 419; with the cookie alone, 200. The meta
remains the fallback for a browser that will not hand the cookie back.

## 3. Read state is reconciled, not inferred

The count alone cannot keep a list honest. Somebody who opens a notification on their phone leaves
a tab on the desk showing the same row in its unread treatment: the badge drops, because that is
the server's number, but the **row** does not, because a row only ever arrived as *new*.

So the ids that are still unread travel with every answer, bounded to the window the bell shows,
and a client marks everything outside that set as read. It is a **reconciliation rather than an
event**, which is what makes it idempotent: a missed poll, a replayed one and a reconnect all
converge on the same answer. One-directional on purpose — it marks rows read, never back to unread.

The unread treatment itself was `bg-brand-50/40`, which over a white menu is very nearly white. On
a phone in daylight the two states were indistinguishable, which is half of why the badge was the
only signal anybody had. Unread is now full `brand-50` with the rule down the left; read drops both.

## 4. Sound needs the gesture that asked for it

Browsers do not refuse audio because of a flag: they refuse it until an `AudioContext` is created
or resumed **inside a real user gesture**. A notification arrives on a timer, with no gesture
anywhere near it. So the sound toggle is not only a preference, it is the opportunity — it primes
the context and plays the tone once as confirmation. Without that, the first notification after
turning sound on was silent and the person reasonably concluded the feature did not work.

Live delivery itself was never a dashboard feature: the provider is mounted by
`AuthenticatedLayout`, which every page in the product wraps, and a test now pins that the
transport prop reaches pages other than the dashboard.

## 5. The splash was built on a path nobody starts from

The owner reported that the mobile loading screen had not changed. It had — somewhere nobody
starts.

v2.128.0 rendered the boot splash only when `$seoPage === null`, i.e. the authenticated
application, on the stated reasoning that a public page "arrives with content a visitor can read at
once". **That reasoning was wrong on the facts, and measuring it is what showed it:** Inertia SSR is
off for *every* page in this product, so `/` paints a blank white screen until its bundle runs —
loaded at 390×844 with the bundle blocked, `document.body.innerText` was **empty**.

Somebody typing the domain on a phone lands on `/`, not on `/dashboard`. The splash now renders on
every page; the `noscript` crawler summary and the `.ioms-js` gate are untouched. The inlined
lockup's own XML comment is stripped on the way in — it belongs to whoever opens the file, not to
every page load, and once the splash reached the public pages its em dashes tripped the
public-copy rule `LandingPositioningTest` enforces.

## 6. Type has a voice

**The complaint was that the interface looks dated and mechanical, and that `HOT WORK` reads as
ordinary default text.** Both were true, and the cause was one decision repeated everywhere: the
whole authenticated product was Inter, and its headings were `font-semibold`.

600 is neither regular nor bold. It is the weight that makes a page title look like a section of
some larger page, and it was on every page title and every KPI figure in the product.

**Three families, each with a job**, named as roles in `resources/css/app.css` rather than spelled
out at four hundred call sites:

| Role | Family | Where |
|---|---|---|
| Interface | **Inter** 400/500/600 | tables, forms, navigation, labels, body. Unchanged: it is the right tool for dense operational text |
| Display | **Archivo** 700/800 | page titles (`.type-page-title`), KPI figures (`.type-metric`), document section heads |
| Document type | **Archivo at 75% width**, 800 | `.type-document-title` — "HOT WORK", "PERMIT TO WORK" |
| Identifiers | **IBM Plex Mono** | permit numbers, codes, badge numbers (`.type-code`) |

Archivo was already in the product for the public website; this extends that decision inward rather
than inventing a fourth voice. The **width axis** is what gives a document's type band authority
without size: condensed letterforms let it be larger per line without taking the line, which is what
makes a controlled document read as an official form rather than as a page with a heading on it.

**And the issuer still outranks it.** v2.128.0's instruction — the company name is the document's
primary identity — holds: the name is 24px and the type band 22px condensed. Authority comes from
letterforms, weight and tracking, not from being the biggest thing on the sheet. A test pins the
relationship so a future pass cannot quietly trade one instruction for the other.

### The PDF is the same typeface, not a similar one

A browser CSS change that leaves the PDF on Helvetica would be the visible half of the work.
Dompdf cannot reach the Google Fonts stylesheet and **must not try** — a renderer that fetches a
font at render time produces a different document when the network is slow. So the PDF embeds
**static instances** of the same family from `resources/fonts`, committed to the repository.

They are instances because Archivo ships as one variable file and **dompdf cannot render a variable
font** — it would draw every weight as Regular. Each weight was instanced with fontTools, including
the 75%-width one, and saved as its own TTF.

Measured, because a dompdf font that fails to load does not raise — it silently falls back and the
document merely looks ordinary: `pdffonts` on a rendered permit reports
`ArchivoRoman-CondensedExtraBold`, `-ExtraBold` and `-Bold` as embedded CID TrueType faces, with
the Indonesian text intact. Licence: SIL OFL 1.1, which permits embedding; `resources/fonts/OFL.txt`
travels with them.

## 7. An identifier is upper case; everything else is the person's to choose

Typing `EMP-0001` on a phone should not require finding Caps Lock. The `uppercase` flag on the
shared `Input` transforms the value **on the event**, so React never sees a mismatch between what
was typed and what is set, and captures and restores the selection in a layout effect — a
controlled input handed a new value drops the caret to the end otherwise, which would send it
there on every keystroke.

Applied to: the employee badge number, the operating unit code, the department code, the warehouse
code, the shift code, the hazard category code, the waste container and storage point codes, and
the My Work access code (which the server already normalised).

**The exclusions are as deliberate as the inclusions.** Never a person's name, an email address, a
password, an address or free text — and never a value whose case carries meaning: the regulatory
waste code is `B105d` by law, and a machine key is `used_oil` because that is the key. Two `code`
fields in the same controller are therefore treated differently, and a test pins that.

The server normalises the same fields again, **before validation**, so a unique rule compares the
value that will actually be stored — otherwise `gaj` passes a uniqueness check against a stored
`GAJ` and collides on write. A form is not the only way a value arrives.

## What was verified, and where

**In a browser, against MySQL 8, at 1440 and 390 pixels:**

- Two unread → badge **2**; open one → **1**, that row's tint gone and the other's kept; open the
  second → badge **absent**; reload and navigate to another module → still absent.
- Typing `emp-0042` yields `EMP-0042`; inserting a character mid-string leaves the caret at
  position 5, not at the end; a paste uppercases; the full-name field is untouched. Both viewports.
- `/`, `/login`, `/dashboard` and `/my-work/login` all paint the splash with the bundle blocked, and
  it is gone after mount.
- Page titles render Archivo 800 at 23px; the document type band renders Archivo 800 at **75%
  width**; KPI figures render Archivo 800 with tabular figures.
- The rendered PTW PDF carries the embedded faces, with the issuer above the type band.

**Not verified: a real device push.** Unchanged from ADR 069 — headless Chromium has no push
service connection, so Android, iPhone and desktop background delivery still need confirming on a
device, and the VAPID key pair still has to be generated on the server (`php artisan ioms:vapid-keys`)
before push is offered at all.

---

See also: `docs/ARCHITECTURE.md` § Typography · `docs/CONVENTIONS.md`

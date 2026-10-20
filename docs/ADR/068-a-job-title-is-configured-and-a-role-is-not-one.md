# ADR 068 — A job title is configured, and a role is not one

- **Status:** Accepted
- **Date:** 2026-10-20
- **Release:** v2.128.0
- **Extends:** [[065-a-stamp-is-an-action-record-and-its-title-belongs-to-the-company]],
  [[067-a-permit-is-verified-and-then-approved]]
- **Related:** [[062-a-my-work-account-is-an-employees-login]],
  [[061-an-offer-is-bound-to-what-the-server-allows]]

---

## The decision

**A role decides who may press the button. A job title is what the paper says. They are
different kinds of fact and the second is never derived from the first.**

A VERIFIED seal printed `HSE` and an APPROVED seal printed `MANAGER`. Those are IOMS permissions.
Nobody's job is "hse", no customer words a job that way, and a reader of a controlled document
recognises an authority by the yard's own title: *HSE Officer*, *Supervisor HSE*, *Manager
Shipyard*. The owner rejected it, correctly.

v2.125.0 had already read the title from the company's own data **where it existed** —
`positions.name`, reached account → employee → position, snapshotted into
`activity_logs.actor_title` at the moment of signing. What was missing is the case that actually
produced the complaint: **an account with no linked Employee**, which has no position and fell
through a third branch to `roleLabel()`. An administrator, or a head-office approver, is exactly
that account.

So:

1. **The `roleLabel()` fallback is gone.** Not replaced with a better word — removed. A seal states
   a record or it states nothing (ADR 065), and with no title available it now prints no title line.
2. **Each stage carries a configured title**, `ptw_workflow_settings.verify_title` and
   `approve_title`, set per operating unit in Settings → Permit to Work Workflow beside the roles
   and people that stage is routed to. Free text, because an enum here would be IOMS deciding what
   a yard calls its people.
3. **It is snapshotted at action time** into the signing activity row's `meta.stage_title`, so
   re-wording the configuration afterwards cannot rewrite a permit already filed — the same rule
   `actor_name` (v2.100.0) and `actor_title` (v2.125.0) follow, and for the same reason.

The chain, each step a real record:

```
actor_title (the POSITION held when they signed)
  → meta.stage_title (the COMPANY'S title for that stage, same moment)
    → the signer's current position        (for a permit signed before either column existed)
      → the stage title as configured now  (same case)
        → nothing
```

A position outranks the stage title deliberately: a position is a configured job title too, and it
is the more specific one — it says what *this person* is, not what whoever signs this stage is
usually called.

## Authority is untouched, and that is the point

These two columns are **labels**. Who may verify and who may approve is still `verify_roles` /
`verify_user_ids` and `approve_roles` / `approve_user_ids`, asked through `PtwWorkflowService` by
both the gate and the button (ADR 061, ADR 067). Naming a title cannot widen or narrow a single
permission, and a test asserts that configuring one grants nobody anything.

The two facts now live in two fields on the same panel, which is the clearest statement of the
distinction the product can make: *who is allowed to do this* and *what we call the person who
does it*.

## What else was found, and could not be left

`is_active` on a user row was checked in exactly **two** places, both of them sign-in forms. So
deactivating an account took effect at its next sign-in, and an account already signed in carried
on until its session happened to expire. Remember Me (below) turns "until their session expires"
into "for up to five years", which makes a believed-in control that does not actually revoke.
`EnsureAccountIsActive` now runs first in the web middleware group: an inactive account is signed
out on its next request, which also cycles `remember_token` and so kills every remembered device.

## Remember Me, and the three promises around it

A My Work account signs in with a badge number and an 8-character access code (ADR 062), on a
phone, in a yard, several times a shift. That is the kind of friction that ends with the code
written inside a helmet, so the form offers to be remembered — through `Auth::login($user, true)`
and Laravel's own remember token. Nothing home-made: the cookie carries the user id and a random
60-character token from the `users` table, never the code and never a hash of it (pinned by a
test).

What makes it safe to offer is what revokes it: signing out cycles the token, an access-code reset
cycles it, and `EnsureAccountIsActive` ends a remembered session the moment the account is switched
off. And signing out now returns a worker to `/my-work/login` rather than to the email-and-password
form they cannot use — one logout served both classes and sent everybody to the wrong door.

## The chrome and the first paint

Three things a phone shows before the application does, all reported as "a black area":

- **`theme_color` was the product navy.** Android paints the status bar with it, so an installed
  IOMS opened to a dark band above a white app. v2.73.0 chose navy so "the OS chrome matches the
  app rather than framing it in white"; on a handset that reasoning inverts, because the chrome is
  *above* the app rather than around it. White now, and `background_color` with it, so the platform
  splash, the boot splash and the application are one colour and the handover has nothing to show.
- **`apple-mobile-web-app-status-bar-style` was `black-translucent`**, which draws white status-bar
  glyphs over the page. Over a white ground that is white on white. `default` now.
- **There was no boot splash at all.** Inertia SSR is off, so the body the server sends is an empty
  div: between the HTML arriving and React mounting the screen is blank, and inside an installed
  app — no address bar, no spinner, no chrome — blank reads as a crash. The splash is now rendered
  by the **server**, in the same response, because React is the thing being waited for. It comes
  down when React mounts, with a timeout as the only other path, and is hidden entirely for a
  client that will never run either.

The artwork is a **stacked** lockup composed from the official assets — the cyan symbol from
`ioms-icon.svg` and the navy word from `ioms-logo.svg`, placed in nested `<svg>` elements carrying
their source viewBoxes. **No path was redrawn**, and a test fails if one ever is: it matches a path
from each source file verbatim against the composition. A stacked file exists because the official
lockup is 3.85:1 and a phone is portrait. Both boxes were MEASURED with `getBBox()` in a real
renderer rather than read off the viewBox, because each source viewBox is padded and the padding
differs.

## A one-line field whose example does not fit on one line

Two PTW fields carried a long worked example as an `<input placeholder>`. An input's placeholder is
one line and clips: on a phone the reader got *"e.g. Hull Block A-12 / Crush"*. So the example was
repeated **underneath** as helper text — the same sentence twice, once broken and once whole.

`ExampleInput` is a single-line field whose placeholder wraps and whose box grows to hold it. Three
obvious fixes were rejected and the reasons are in the component, but one is worth repeating here
because it is the trap: **a `<textarea>` autosized by `scrollHeight` does not work**, because the
scrollHeight of an *empty* textarea is one row however long its placeholder is — and the placeholder
is the only thing on screen at that moment. It uses a mirror element instead, the oldest autosize
technique and the only one that can measure text the field is not holding: an invisible div renders
the value, or the placeholder when there is none, with the same typography and width, and the field
is laid over it. No measuring in JavaScript, nothing to re-run on a font swap or a rotation.

It stays a single-line field: Enter cannot insert a newline, and pasted newlines collapse to spaces,
so the submitted value is what an `<input>` would have submitted.

## The permit letterhead

`.doc-company-name` was 14px — **smaller than the document's own title** (`doc-title-main`, 16px)
and barely above the section headings. On the rendered sheet the first thing a reader's eye found
was the words "HOT WORK" and the name of the company whose permit it is came third. 19px now, with
the two lines under it nudged so the block still reads as one group. Only those three rules moved;
the title band, status bar, section heads and every field keep the sizes they were tuned to. On
screen the same name also carried `truncate`, which clipped a legal name mid-word on a phone — a
company's name is not a label to be shortened, so it wraps.

## How it was verified

Driven in a real browser against MySQL 8 with synthetic data, at 390 and 1440 pixels, and by
looking at the rendered artefacts:

- The splash, caught with the bundle blocked: white ground, official stacked lockup, minimal bar,
  removed the frame React mounts. **One defect found this way**: a bare `svg` selector in the
  splash CSS overrode the `x`/`y`/`width`/`height` of the lockup's nested elements, so the first
  render drew the symbol at full width, off centre, with the word missing entirely — invisible in
  the markup, obvious in a screenshot.
- Remember Me, end to end: checked → the recaller cookie is issued and reopening the app lands on
  My Work; unchecked → no cookie and the form returns; signing out → cookie gone and `/my-work`
  refused.
- The PTW form at 390px: the example wraps to four lines inside the box with nothing clipped, the
  box returns to one line as soon as a value is typed, Enter inserts nothing, and the duplicated
  helper lines are gone.
- A permit driven through both gates with `HSE Officer` and `Manager Shipyard` configured: the
  verifier had a position (`SPV HSE`) and it won; the approver had none and the configured stage
  title was snapshotted and printed. The re-rendered PDF shows the company name dominant and the
  two seals naming a job rather than a permission.

**Not verified:** iOS. There is no iPhone on this machine. The boot splash and the chrome metas are
the implementation for it and are standards-based, but nobody has looked at this release on an
Apple device, and `apple-touch-startup-image` — the platform splash shown before the page loads in
an installed iOS app — is deliberately **not** shipped: it needs device-exact PNGs that cannot be
checked here, and without it iOS shows white, which is now exactly the splash's own ground.

---

See also: `docs/MODULES.md` § Permit to Work · `docs/CONVENTIONS.md`

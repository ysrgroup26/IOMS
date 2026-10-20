# ADR 069 — A notification arrives without being asked for

- **Status:** Accepted
- **Date:** 2026-10-20
- **Release:** v2.129.0
- **Related:** [[064-a-notification-is-a-destination-not-a-sentence]],
  [[067-a-permit-is-verified-and-then-approved]],
  [[061-an-offer-is-bound-to-what-the-server-allows]]

---

## The problem

Notifications were real, stored, routable and correct — and invisible until the page was
navigated. Every count and every row reached the browser through
`HandleInertiaRequests::share()`, which runs when a page is *visited*. So a permit that came back
for correction while somebody sat on the dashboard existed, was addressed to them, had a working
destination, and said nothing until they clicked something else.

## The decision

Two channels, chosen against the deployment IOMS actually has:

| While | Channel | Mechanism |
|---|---|---|
| a tab is open | **live sync** | a cursor-based delta endpoint, asked every few seconds while the tab is *visible* |
| the app is in the background or closed | **web push** | the browser's own push service, VAPID, self-hosted |

And one producer behind both: `NotificationService::notify()`. Every module that calls it gets
real-time delivery and background push without writing a line — which is what "a foundation rather
than a PTW feature" has to mean.

## Why not a WebSocket server

Laravel Reverb is the obvious answer and it is the wrong one *here*. It needs a long-running
process and an open port. IOMS deploys to **cPanel shared hosting** — `deploy.sh` detects
`/opt/cpanel/ea-php*`, and Inertia SSR is off in this product precisely because no Node process
runs there. A socket server cannot be installed on that host by anybody, including the owner.

A hosted provider (Pusher, Ably) would work: real sockets, no infrastructure. It was rejected for
reasons that are commercial rather than technical, and they are the owner's reasons rather than
mine to assume away — an account to create, credentials to hold, a per-message price list, a free
tier with a connection cap that a yard of sixty would sit against, and a dependency whose outage
is IOMS's outage. Nothing in this release needs one. `config('realtime.transport')` names the
transport rather than hiding it, so a provider can be added later as a third value without
rewriting anything above it.

**What this costs, stated plainly:** a notification can be up to five seconds late while a tab is
visible and active, up to thirty when the tab has been idle for two minutes, and arrives by push
rather than by poll when the tab is hidden. Every request is one indexed lookup on
`notifications.user_id` returning, almost always, nothing.

## Why a cursor, and the defect that proved it

The client asks *"what has happened since id N?"* — never *"give me the latest ten"*. The cursor is
what makes a dropped connection harmless: a tab that was asleep, offline, or behind a captive
portal asks with the cursor it still has and receives exactly what it missed, in order, once.
Nothing is lost, because the rows were written to the database before anybody was told about them.
Nothing is doubled, because the cursor only advances past rows that actually arrived.

**Absent is not zero**, and reading them as the same thing was a real defect, found by a test
rather than by reading. A *first* call — no `cursor` parameter at all — must not replay a person's
history as new; it answers with the counts and the id to start from. But `cursor=0` is a different
statement, "I have seen nothing, tell me about anything", and it is exactly what every account with
no notifications yet is told to send. Conflating the two meant **live notifications silently did
not work for anybody who had never received one** — the people most likely to be new to the
product.

## Why web push carries nothing

The push is a **tickle**: no payload. The service worker, woken by it, fetches the notification
over the person's own authenticated session and shows what comes back.

1. **Nothing operational crosses a lock screen that the server did not just authorise.** A payload
   is sealed to the device, but it is composed at *send* time — so a permit's details would sit on
   a phone that may since have been deactivated, handed over, or had its access narrowed. A fetch
   asks again, and `EnsureAccountIsActive` (v2.128.0) answers.
2. **The push service never sees anything.** With a payload it sees ciphertext; with a tickle there
   is nothing to see.
3. **Read state cannot drift.** The worker renders what is unread *now*, not what was true when the
   push was queued.

The cost is honest: a device that is online but signed out shows "IOMS — ada pemberitahuan baru"
and nothing more. That is a worse notification, not a wrong one.

## Why no push library, which was measured rather than assumed

`minishlink/web-push` is good and is needed for *encrypted* payloads — the ECDH + HKDF + AES-128-GCM
sealing a payload requires. A payloadless push needs one ES256-signed JWT, and PHP's own `openssl`
does that unaided: a probe generated a P-256 pair, expressed the public key as the 65-byte
uncompressed point the Push API expects, signed a token, and **verified it against only the
published public key** — the same check a push service performs. Confirmed by running it.

So the dependency was not added. A package pulled in for one JWT is a package whose transitive
requirements (GMP among them) must hold on a shared host nobody here can inspect.

## Why the open page is refreshed by Inertia and not by the payload

A permit's status on an open page is **never** patched from a notification. The notification says
only that something happened; `router.reload({ only: [...] })` re-runs the controller, and
therefore `assertInCurrentTenant()`, the entitlement middleware, both gates in
`PtwWorkflowService` and every scope on every query. The page updates without a full navigation
**and** without a second, thinner path to record state — which is where isolation defects live.

That left one gap, found in a browser: a page only refreshed when a notification arrived *for its
viewer*, so an approver who had just approved a permit saw nothing when HSE then activated it —
their open page sat on a stale status with a stale set of buttons. Closed with **change markers**: a
page may name the record it is showing and receive one opaque integer that moves when something
happens to it and says nothing about what. The state still arrives only through the partial
reload. The markers are:

- **allow-listed by type** (`WATCHABLE`), because the alternative is an endpoint that reports
  activity timing for any table an attacker cares to name;
- **scoped by company**, so naming another customer's permit returns nothing that distinguishes
  "no activity" from "not yours";
- **capped** at five per request.

## What delivery may never do

`NotificationService::deliver()` enforces two rules, and the second is a lesson this codebase has
already paid for once:

1. **Never before the commit.** `notify()` is called from inside workflow transactions — PTW holds a
   row lock while it records a decision. A push sent from inside one would announce a decision that
   can still be rolled back. `DB::afterCommit` defers to the real commit.
2. **Never at the caller's expense.** The HTTP request to a push service happens *after* the
   response is sent (`app()->terminating`), wrapped, so a dead endpoint costs the person who pressed
   Approve nothing. v2.127.0 found `HasWorkflow` destroying an approval because its notifier threw
   inside the caller's transaction; the lesson does not need learning twice.

**No queue worker is required**, which is a deployment fact rather than a preference: on cPanel a
queued job would sit in `jobs` until the next cron minute at best.

## The sound, and its four rules

Synthesised with Web Audio — two short sine notes through a gain envelope — rather than shipped as
an audio file, because 120 milliseconds of sound does not need a binary asset to commit, serve,
cache-bust and licence, and a blocked file fails in a way that is indistinguishable from the audio
policy refusing to play it. It is bounded by four rules, each a decision: **not without a gesture**
(every browser refuses audio until the page has been interacted with), **not twice in a breath**
(six permits cleared in one batch is one sound), **not for data** (a silent state refresh rings
nothing), and **not against the person's wishes** (a per-device preference, off by default under
`prefers-reduced-motion`).

## What was verified, and where

**In a browser, against MySQL 8, at 390 and 1440 pixels** — two concurrent signed-in sessions:

- An approver sitting on the dashboard, touching nothing: badge unchanged when a permit was
  *submitted* (they are not asked before a verification exists), then **4 → 5 with zero
  navigations** when HSE verified it, with a toast on screen and the row in the bell.
- An approver with the permit's own page open: the status badge went to **Active**, and the
  Activate button disappeared with it, when a *different session* activated it — zero navigations,
  and no toast, because nothing was being asked of them.
- **Offline for the length of two verifications**, then back: the badge was frozen at 5 while
  offline, caught up to 7 on reconnect, showed exactly two toasts, and the bell held five unique
  approval rows and no duplicates.
- The bell's device controls: sound on/off, and a push control that offers what the platform will
  actually honour.

**Server side, against a stand-in push service:** the POST carries `Authorization: vapid t=…, k=…`,
`TTL`, `Urgency: high`, a per-record `Topic`, and **a zero-length body**; a 201 records
`last_pushed_at`; a **410 deletes the subscription**; and a permit verified over real HTTP woke the
approver's registered device after the response.

**Two defects of my own were found by driving it rather than reading it:** `useWatchedRecord()`
silently did nothing on every page in the product (every page wraps its *own*
`<AuthenticatedLayout>`, so a page component is the **parent** of anything the layout provides —
a context published by the layout is invisible to the page inside it), and the bell reported "this
device receives IOMS notifications" from `Notification.permission` alone, while
`pushManager.subscribe()` was refusing.

## Not verified

**A real device push was never received.** Headless Chromium here has no push service connection —
`pushManager.subscribe()` answers *"Registration failed - permission denied"* — so the browser half
of push (subscription, `push` handler, `notificationclick`) has been exercised only in code and
against a stand-in service. Android Chrome, desktop Chrome/Firefox/Edge and an installed iOS 16.4+
PWA are where it has to be confirmed, on a device, by the owner. Until then the honest claim is:
**the sender works and the subscription flow refuses to claim more than it can prove.**

---

See also: `docs/ARCHITECTURE.md` § Real-time notifications · `docs/MODULES.md` § Notification Center

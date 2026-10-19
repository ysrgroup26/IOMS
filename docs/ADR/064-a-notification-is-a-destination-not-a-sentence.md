# ADR 064 — A notification is a destination, not a sentence

**Status:** Accepted · **Date:** 2026-10-19 · **Release:** v2.125.0

**Supersedes nothing.** Extends [ADR 011](011-notification-center.md) (the Notification Center) and
applies [ADR 061](061-an-offer-is-bound-to-what-the-server-allows.md)'s rule to a third kind of
offer.

---

## The report

> "Notifikasi untuk pekerja lapangan yang membuat atau mengajukan PTW saat ini hanya menampilkan
> teks dan tidak berfungsi untuk melanjutkan tindakan."

Exactly true, and the reason is one argument in one call.

## What was actually wrong

The delivery side was complete. `NotificationService` stored a `url`. The menu in
`AuthenticatedLayout.jsx` read it and navigated:

```js
if (notification.url) { router.visit(notification.url); }
```

And `notifications.read` / `notifications.read-all` were real endpoints writing a real column, so
read state already survived a reload.

Both **producers** passed `null`:

```php
// HasWorkflow::notifyStatusChange()
app(NotificationService::class)->notify($recipient, $category, $title, $meta['comments'] ?? null, null, $this);
//                                                                                              ^^^^
// ApprovalEngine::notifyApprovers() / notifyApprover() / the escalation pass — the same null.
```

So `if (notification.url)` was never true for any workflow notification in the product. The row
appeared, the tap marked it read, and nothing opened. The person then went looking for the permit by
hand — which is what "tidak berfungsi untuk melanjutkan tindakan" describes.

This had been true since Milestone 3. It was invisible because every surface that *displays* a
notification was correct, and the one line that decides whether there is anywhere to go lived in a
different file from the one that does the going.

## The decision

**A model names the route that shows one of its records, and nothing else names it.**

```php
// App\Concerns\HasWorkflow
public function notificationRouteName(): ?string { return null; }   // the default, and a safe answer
public function notificationUrl(): ?string { /* route($name, $this), or null */ }
```

Thirteen workflow models plus `PermitToWork` declare theirs explicitly. Not derived from the class
name: `JobSafetyAnalysis` is `job-safety-analyses.show` and `IncidentInvestigation` is
`investigations.show`, so a derivation rule would be right eleven times and silently wrong twice —
and silently wrong is the failure this ADR exists to stop repeating.

**Null stays legal.** A notification that opens nothing is better than one that opens a refusal,
because a 403 reached by tapping a notification reads to the person as the account being broken.

### And the offer is bound to what the server allows

ADR 061 established that anything which OFFERS a link must ask the same question the gate that would
refuse it asks, from the same source. A notification is an offer. So the row carries its route
**name** (`meta['route']`), the shared prop exposes its first dot-segment as `route_prefix`, and the
menu checks it through `isRouteBlockedForUser()` — the same function the sidebar and the dashboard
tiles use, against the same `allowed_route_prefixes` / `blocked_route_prefixes` the middleware
publishes.

A row that can be opened says `Buka`. A row that cannot says so and still marks read. Neither is a
guess about the server's answer; both are the server's answer.

## What this is not

- **Not a routing table in the notification layer.** The service still stores whatever url it is
  given. The models decide; the service transports.
- **Not a general page.** "Jangan mengarahkan seluruh notifikasi ke halaman umum" — a status change
  opens *that* record, which is why the route is per model and takes the model as its parameter.
- **Not a new notification channel, recipient rule, or category.** `notificationRecipient()` is
  unchanged, including its deliberate refusal to tell somebody what they just did themselves.

## The second defect in the same code

`class_basename($this)` built the title, so the product sent **"PermitToWork PTW-2026-00001 is now
Submitted"** to a worker holding a phone, and wrote **"PermitToWork moved from Draft to Submitted."**
onto the permit's own activity timeline — the surface an auditor opens.

Three copies of one fault: a stored identifier reaching customer copy with nothing between the two.
`docs/CONVENTIONS.md` already records this shape for Settings' module keys, where underscores were
swapped for spaces and CSS `capitalize` produced "Hse Inspection" and "Ptw".

`HasWorkflow::documentLabel()` is now the one place that decides what a record is called
(`Str::headline(class_basename())` by default; `PermitToWork` overrides it with the product's own
"Permit to Work"), and `statusLabel()` the one place that decides how a status reads. The timeline,
the notification and the approval request therefore cannot disagree.

## Consequences

- Every workflow notification in the product became clickable in one release, not only PTW's. That
  was deliberate: "notifications do nothing" was never a PTW problem.
- A new workflow model gets no destination until somebody declares one, and
  `PtwFieldRevisionTest::test_every_workflow_model_names_a_real_destination_route()` fails until
  they do — including if the route name is a typo, because it resolves the name against the real
  route table.
- Adding a route to a restricted class's allow-list now changes which notifications it may open, in
  one place, which is the point.

## Evidence

Browser, MySQL 8, a My Work account at 390px: the menu reads
`Permit to Work PTW-2026-00001 is now Approved · just now · Buka`, tapping it lands on
`/permits-to-work/1`, and the read state survives a reload. Before the release the same flow
produced a row that did nothing.

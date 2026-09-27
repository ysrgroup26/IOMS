---
title: Support Inbox
type: backlog
status: IN PROGRESS — built; ingestion BLOCKED
updated: 2026-09-27
tags: [kb/backlog, status/implemented, status/blocked]
---

# Support Inbox

**Source:** `docs/FUTURE IDEAS/PROMPT 1.md` §7–§12 · **Board:** [[Project Board]]

## What was asked

Email to `support@iomsuite.com` becomes a native support conversation inside Master Admin — a queue
of work needing action, not an email viewer. Statuses OPEN / IN PROGRESS / WAITING FOR CUSTOMER /
RESOLVED / CLOSED, a **Needs Reply** primary queue, priority, assignment, thread history, customer
and subscription context, and replies that go out as real email from IOMS Support.

## State of the repository — built in v2.80.0, except ingestion

When this note was written nothing existed. Now everything except the inbound transport does.

| Asked for | State | Where |
|---|---|---|
| Five states OPEN / IN PROGRESS / WAITING FOR CUSTOMER / RESOLVED / CLOSED | **Done** | `SupportTicket::STATUSES` |
| Default queue focused on what needs a reply | **Done** — `needsAction()` is the default tab; *waiting on customer* is separate | `PlatformSupportController::index()` |
| Customer reply returns the ticket to active | **Done**, automatically, even from resolved or closed | `recordCustomerMessage()` |
| Support reply moves it to waiting-for-customer | **Done**, automatically | `recordSupportReply()` |
| Priority, assignment, ticket age | **Done** — age is **derived** from the last customer message, not stored | `ageInHours()` |
| Customer / tenant context, subscription and payment context | **Done**, from the shared lifecycle snapshot | `Platform/Support/Show.jsx` |
| Conversation history | **Done** | `support_ticket_messages` |
| Identified sender linked to a tenant | **Done** — exact email match, never a domain guess | `SupportTicketIntake::identifyTenant()` |
| Unidentified sender queued for manual association | **Done**, with re-identification for accounts created later | `associate()` |
| Replies from `support@iomsuite.com` on the IOMS email design system | **Done** | `SupportTicketReply`, `emails/support-reply.blade.php` |
| Not merged with platform notifications | **Done**, and asserted | ADR [[042-support-queue-is-not-an-inbox\|042]] |

Verified: `SupportTicketQueueTest` (16 tests) and in a browser against MySQL — a real reply was
sent, logged from `IOMS Support <support@iomsuite.com>` with `[TKT-000001]` in the subject, and the
ticket moved to *Menunggu pelanggan*.

Status: `#status/implemented` for the product, `#status/blocked` for the one remaining piece.

## Still BLOCKED: how does mail get in?

Email to `support@iomsuite.com` does **not** become a ticket by itself. This is not a detail — it
decides the whole ingestion design, and none of the options can be chosen from inside the
repository:

| Option | What it needs | Trade-off |
|---|---|---|
| **IMAP polling** of the support mailbox | Mailbox credentials; a scheduled job | Works with the existing cPanel mailbox; polling lag; credentials to keep |
| **Inbound webhook** from a mail provider (Mailgun, Postmark, SES) | An account and DNS/MX routing | Near-instant, signed, well-documented; a new external dependency |
| **Forwarding address** into a provider's inbound parser | Forwarding rule | Cheapest; the weakest thread fidelity |

> [!question] Decision needed from the owner
> Which route does mail take into IOMS? Everything else — threading, attachments — follows from it.

### What is already in place for whichever route is chosen

- **One entry point.** `SupportTicketIntake::record()` does identification and threading. The
  adapter will be a thin caller, not a second implementation of the status rules — which is why
  those rules live on the model.
- **A reference in the subject.** Outgoing replies carry `[TKT-000123]`, which survives forwarding
  and quoting, so an adapter has something to thread on from day one.
- **A manual path meanwhile.** An operator logs an incoming message through the same service, and
  the queue page states on screen that the mailbox does not drain into it — rather than letting
  anyone assume it does.

## Design notes worth keeping before implementation

- **Threading is the hard part.** A reply must rejoin its conversation, not open a new ticket.
  `Message-ID` / `In-Reply-To` / `References` plus a token in the reply-to address is the usual
  answer; the subject line alone is not reliable.
- **Reuse rather than invent:** the outgoing mail layout (`emails/layout.blade.php`) and the sender
  identity (`noreply@` vs `support@`, per `config/ioms.php`). Replies must come from
  `IOMS Support <support@iomsuite.com>`, never a staff member's own address.
- **Support ≠ notifications.** They stay separate surfaces (ADR
  [[040-master-admin-is-an-operations-console\|040]]).
- **Tenant isolation:** a support conversation belongs to the platform, not to a tenant, and must
  never expose one customer's message to another. An unidentified sender goes into its own queue for
  manual association rather than being guessed into an organization.
- **Customer context, compact:** organization, plan, subscription status, period end, last payment —
  all already derivable from existing models.
- Assignment exists to stop two agents answering the same ticket; keep it that simple.

## Size — revised after building it

The estimate said "the largest single item on the board … its own milestone". Two thirds of it
turned out to be ordinary domain work: two tables, a model carrying its own state rules, a queue
and a reply. What is genuinely hard is the part still blocked — **threading inbound mail
reliably** — and that is hard because of the transport, not because of IOMS.

Attachments are still not handled in either direction, and are deliberately out of scope until
ingestion exists: an attachment model with nothing to receive attachments would be speculation.

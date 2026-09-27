---
title: Support Inbox
type: backlog
status: future, blocked on one decision
updated: 2026-09-27
tags: [kb/backlog, status/planned, status/blocked]
---

# Support Inbox

**Source:** `docs/FUTURE IDEAS/PROMPT 1.md` §7–§12 · **Board:** [[Project Board]]

## What was asked

Email to `support@iomsuite.com` becomes a native support conversation inside Master Admin — a queue
of work needing action, not an email viewer. Statuses OPEN / IN PROGRESS / WAITING FOR CUSTOMER /
RESOLVED / CLOSED, a **Needs Reply** primary queue, priority, assignment, thread history, customer
and subscription context, and replies that go out as real email from IOMS Support.

## State of the repository — nothing exists yet

Audited: there is no ticket, conversation or message model; no inbound mail handling; no support
routes. IOMS **sends** mail (`app/Mail/*` on a shared layout) and has never **received** any.

Status: `#status/planned`, and the first step is `#status/blocked`.

## The blocking decision: how does mail get in?

This is not a detail — it decides the whole ingestion design, and none of the options can be chosen
from inside the repository:

| Option | What it needs | Trade-off |
|---|---|---|
| **IMAP polling** of the support mailbox | Mailbox credentials; a scheduled job | Works with the existing cPanel mailbox; polling lag; credentials to keep |
| **Inbound webhook** from a mail provider (Mailgun, Postmark, SES) | An account and DNS/MX routing | Near-instant, signed, well-documented; a new external dependency |
| **Forwarding address** into a provider's inbound parser | Forwarding rule | Cheapest; the weakest thread fidelity |

> [!question] Decision needed from the owner
> Which route does mail take into IOMS? Everything else — threading, replies, attachments — follows
> from it.

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

## Size

The largest single item on the board: new tables, inbound mail infrastructure, a queue UI, an email
threading model, and its own security surface. It should be its own milestone, not folded into
another release.

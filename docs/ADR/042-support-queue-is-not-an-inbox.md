---
title: 042 — The support queue is not an inbox
type: adr
status: accepted
decided: 2026-09-27
version: 2.80.0
tags: [adr, status/implemented, support, platform]
---

# ADR 042 — The support queue is a work list, not a mailbox

## Status

Accepted and implemented in **v2.80.0**. The domain, the queue and outgoing replies are
built and tested; **automated inbound mail ingestion is BLOCKED** — see *What is
deliberately missing*.

## The problem

Customer support happened in somebody's email client. Nothing recorded that a customer
had asked something, whether anyone had answered, who owned it, or how long it had been
waiting. The questions an operator actually has —

- *what is unanswered right now?*
- *who has been waiting longest?*
- *whose problem is this, and is their subscription the reason?*

— have no answer in an inbox, because an inbox is ordered by arrival and has no concept
of owing somebody a reply.

## The decision

A ticket is a **state machine with a conversation attached**, not a copy of an email
thread.

```
        customer writes                        support replies
  ──────────────────────▶  open  ──────────────────────▶  waiting_customer
                            ▲  │                                │
       customer writes again │  │ operator picks it up           │ customer writes again
                            │  ▼                                │
                            │  in_progress ───────────────┐     │
                            │                             ▼     ▼
                            └──────────────────  resolved / closed
```

Five states: `open`, `in_progress`, `waiting_customer`, `resolved`, `closed`.

### The two transitions that carry the design are automatic

| Event | Effect | Why not manual |
|---|---|---|
| A **customer** message arrives | → `open`, even from `resolved` or `closed` | A customer writing again is the clearest possible statement that the matter is not finished. Making them raise a second ticket to say so loses the history of the first |
| **Support** replies | → `waiting_customer` | The failure mode of a manual status is a ticket parked in the wrong one. If the operator has to remember, the queue is wrong by the end of the week |

They live on the **model**, not in the controller, because there will be more than one
way a message arrives and a rule that lives in one controller is a rule the second entry
point forgets.

### The default view is the work, not everything

The queue defaults to `open` + `in_progress` — the states that **owe somebody an answer**
— ordered by *how long they have waited*, oldest first. `waiting_customer` is a separate
tab, because a ticket waiting on the customer is not our work.

Deliberately **not priority-first**: an urgent ticket raised a minute ago must not push a
normal one that has been ignored for three days further down. Priority breaks ties; it
does not replace the clock.

**Ticket age is derived, never stored**, and measured from the last *customer* message
rather than from creation — a three-week conversation answered an hour ago is not three
weeks old as work. It is null once the ticket owes nobody anything, the same rule
material-request ageing already follows.

### Identification is a lookup, never a guess

The sender is matched against real user accounts **by exact email address**. Nothing is
inferred from the mail domain: two customers can share a provider, and attaching a ticket
to the wrong tenant would show one customer's commercial context beside another
customer's question.

An unmatched sender stays **unidentified** and goes in its own queue for a human to
associate. That is a supported state, not a failure — and it is the reason the table is
not tenant-scoped (below).

### Commercial context, where it is relevant

The ticket page shows the requester's organization, the **derived** subscription state
and its recent invoices. This is the reason the page exists rather than a mail client: an
operator answering *"why can I not save anything?"* needs to see that the tenant is
read-only, beside the question.

It renders the **same shared snapshot** (`Subscription::stateSnapshot()`, v2.80.0) the
customer's own Billing page does, so support can never quote a state the customer does
not see.

### Support and Notifications stay separate surfaces

A **notification** is something IOMS tells its operator about its own business (a
renewal, a grace period, a failed payment). A **ticket** is somebody waiting for an
answer. Merging them buries the second in the first: an event feed is read casually and
a queue has to be worked. `SupportTicketQueueTest` asserts a ticket never writes to the
notification table.

### Platform-owned, and not tenant-scoped

`support_tickets.tenant_id` is nullable and carries **no global scope** — the opposite of
every operational table in IOMS. It has to be:

- a message from an unrecognised address **has no tenant yet**, and a scope would hide
  precisely the rows that need human attention;
- the operator who works the queue has no tenant of their own, so a tenant scope would
  fail closed and show them nothing.

Access is therefore enforced by **role at the route** (`role:platform_admin`), and that is
asserted rather than assumed: a tenant administrator is refused both the queue and an
individual ticket — including one raised by their own colleague, because reading the queue
would mean reading other customers' conversations. The two models are listed in
`TransitiveTenantIsolationTest`'s `$declaredGlobal` with this reasoning, so the exception
is recorded where somebody auditing isolation will find it.

### Replies come from support@, not noreply@

The one mailable in IOMS that is not from the noreply mailbox, and that is the point: a
support answer exists to be replied to. From and Reply-To are both `support@iomsuite.com`,
and the ticket reference is in the **subject** (`[TKT-000123] …`) because a subject token
survives forwarding and quoting where a custom header does not.

The reply is **recorded first and emailed second**. A mail failure loses the message from
the customer's inbox but never from the ticket, and `sent_at` stays null — which the
conversation renders as *belum terkirim* rather than pretending an answer landed.

## What is deliberately missing — BLOCKED

**Email to support@ does not become a ticket by itself.** How mail reaches the
application is an infrastructure decision that has not been made:

| Option | Needs |
|---|---|
| IMAP polling | Mailbox credentials, a scheduled worker, a read/seen strategy |
| Inbound-mail webhook | A provider (Mailgun / Postmark / SES) and its signature scheme |
| Forwarding to an application address | DNS and a parsing endpoint |

Inventing one would produce code that looks finished and fails on first contact, so it is
not written. Until it is decided, an operator **logs** an incoming message — through the
same `SupportTicketIntake` service the future adapter will call, so no status rule has to
be reimplemented — and the queue page says so on screen rather than letting anyone assume
the mailbox drains into it.

## Consequences

- One more place to look, on purpose. The alternative was a place where nothing was
  recorded at all.
- Tickets are **kept**, not deleted, when closed. A support history is evidence.
- Only a platform operator can be assigned a ticket; assigning a customer's own
  administrator is refused with a 422 rather than quietly accepted by an
  `exists:users,id` check.

## Related

ADR [[040-master-admin-is-an-operations-console|040]] (the console this lives in, and why
it is in Indonesian) · ADR [[033-subscription-lifecycle|033]] (the state the context panel
renders) · [[Support Inbox]] · [[Project Board]]

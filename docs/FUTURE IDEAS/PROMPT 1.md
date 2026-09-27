# IOMS — FUTURE PROMPT 01
## Master Admin + Subscription Operations + Support System

> STATUS: FUTURE
> PROJECT: IOMS
> EXECUTION: Claude Code → Claude Opus 5 → High
>
> IMPORTANT:
> Before implementation, inspect the current IOMS repository and verify the actual current state. Do not assume previous audit findings or implementation details are still unchanged.
>
> Claude must reuse existing architecture where appropriate, avoid duplicate concepts, test the implementation, update relevant documentation, commit, and push to GitHub main.
>
> DO NOT TOUCH CPANEL.

---

# 1. FINAL SUBSCRIPTION LIFECYCLE

Implement and verify:

ACTIVE
→ H-7 RENEWAL REMINDER
→ EXPIRY
→ 7-DAY FULL-ACCESS GRACE PERIOD
→ READ-ONLY / LAPSED
→ RENEW
→ ACTIVE

## H-7

At H-7:

- Send renewal reminder email.
- Show in-app/top notification/banner.
- Clearly show remaining subscription time.
- Provide Renew Subscription CTA.

## 7-DAY GRACE PERIOD

During the 7-day grace period:

- Customer retains normal/full operational access.
- Show a clear renewal warning.
- Show remaining grace time.
- Provide Renew CTA.

## AFTER GRACE

After the 7-day grace period:

- Tenant becomes read-only/lapsed.
- User can still authenticate.
- Appropriate existing data remains viewable.
- Mutation operations must be blocked server-side.
- Do not rely only on frontend button hiding.
- Do not delete operational data.

A verified renewal after lapse must restore normal access.

---

# 2. RENEWAL DATE RULE

If customer renews before the current subscription period ends:

Current period:

1 Sep → 30 Sep

Payment:

25 Sep

New period:

1 Oct → 30 Oct

DO NOT calculate:

25 Sep → 25 Oct.

The customer must not lose remaining paid time.

If renewal happens after the period has ended, follow the existing subscription lifecycle rules.

Preserve:

- agreed-price snapshots;
- billing-cycle rules;
- upgrade/downgrade rules;
- existing subscription history.

Duplicate webhook/payment events must never extend a subscription twice.

---

# 3. RENEWAL EMAIL / IN-APP REMINDERS

Create a sensible reminder lifecycle around H-7 and expiry.

At minimum:

- H-7 reminder.
- Approaching-expiry reminders where useful.
- Expiry/grace notification.
- Read-only notification.

Avoid unnecessary email spam.

When renewal succeeds:

- Renewal warning disappears.
- Subscription remains/restores ACTIVE.
- Entitlement remains correct.
- Normal payment-success/invoice email is sent.
- Customer continues using the same tenant.
- Existing operational data remains untouched.
- Renewal must not create a new tenant.

---

# 4. MASTER ADMIN — SAAS OPERATIONS CENTER

Redesign Master Admin so it functions as a SaaS Operations Center rather than a generic CRUD dashboard.

Master Admin answers:

"How is the IOMS platform operating?"

Tenant Dashboard answers:

"How is my company operating?"

Do not turn Master Admin into a tenant operational dashboard.

Master Admin UI must use BAHASA INDONESIA.

Include meaningful operational information such as:

- total organizations/tenants;
- active subscriptions;
- expiring soon;
- grace period;
- read-only/lapsed;
- new subscriptions;
- renewals;
- upgrades;
- payment activity;
- pending/failed payments;
- recent platform activity;
- important support workload.

Do not simply add many cards.

Use meaningful information hierarchy and operational visualization.

---

# 5. PAYMENT ↔ SUBSCRIPTION ↔ TENANT

Master Admin must clearly connect:

Tenant
→ Subscription
→ Invoice
→ Payment
→ Gateway Event

and:

Payment
→ Subscription
→ Tenant

Tenant detail should make subscription/payment status understandable without forcing Master Admin to search unrelated screens.

Show appropriate information:

- organization;
- current plan;
- subscription status;
- current period;
- period end;
- billing cycle;
- agreed/current price;
- last payment;
- payment status;
- renewal status;
- relevant payment/invoice history.

Important events should appear in Master Admin activity/notification system:

- New subscription.
- Subscription renewed.
- Plan upgraded.
- Payment successful.
- Payment failed.
- Subscription expiring.
- Grace period started.
- Tenant became read-only.
- Subscription reactivated.

---

# 6. MASTER ADMIN HEADER

Master Admin header must contain two clearly separate attention areas:

1. SUPPORT
2. NOTIFICATIONS

Do NOT mix customer messages with system notifications.

Example:

Support 8
Notifications 5

Exact visual implementation should follow the existing IOMS design system.

---

# 7. SUPPORT INBOX — NATIVE SUPPORT TICKETING

support@iomsuite.com is the customer support address.

Customer emails sent to support@iomsuite.com must be represented inside Master Admin as support conversations/tickets.

Do NOT build this as a simple email viewer.

Build a native support queue/conversation model.

Statuses:

- OPEN
- IN PROGRESS
- WAITING FOR CUSTOMER
- RESOLVED
- CLOSED

The default support view should prioritize work requiring action.

Core principle:

"Support Inbox shows work that needs to be done, not every email ever received."

---

# 8. SUPPORT QUEUE

Primary working queue:

NEEDS REPLY

This contains conversations requiring a response/action from IOMS support.

When Master Admin replies:

Needs Reply
→ Waiting for Customer

When customer replies:

Waiting for Customer
→ Open / Needs Reply

Resolved/Closed tickets must not clutter the active working queue.

Support should support:

- priority;
- ticket age;
- assignment;
- unassigned tickets;
- conversation/thread history;
- customer/organization context;
- subscription context;
- payment context when relevant;
- attachments where appropriate.

Priority:

- Urgent
- High
- Normal
- Low

Do not invent an overcomplicated AI prioritization system.

---

# 9. SUPPORT ASSIGNMENT

Support must support multiple Master Admin/support staff.

Support tickets must support:

- Assigned to
- Unassigned
- My Tickets
- All Active Tickets

Avoid two support agents unknowingly replying to the same ticket.

---

# 10. CUSTOMER CONTEXT

When opening a support conversation, Master Admin should see useful customer context.

For identified customers:

- Organization
- Plan
- Subscription status
- Period end
- Relevant payment status
- Recent related events

Keep this compact.

If sender cannot confidently be associated with an organization:

- Put message into unidentified support state/queue.
- Allow Master Admin to associate it manually.

---

# 11. EMAIL BEHAVIOR

Support replies must be real email replies.

Customer should see:

IOMS Support <support@iomsuite.com>

Not a private Master Admin email address.

Outgoing support email must use the existing IOMS email design system.

Customer replies must remain in the same conversation/thread.

Do not create unrelated tickets every time the customer replies.

Preserve email subject/thread identity appropriately.

---

# 12. SUPPORT VS SYSTEM NOTIFICATIONS

SUPPORT:

Customer-originated communication requiring human action.

NOTIFICATIONS:

Internal platform/business events.

Examples:

- New organization.
- New subscription.
- Renewal.
- Successful payment.
- Failed payment.
- Upgrade.
- Expiry approaching.
- Grace period.
- Read-only transition.
- Reactivation.

Keep Support and Notifications separate.

---

# 13. MASTER ADMIN INFORMATION ARCHITECTURE

Conceptually:

Master Admin
├── Dashboard
├── Organizations
├── Subscriptions
├── Payments
├── Support
│   ├── Needs Reply
│   ├── In Progress
│   ├── Waiting for Customer
│   ├── Resolved
│   └── All Tickets
└── Notifications

Adapt this to the existing IOMS architecture rather than blindly creating conflicting routes.

---

# 14. SECURITY

Preserve and verify:

- tenant isolation;
- Master Admin boundaries;
- authentication;
- RBAC;
- payment verification;
- webhook signature verification;
- webhook idempotency;
- subscription entitlement;
- existing audit trail.

Support data must not accidentally expose one tenant's private support information to another tenant.

Payment information must only be visible to authorized Master Admin/platform users.

Read-only subscription enforcement must be server-side.

Never allow client-side subscription state to grant access.

---

# 15. PAYMENT PROVIDER ARCHITECTURE

Keep the existing payment provider abstraction.

DO NOT REMOVE MIDTRANS.

The architecture should remain provider-agnostic:

IOMS Payment Service
→ Payment Provider
→ Midtrans / iPaymu / future provider

Do not couple Master Admin directly to one gateway.

Payment events should normalize into IOMS internal payment/subscription domain.

---

# 16. UX DIRECTION

Master Admin should feel like a premium SaaS operations console.

Use existing IOMS visual language:

- navy;
- IOMS blue;
- soft tinted surfaces;
- semantic accents;
- subtle depth;
- clear status hierarchy;
- information density;
- meaningful activity feeds;
- restrained card usage.

Avoid:

- generic CRUD admin;
- endless white cards;
- excessive rounded rectangles;
- unnecessary charts;
- decorative UI without operational value.

Support queue/inbox should be fast to scan.

---

# 17. IMPLEMENTATION APPROACH

First inspect:

- current Subscription model/service;
- billing/invoice architecture;
- payment provider abstraction;
- Midtrans webhook;
- current entitlement enforcement;
- Master Admin routes/controllers/pages;
- existing notification system;
- existing email architecture;
- tenant isolation;
- existing activity/audit records.

Do not blindly implement this prompt.

Reuse existing architecture where appropriate.

Only introduce new models/tables where the existing architecture genuinely cannot support the requirement.

Do not duplicate existing concepts.

---

# 18. VERIFICATION

## Subscription

Test:

- active subscription;
- H-7 reminder;
- expiry;
- 7-day grace;
- read-only after grace;
- renewal before expiry preserves original period end;
- renewal after expiry;
- renewal restores active access;
- duplicate webhook does not double-extend;
- data remains intact.

## Master Admin

Test:

- tenant status visibility;
- subscription/payment visibility;
- recent events;
- new subscription notification;
- renewal notification;
- payment event;
- expiry/grace/lapsed events.

## Support

Test:

- customer email enters Support;
- unidentified sender handling;
- ticket creation;
- Needs Reply queue;
- Master Admin reply;
- Waiting for Customer;
- customer reply returns to Needs Reply;
- priority;
- assignment;
- conversation history;
- tenant/customer context;
- support email sends with support@iomsuite.com;
- multiple tickets remain correctly separated.

## Security

Test:

- tenant support data cannot cross tenant boundaries;
- only authorized Master Admin can access platform support/payment data;
- read-only enforced server-side.

## Responsive

Verify approximately:

- 375px;
- 768px;
- 1280px.

Run:

- backend tests;
- lint;
- frontend build;
- relevant browser verification.

If external email/payment provider cannot be fully tested in the current environment, clearly document the exact unverified boundary instead of pretending it passed.

Update relevant Obsidian/KB/ADR documentation.

Commit and push completed implementation to GitHub main.

DO NOT TOUCH CPANEL.
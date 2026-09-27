# ADR 040 — Master Admin is an operations console, and it speaks Bahasa Indonesia

**Status:** Accepted; first slice implemented (v2.79.0).
**Date:** 2026-09-27
**Related:** ADR 033 (the subscription lifecycle these figures are derived from), ADR 008 (the
tenancy boundary Master Admin deliberately crosses), `docs/CONVENTIONS.md` § *The language
hierarchy*.
**Backlog:** [[Master Admin Operations Center]]

---

## Two products, one codebase

| Surface | Question it answers | Audience |
|---|---|---|
| Tenant application | *How is my company operating?* | the customer |
| Master Admin (`/platform`) | *How is the IOMS platform operating?* | the IOMS team |

Master Admin is not a bigger tenant dashboard, and it must not become one. It answers questions
about the **business of running IOMS**: who bought, who is about to lapse, whose payment failed,
what needs chasing today.

## The counters were reading the wrong thing

The Platform Dashboard counted `tenants` and `subscriptions` by their **stored** status. ADR 033 §1
established that where a subscription sits in *time* — active, grace, lapsed — is **derived on every
read**, precisely so it cannot drift. A subscription whose period ended six weeks ago still stores
`active`.

So the one screen meant to show operational trouble showed none of it: *expiring*, *grace* and
*lapsed* — the three states an operator can actually act on — did not appear anywhere.

`PlatformOperationsService` derives them through `Subscription::lifecycleState()`, the same call the
customer's Billing page and the write guard use. The console and the customer therefore cannot
disagree about whether an organization is in grace.

**It is read-only.** It counts and sums records that already exist; it writes nothing and sends
nothing.

**It is provider-agnostic.** Payment activity is read from `invoices` and `payment_transactions`,
where every provider's verified result is normalised. Adding iPaymu beside Midtrans changes nothing
here, and no gateway name is hardcoded — the provider breakdown comes from the data.

## Support and Notifications are different things

- **Notifications** — internal platform events: a subscription began, a plan changed, a period
  lapsed, a payment failed. Machine-generated, informational.
- **Support** — a human being asking for help.

They are kept apart deliberately: mixing a customer's question into a feed of automated events is
how the question gets missed. v2.79.0 implements the Notifications half. Support ticketing is a
separate, larger piece of work — see [[Support Inbox]].

Platform notifications reuse the existing `Notification` model rather than adding a table. The one
new thing needed was a way to reach the operators: `notifyRole()` cannot, because `User` carries a
tenant global scope, so inside a tenant request it returns nobody with `tenant_id IS NULL` and the
notification is silently dropped. `notifyPlatformAdmins()` lifts that scope, and only that scope,
only to find platform administrators by role. **A platform event is never delivered to a tenant
user**, which `PlatformOperationsTest` asserts.

Events are emitted only from paths that a verified payment or a real transition reached:

| Event | Emitted from |
|---|---|
| Langganan baru | `TenantProvisioningService::activate()` |
| Perpanjangan diterima / diaktifkan kembali | `applyPaidInvoice()` (verified webhook or audited manual settlement) |
| Paket diubah | `applyPaidInvoice()`, plan-change branch |
| Masa tenggang dimulai / Tenant menjadi read-only | the nightly lifecycle command, once per state per period |
| Pembayaran gagal | the payment webhook's non-settlement branch |

## The language decision

**Master Admin is written in Bahasa Indonesia.** The customer-facing product keeps the language
hierarchy in `docs/CONVENTIONS.md` unchanged: English for module names, page titles, labels and
actions; Indonesian for subtitles, help text and empty states.

This is an exception, and it is deliberate:

- Master Admin is an **internal console** operated by the IOMS team in Indonesia. It is not part of
  what a customer buys, and no customer sees it.
- The language hierarchy exists so that a **customer's** menu item and the page it opens are
  recognisably the same thing. That argument does not reach a surface customers never open.
- `LanguageHierarchyTest` already scopes itself to public routes and tenant department pages. The
  `platform` route name is explicitly noted there as "a different surface entirely", so this
  exception contradicts no test and no existing rule — it states one that was previously unwritten.

v2.79.0 writes the new operations section in Indonesian. The older Master Admin navigation and
tables are still English; converting them is tracked, not silently half-done — see
[[Master Admin Operations Center]].

## What would make this ADR wrong

If Master Admin were ever sold or delegated to customers — a reseller console, or a customer group
administering several of its own organizations — the language exception would have to go, and the
tenancy boundary would need a great deal more than a role check.

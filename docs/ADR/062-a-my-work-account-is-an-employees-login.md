# ADR 062 — A My Work account is an employee's login, not a second record of them

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.123.0
- **Relates to:** ADR 061 (an offer is bound to what the server allows), ADR 055 (deleting an employee is permanent), ADR 038 (account / organization / subscription)
- **Supersedes:** the v2.89.0 decision not to add a second login page — see *Why a second page now* below

## Context

There was one form for creating an account. It asked for a name, an email address, a password, a
role and a department, and an Account Class picked whether the result was billed as a Full User or a
My Work User.

For a Platform User that is right: the form is where that identity comes into existence.

For a worker it is wrong in three ways, and each one was reported as a different problem:

1. **The identity already exists.** The Employee Register holds the person's number, full name,
   position, department and operating unit. Retyping them produces a second, diverging copy of
   somebody IOMS already knows, with nothing linking the two — so nothing could answer "does this
   worker have a login?", and a renamed employee and their account drifted apart.

2. **Role and Department are meaningless for the class.** `RestrictMyWorkUser` already bounds a My
   Work account to My Work, its permits and its tasks (ADR 061). Asking an administrator to choose
   a role and a department for it invites the conclusion that those choices grant something, when
   the allow-list is the only thing that decides.

3. **An email address is not something a yard worker necessarily has.** The form required one, so
   administrators invented them. An invented address is a fiction in the column every notification
   reads.

And the credential was wrong for the setting. A worker signing in on a phone, in a yard, with gloves
on, was being asked for an email address and a password nobody had told them.

## Decision

**A My Work account is an employee's login.** It is created by PICKING a person from the register,
never by describing one, and it is identified by what that person already carries.

### One form each, and what stays shared

| | Add Platform User | Add My Work User |
|---|---|---|
| Identity | invented on the form | picked from the Employee Register |
| Role / Department | chosen | not asked — the allow-list decides |
| Email | required | optional |
| Credential | password | access code |
| Signs in at | `/login` | `/my-work/login` |

What is NOT separated, because separating the forms must not become a way around any of it: the seat
quota (both go through `EntitlementService::canCreateUserOfType()`, re-counted under a row lock
inside the transaction that writes the row), the tenant boundary, `user_type` as the billable class,
and `RestrictMyWorkUser`.

### The access code is the password

It is stored in `users.password`, hashed by the model's existing `hashed` cast, and verified by the
existing guard. There is no second credential column and no second hashing path: a parallel secret
store would be two things to rotate, two things to hash correctly, and two places a plain-text leak
could come from.

A code is 8 characters over a 32-symbol alphabet with no I, O, 0 or 1 — unambiguous in every font a
yard prints with, 2^40 of space, short enough to say out loud. It is shown **once**, normalised on
both set and check (case-insensitive, hyphens ignored), and reset rather than recovered: nothing
stores it, so there is nothing to look up.

### A name finds an account; the code proves it

Yards have two Budi Santosos. An identifier resolves to the employees whose **badge number or full
name is exactly** that string (never a prefix — a LIKE over a register is a way to enumerate it),
badge matches first, capped at five candidates. The access code is then checked against each, and
the first that matches IS the account. So:

- knowing a colleague's name or badge number gets you nowhere without their code;
- two workers with one name each reach their own account, with their own code;
- two tenants may both number a worker EMP-0001 — browser-verified — and each code reaches its own
  tenant's account. The resolution reads without the tenant scope **because nobody is signed in
  yet**; the account carries its own `tenant_id` and the scope applies from the next request, as it
  does for every sign-in.

Failures are one sentence ("identifier or code does not match") whether the identity is unknown or
the code is wrong, so the form cannot be used to discover who holds an account. "Your access is
deactivated" is said only **after** a correct code, so it tells the holder something true and tells
everybody else nothing. Attempts are limited per identifier and per address, on top of the route's
throttle.

### A link is not access

After creation the administrator is shown the worker's name, badge number, status, the code (once),
and a **Share Access Link**: the My Work sign-in page with the badge number prefilled. The badge
number is an identifier, not a secret. **No permanent secret ever goes in a URL** — a code in a link
is a code in every chat history, proxy log and screenshot it passes through. Sharing uses the Web
Share API where the browser has one, and falls back to copying the link or the full instructions.

### Why a second page now

v2.89.0 explicitly refused a second login page: *"a second login page would duplicate authentication
to solve a wording problem"*. That was correct then — both classes signed in with an email and a
password, so the only difference was the sentence on the page. It is not a wording problem any
more: the two classes present **different credentials**, and a form that asks for an email cannot
accept a badge number. What v2.89.0 was protecting is still protected — one guard, one session, one
user provider, one hashing path, one `is_active` rule. `MyWorkSessionController` resolves an
identity and hands over to `Auth`; it authenticates nothing itself.

## Consequences

- **`users.email` is nullable.** The unique index stays (two accounts still cannot share an
  address; NULL is not an address). Anything that formats an email must tolerate null — one place
  did not, and crashed the Users tab search for an account without one.
- **`users.employee_id` is unique.** "One login per worker" is enforced by the database rather than
  by remembering to check; the duplicate is also caught under the quota lock so the administrator
  gets a sentence instead of an integrity error, and the dialog says so before the button is
  pressed.
- **It is deliberately NOT mass-assignable**, for the same reason `google_id` is not: linking an
  account to a person is an identity decision, and no future controller passing request data to
  `update()` should be able to repoint somebody's login at another worker.
- **Erasing an employee deactivates the login and keeps the record** (`EmployeeEraser`, ADR 055).
  `users` is a record of its own — activity, approvals and everything the person did point at it —
  so it is a REFERENCE, not a dependent. Sign-in resolves through the register, so once the
  employee is gone no identifier reaches the account anyway; deactivating it says so explicitly.
- **An account created through the Platform form with class My Work still exists and still works.**
  It has no employee link, so it signs in with its email and password as before and appears in the
  My Work card with its missing link stated. Nothing was migrated.
- **Reactivating consumes a seat**, so it asks the quota the same question creating one does.
  Without that, deactivating at a full quota and reactivating later would walk a tenant past its
  allowance one account at a time.

## What this does NOT claim

It does not claim production is fixed. Per ADR 057 that question is answered by comparing the
signature `app:deploy` prints with the one Master Admin → Running Release prints, and by nothing
else.

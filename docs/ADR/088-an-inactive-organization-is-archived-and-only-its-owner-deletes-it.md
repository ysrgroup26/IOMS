# ADR 088 — An inactive organization is archived, never deleted for inactivity; only its owner deletes it

**Status:** Accepted (2026-11-17, the owner). Branch `ticorp/tenant-retention`. Refines ADR 087.
**Related:** ADR 033 (lifecycle), `App\Services\TenantArchiveService`, `tenants:archive-dormant`,
`OrganizationDeletionController`, `TenantDeletionService::requestByCustomer()`, migration
`2027_01_20_100100_a_dormant_tenant_is_archived_not_deleted` (additive).

## Context

The owner's question: should an organization that stops paying be deleted to keep the database small,
or kept? Deleting a company's records because it paused would be heavy-handed, and the owner wants a
returning customer to simply sign in, pay and continue. Separately, a customer must be able to delete
their own organization from the dashboard, and that deletion must happen by itself.

What already existed (ADR 033): renewal invoice e-mailed H-7, a 7-day grace window, then read-only
**indefinitely** with every record intact; a payment restores writes. Nothing is ever withdrawn by time.

## Decision

1. **No deletion for inactivity.** Lapsed stays read-only and intact, as today.
2. **Archive after a year of dormancy** (`tenants:archive-dormant`, daily 03:30): not the Sandbox, no
   deletion scheduled, subscription lapsed with its period ended more than 365 days ago, and no member
   signed in for 365 days. Archiving sets `tenants.archived_at` and nothing else: no row removed, no
   access changed. **Any member's next sign-in un-archives it**, through every sign-in path (a listener
   on Laravel's `Login` event). Rows are small; uploaded files are what grows, so moving archived
   uploads to cold object storage is the next step (it needs object storage on the production host).
3. **Master Admin's tenant list defaults to organizations in use**: tabs Aktif / Tidak aktif (lapsed) /
   Arsip / Semua, with counts. Nothing is hidden for good.
4. **The organization's own administrator can delete it** (Billing page): types the organization's
   exact name and their password; the deletion is scheduled `CUSTOMER_HOLD_DAYS` (7) ahead, every
   administrator is e-mailed at once and reminded, it can be cancelled until the date, and
   `tenants:process-deletions` then removes it permanently and automatically (ADR 087's purge). The
   routes are named under `subscription.` so a read-only organization can still leave: leaving never
   requires paying. Only `super_admin` of that organization may do it.
   The short hold protects against an accidental click and a hijacked administrator account; the owner's
   intent (the customer's decision is carried out without anybody's approval) is unchanged.
5. Operator-initiated deletion of a customer stays the 90-day hold of ADR 087; immediate purge stays
   limited to test organizations that never paid.

## Tests

`tests/Feature/TenantRetentionTest.php` (4): the administrator deletes a read-only organization after
name and password are confirmed (wrong name or password refused), is reminded, and it is purged after the
hold; a non-administrator gets 403 and a cancelled deletion purges nothing; only an organization lapsed
over a year with no sign-in is archived, its data intact, and the next sign-in restores it; Master Admin
shows in-use organizations by default with counts for the other tabs.

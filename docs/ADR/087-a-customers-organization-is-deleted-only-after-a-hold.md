# ADR 087 — A customer's organization is deleted only after a hold; a test one exactly and at once

**Status:** Accepted (2026-11-17, the owner). Branch `ticorp/tenant-deletion`.
**Related:** `App\Services\TenantPurgeService`, `App\Services\TenantDeletionService`,
`PlatformTenantDeletionController`, `tenants:process-deletions`, `App\Mail\TenantDeletionNotice`,
migration `2027_01_20_100000_a_tenant_deletion_is_held_and_announced` (additive).

## Context

Master Admin had no way to remove an organization. The owner wanted his test organizations and test
registrations gone so the console shows only real customers, with two conditions:

1. an address whose organization or registration is removed can **register again**;
2. a **customer's** data is never deleted at will: hold it at least three months and remind the
   customer by e-mail first.

IOMS protects company data with RESTRICT foreign keys (41 on `company_id`), so deleting a tenant row
cannot work, and a hand-written list of tables would rot the first time a module is added.

## Decision

1. **`TenantPurgeService` removes exactly an organization's rows, or nothing.** It reads the running
   database's foreign keys (`Schema::getForeignKeys`), seeds from the tenant row, every row carrying
   its `tenant_id`, every row of its companies and its users, follows every RESTRICT / NO ACTION /
   CASCADE reference downwards, and leaves SET NULL references to be nulled. Before deleting it
   refuses the whole purge if any collected row carries another organization's `tenant_id` or
   `company_id`, or would remove a platform administrator. Deletion runs in one transaction,
   children before parents (topological order of the collected tables, so RESTRICT holds on every
   engine), and removes the users' Sanctum tokens. Users are deleted, not deactivated, so their
   addresses are free to register again. Uploaded files are left on disk.
2. **`TenantDeletionService` holds the rule.**
   - A **test organization**, one that never paid (no paid invoice, no settled payment, ever) and is
     not the Sandbox, may be purged at once by a platform admin who types its slug.
   - **Anybody else is scheduled**: `deletion_scheduled_for` = now + 90 days; the organization's
     active administrators are e-mailed at once and reminded 30, 7 and 1 days before (one e-mail per
     run even after a missed run); the schedule can be cancelled until the date (they are told);
     `tenants:process-deletions` (daily 03:00) purges it after the date. Nothing changes for the
     customer during the hold.
   - A **registration** that never paid and never became an organization is purged with its
     onboarding invoice(s), their payment attempts, and the signing-up account when that account
     belongs to no organization and is not staff.
   - Every step is written to the Activity Log with per-table row counts.
3. Master Admin: a **Hapus organisasi** card on the tenant page (schedule/cancel; purge now only where
   allowed) and a delete button on registrations the server says may be deleted.

## Tests

`tests/Feature/TenantDeletionTest.php` (6): a test organization is purged completely (company,
RESTRICT-protected project, users, invoices) while a paying organization and the operator are
untouched, and its address can register again; the wrong slug and an organization that ever paid
are refused; a customer is held 90 days, reminded at 30 and once for 7/1 after a late run, then purged;
a cancelled schedule purges nothing and tells the customer; an unpaid registration is purged with its
invoice and account while a paid one is refused; a tenant administrator gets 403.

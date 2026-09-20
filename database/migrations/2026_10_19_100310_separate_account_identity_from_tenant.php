<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.74.0 -- AN ACCOUNT CAN NOW EXIST WITHOUT AN ORGANIZATION.
     *
     * Until this release there was no such thing as an IOMS account on its
     * own. `/get-started` collected identity, company details, plan and
     * password in one form, stored them in a `tenant_registrations` row,
     * and the `users` record was not created until
     * TenantProvisioningService ran -- which only runs after a verified
     * payment. You could not sign in before you had paid, because there
     * was nothing to sign in to.
     *
     * The new model separates three things that were collapsed into one:
     *
     *   Account       a person's login identity          `users`
     *   Organization  the customer's business context    `tenants` + `companies`
     *   Subscription  the commercial entitlement         `subscriptions`
     *
     * See `docs/ADR/038-account-organization-subscription.md`.
     *
     * -------------------------------------------------------------------
     * THE KEYSTONE PROBLEM THIS SCHEMA HAS TO SOLVE
     * -------------------------------------------------------------------
     *
     * `User::isPlatformAdmin()` was `is_null($this->tenant_id)`. A
     * registered account that has not subscribed yet ALSO has no tenant --
     * so under the old definition, every new signup would have been a
     * Platform Super Admin with cross-tenant reach. That is the single
     * most dangerous thing this change could have done.
     *
     * The fix is in the model, not here: `isPlatformAdmin()` now reads the
     * ROLE. This migration's job is to guarantee the data supports that,
     * which was verified before writing it -- every null-tenant user in
     * the database already carries `role = 'platform_admin'`, and no
     * tenant user carries it, so the two definitions agreed exactly and
     * the switch is behaviour-preserving. The backfill below makes that a
     * guarantee rather than an observation.
     */
    public function up(): void
    {
        /*
         * BELT AND BRACES for the keystone. If any environment has a
         * null-tenant user that is NOT already marked platform_admin, the
         * role switch would silently DEMOTE a real operator. Promote them
         * here, before the code change lands, so the two definitions are
         * identical on every deployment and not just on the one that was
         * inspected.
         *
         * This runs first, while `is_null(tenant_id)` is still the
         * authoritative definition.
         */
        DB::table('users')
            ->whereNull('tenant_id')
            ->where('role', '!=', 'platform_admin')
            ->update(['role' => 'platform_admin']);

        Schema::table('users', function (Blueprint $table) {
            /*
             * A Google-authenticated account has no IOMS password, and
             * storing a random hash to satisfy NOT NULL would be a lie:
             * it would look like a credential, count as one in any audit,
             * and silently make "does this account have a password?"
             * unanswerable. Null means exactly what it says.
             *
             * Laravel's own Hash::check() against a null hash fails
             * closed, and the login path guards it explicitly -- see
             * AuthenticatedSessionController.
             */
            $table->string('password')->nullable()->change();

            /*
             * The Google subject identifier (`sub`), not the email.
             *
             * Google's `sub` is stable and unique forever; an email
             * address is neither -- Workspace accounts get renamed, and a
             * deleted consumer address can in principle be re-registered.
             * Matching on `sub` is what makes "this is the same person"
             * true across those changes.
             *
             * Nullable because most accounts never link Google; unique
             * because one Google identity must map to at most one IOMS
             * account.
             */
            $table->string('google_id')->nullable()->unique()->after('email_verified_at');

            // When the link was made, for the account security panel. An
            // account owner should be able to see that Google sign-in is
            // attached, and since when.
            $table->timestamp('google_linked_at')->nullable()->after('google_id');
        });

        Schema::table('tenant_registrations', function (Blueprint $table) {
            /*
             * `password` becomes nullable because an order raised by an
             * ALREADY AUTHENTICATED account has no password to carry --
             * the credential lives on the `users` row that already exists.
             *
             * The legacy public flow (no account yet) still sets it, and
             * TenantProvisioningService still reads it for that path. The
             * two paths are distinguished by `user_id`, not by this
             * column.
             */
            $table->string('password')->nullable()->change();

            /*
             * WHICH ACCOUNT THIS ORDER BELONGS TO, set at creation.
             *
             * The column already existed but was written only AFTER
             * provisioning, as a pointer to the user that provisioning had
             * just created. It now means something stronger and earlier:
             * when it is set at creation, this order was raised by an
             * existing account, and provisioning must ATTACH that account
             * to the new tenant rather than create a second one.
             *
             * That distinction is the whole of the new flow on the
             * backend, which is why it is one column rather than a second
             * order table.
             *
             * NO INDEX IS ADDED FOR IT. The column already carries a
             * foreign key, which InnoDB backs with an index of its own --
             * a second one is redundant, and MySQL then adopts whichever
             * it likes to satisfy the constraint and refuses to let that
             * one be dropped on rollback. Found by rolling this migration
             * back, which is the only thing that shows it. Same shape as
             * the v2.73.0 pitfall already in CONVENTIONS.md.
             */
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'google_linked_at']);
        });

        /*
         * `password` is deliberately NOT restored to NOT NULL, and
         * `tenant_registrations.password` likewise.
         *
         * Rolling back the schema must not destroy data, and by the time
         * anyone rolls this back there may be Google-only accounts whose
         * password genuinely is null. Making the column NOT NULL again
         * would fail outright on those rows, or -- worse, if forced --
         * require inventing a credential for them. Leaving the column
         * nullable is harmless: the old code never wrote null to it.
         */
    }
};

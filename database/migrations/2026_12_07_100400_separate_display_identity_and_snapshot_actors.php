<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.100.0 -- WHO SOMEBODY IS CALLED, AND WHO DID THE THING.
 *
 * Two different identity questions were answered by one column, and both
 * answers were wrong in a way nobody could see until a name changed.
 *
 * A PERSON HAS A NAME ON THE ACCOUNT AND A NAME ON THE SCREEN.
 * `users.name` is what the account was registered as: it comes from the
 * registration's `contact_name`, it appears beside the email on invoices
 * and in support, and it is the one an administrator should be slow to
 * rewrite. What a colleague should see in a sidebar, an approval queue or a
 * timeline is a different question -- "Budi S." or "Budi (Produksi)" --
 * and changing it should not quietly rewrite the registered name.
 *
 * With one column there was no way to offer the second without destroying
 * the first, so administrators either renamed the account or left a name
 * nobody recognises. `display_name` is the second answer. It is nullable,
 * and null means "use the registered name", so no existing account changes
 * in any way until somebody deliberately sets one.
 *
 * AN ACTOR IS A FACT ABOUT THE PAST, NOT A LOOKUP.
 * `activity_logs` stored only `user_id`, and `approvals` only
 * `approved_by`/`requested_by`. Every actor shown anywhere -- a timeline
 * entry, an audit row, an approval stamp, a signature block on a printed
 * permit -- was resolved by following that key to the user's CURRENT name.
 *
 * So renaming one person silently rewrote history: every approval they had
 * ever granted, on every permit already printed and filed, retroactively
 * carried the new name. Deactivating or removing them emptied the stamp
 * altogether. For a product whose permits and approvals are the compliance
 * record, an audit trail that changes when somebody marries is not an
 * audit trail.
 *
 * These columns record the name AS IT WAS when the action happened. The
 * foreign key stays, and stays authoritative for "which account" -- the
 * snapshot answers "what did this say at the time", which is a different
 * question and the only one a historical document can honestly answer.
 *
 * NOTHING IS BACKFILLED, DELIBERATELY. A historical row's null means "this
 * was not captured when it happened", which is the truth. The read paths
 * fall back to the live relation exactly as they do today, so every
 * existing row renders identically -- while writing a name in from today's
 * users table would manufacture a historical fact out of a value that may
 * already have changed. Same reasoning as the payment reconciliation
 * migration before it.
 *
 * Portable by design: nullable string adds only, no MODIFY and nothing read
 * out of information_schema, so this applies identically on MySQL and on
 * the SQLite the suite runs against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What colleagues see. Null means "use `name`", which is why
            // this is additive rather than a rename: every account keeps
            // its current appearance until somebody chooses otherwise.
            $table->string('display_name')->nullable()->after('name');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            // The actor's display identity at the moment of the action.
            // `user_id` still says which account; this says what it was
            // called, which is what the row is read for.
            $table->string('actor_name')->nullable()->after('user_id');
        });

        Schema::table('approvals', function (Blueprint $table) {
            // The two signatures on the document. Captured when the
            // decision is made and when the request is raised, never
            // recomputed -- a signature that re-renders is not one.
            $table->string('requester_name')->nullable()->after('requested_by');
            $table->string('approver_name')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('actor_name');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['requester_name', 'approver_name']);
        });
    }
};

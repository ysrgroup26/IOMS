<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.106.0 -- A CUSTOMER'S PPE CATALOGUE IS THEIR OWN BUSINESS.
 *
 * `ppe_types` is installation-wide shared reference data, and v2.63.0
 * recorded that as a deliberate decision: six generic rows ("Safety
 * Helmet", "Coverall") with a replacement interval, holding nothing that
 * identifies a customer. Sharing those defaults is fine.
 *
 * WHAT WAS NOT CONSIDERED IS WHO MAY CHANGE THEM. The create, update and
 * delete routes are gated by `role:super_admin`, which is the TENANT
 * administrator, and `PpeTypePolicy` only ever asks about a role
 * capability -- because on a shared row there is no ownership to ask
 * about. So any customer's administrator could rename, re-interval or
 * delete a PPE type that every other customer was using. `destroy()` had
 * already noticed half of this and refuses to delete a type somebody is
 * issuing; `update()` had no guard at all, and renaming is just as
 * destructive to the customer reading the other end of it.
 *
 * It is also the wrong shape for the product: a customer adding
 * "Sarung Tangan Las" made it appear in every other customer's dropdown.
 *
 * THE PATTERN ALREADY EXISTS IN THIS CODEBASE. `KpiCategory`,
 * `NumberingFormat` and `NumberingSequence` all use a nullable
 * `company_id` where null means "the built-in default every customer
 * starts from" and a value means "this customer's own". `PpeType` now
 * joins them, which keeps the shared defaults shared, gives each customer
 * its own additions, and makes the authorization question answerable:
 * a tenant may change what it owns, and nothing else.
 *
 * NOTHING IS BACKFILLED. Every existing row stays null, which is exactly
 * what it is: a shared built-in. No customer loses a type, no assignment
 * is repointed, and `employee_ppe` is untouched.
 *
 * Portable: one nullable column and an index, no data movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppe_types', function (Blueprint $table) {
            // Null = the shared built-in catalogue. A value = this
            // customer's own addition. See the model's
            // companyScopeAllowsGlobalRows().
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ppe_types', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};

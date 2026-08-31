<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access).
 *
 * "PTW Access" is a per-USER grant, deliberately separate from `role`
 * and from `Employee` entirely -- see this phase's own doc comment on
 * `User::$fillable` for the full reasoning:
 *   Employee (HR/HSE personnel record, no login) --NOT the same row as--
 *   User (one login = one person) --grants--> PTW Access (this column)
 *   --allows--> creating a PTW, where Requester = that same User.
 *
 * Follows the exact existing `is_active` shape on this same table
 * (plain boolean, default false, toggled the same way) rather than
 * inventing a new pattern -- confirmed by audit this is the established
 * per-user boolean-flag convention already in `SettingsController::
 * updateUser()`/`Settings/Index.jsx`'s `EditUserDialog`.
 *
 * Default `false`: enabling PTW Access for an EXISTING user is always an
 * explicit, counted, quota-checked admin action (see
 * `EntitlementService::canEnablePtwAccess()`) -- it must never be silently
 * true for anyone as a side effect of this migration running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ptw_access')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ptw_access');
        });
    }
};

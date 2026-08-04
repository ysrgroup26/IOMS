<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department User mechanism (v1.10.2). Nullable, opt-in, no backfill
     * -- every existing user keeps `department_key = null`, which means
     * "Administrator" for navigation purposes (full Department Selector,
     * can switch freely), exactly their current behavior, unchanged.
     * Setting this to a real `resources/js/lib/workspaces.js` department
     * key (e.g. 'hse', 'logistics') restricts that one user to that one
     * department's sidebar, with no selector at all -- see
     * `app/Models/User.php`'s own note on why this is a plain string
     * column, not a foreign key, and why no existing account was assigned
     * one as part of this change.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department_key')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('department_key');
        });
    }
};

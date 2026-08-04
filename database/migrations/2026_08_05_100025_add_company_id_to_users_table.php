<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Tenant Foundation (Epic 3). Found while building TenantContext:
     * `users` had no `company_id` at all -- every current user (Super
     * Admin, HSE, HRD, Manager) is internal staff who can view multiple
     * companies' data through UI-level filters, not someone inherently
     * tied to exactly one company. That's genuinely different from a SaaS
     * tenant model, where a user belongs to one customer organization.
     *
     * Nullable, not required: existing users legitimately have no single
     * company (that's the correct, current reality, not a data gap to
     * paper over), and a Super Admin managing multiple companies should
     * stay that way. This column exists so a FUTURE "Company
     * Registration" flow (Epic 3 task 2) can create a Company Admin that
     * genuinely IS scoped to one company, which today's schema had no way
     * to express at all.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};

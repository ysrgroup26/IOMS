<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `users.tenant_id` is DELIBERATELY nullable forever, not backfilled
     * to NOT NULL the way `companies.tenant_id` was -- null is a real,
     * permanent, intentional state here: it means "Platform Super Admin",
     * a person who works FOR the platform operator, not for any customer
     * tenant. Every EXISTING user account (all of whom were operating
     * within the single pre-Milestone-2 company/tenant) is backfilled to
     * the default tenant instead -- they become that tenant's own admins/
     * staff, exactly the access they already had, nothing removed. A
     * genuinely new Platform Super Admin account is seeded separately
     * (see PlatformAdminSeeder) rather than repurposing an existing
     * tenant account, so no current login's behavior changes.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $defaultTenantId = DB::table('tenants')->where('slug', 'default')->value('id');

        if ($defaultTenantId) {
            DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

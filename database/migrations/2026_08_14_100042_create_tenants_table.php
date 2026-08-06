<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenancy Foundation (Milestone 2, v2.0.0 -- see docs/ADR/008 and the
     * approved SaaS Blueprint). `Tenant` is the new top-level owner above
     * the existing `Company` model: one Tenant (a paying customer
     * organization) hasMany Company (internal business units -- GAJ,
     * Maintenance, exactly as before, unchanged in meaning).
     *
     * Deliberately NOT adding tenant_id to every downstream table
     * (departments, positions, employees, tasks, incidents, ...). Every
     * one of those already scopes through company_id, directly or
     * transitively (Milestone belongsTo Project which has company_id,
     * GoodsReceipt belongsTo MaterialRequest which has company_id, etc.)
     * -- adding a parallel tenant_id to all of them would be redundant
     * data that could drift out of sync with its own company_id, not a
     * safety improvement. `companies.tenant_id` is the single anchor;
     * `Company`'s own global scope (see App\Models\Company) is what
     * actually enforces isolation for everything beneath it.
     *
     * `users.tenant_id` is separately nullable and stays nullable
     * permanently -- see the users migration below and
     * App\Models\User::isPlatformAdmin() for why null is a real,
     * intentional state (Platform Super Admin), not a data gap.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active'); // trial, active, suspended, expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        // Backfill: every Company that exists today belongs to ONE
        // existing production tenant -- create it and point every current
        // Company row at it, matching the same "preserve existing data,
        // never invent a fresh-install-only path" convention already used
        // by the company_id-on-departments/positions migrations.
        $defaultTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Default Tenant',
            'slug' => 'default',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('companies')->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::dropIfExists('tenants');
    }
};

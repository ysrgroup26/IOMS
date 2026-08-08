<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Company Settings completion, Task #62 -- found while
     * building the Approval Flow config UI, same class of bug as
     * `numbering_formats` in the previous migration). `approval_flows`
     * had a nullable `company_id` but no `tenant_id` at all --
     * `ApprovalFlowResolver::resolve()` matches a catch-all flow
     * (`company_id` null) purely by `module_key`, with NO tenant
     * filtering whatsoever. Once any tenant configured a tenant-wide
     * flow for a module (e.g. "material_request"), it would silently
     * apply to EVERY OTHER TENANT's records of that module too -- a real
     * cross-tenant approval-routing leak, not just a config nuisance
     * (the wrong people could end up approving another company's
     * requests). `tenant_id` null stays a genuine platform-wide
     * fallback (unused today, mirroring `numbering_formats`); a
     * tenant's own flow always gets `tenant_id` set explicitly.
     */
    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

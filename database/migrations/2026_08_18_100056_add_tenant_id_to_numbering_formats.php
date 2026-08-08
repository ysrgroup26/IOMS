<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Company Settings completion, Task #62 -- found while
     * building the Numbering config UI). `numbering_formats` had a
     * `company_id` but no `tenant_id` at all -- `NumberGeneratorService::resolveFormat()`'s
     * `firstOrCreate(['company_id' => null, 'module_key' => $moduleKey], ...)`
     * meant the first tenant to trigger a module's default format
     * creation "won," and every OTHER tenant on the platform would then
     * silently share that same row -- one tenant customizing their
     * prefix/pattern would leak into every other tenant's documents.
     * `numbering_sequences` (the actual counters) deliberately stays
     * global-scope by design (ADR-009) -- that decision is unaffected;
     * this migration only fixes the FORMAT config, which was never meant
     * to be shared cross-tenant, just under-specified when first built.
     *
     * `tenant_id` null = a genuine platform-wide fallback (used only if
     * a tenant hasn't customized a module's format at all), not "any
     * tenant's row" -- `resolveFormat()` now looks up the CURRENT
     * tenant's own row first, then this platform default.
     */
    public function up(): void
    {
        Schema::table('numbering_formats', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('numbering_formats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

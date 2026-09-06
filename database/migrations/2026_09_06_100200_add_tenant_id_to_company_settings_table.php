<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.40.0 (P0 tenant isolation) -- gives `company_settings` a tenant owner.
 *
 * THE DEFECT THIS FIXES (proven empirically, not inferred -- see
 * tests/Feature/CompanySettingTenantScopeTest): `key` carried a
 * PLATFORM-WIDE unique() constraint, so exactly one row could exist per
 * key for every tenant combined. Consequences:
 *
 *   - Tenant B READ Tenant A's company name, logo, address, phone,
 *     email and brand colour.
 *   - Tenant B SAVING its branding DESTROYED Tenant A's, because
 *     updateOrCreate(['key' => ...]) matched the single shared row.
 *   - It reached generated artifacts, not just the shell: DocumentEngine
 *     (every PDF), EmployeeExport / KpiReportExport, ReportController,
 *     ReportCenterController, AnalyticsController and NotificationService
 *     all read these keys.
 *
 * THE MODEL: two tiers, distinguished by `tenant_id`.
 *
 *   tenant_id IS NULL -> platform default. What a guest (login/landing),
 *                        a Platform Super Admin, and any tenant that has
 *                        not overridden the key all resolve to.
 *   tenant_id = X     -> that tenant's own override.
 *
 * CompanySetting::get() resolves current tenant -> platform default ->
 * caller default. Making the pre-existing rows the PLATFORM tier is what
 * lets this deploy with ZERO behavioural change: every tenant keeps
 * seeing exactly what it saw before, and only future writes become
 * correctly isolated.
 *
 * BACKFILL -- deterministic, never invented. Ownership of the existing
 * rows is only recoverable in one situation, so that is the only
 * situation in which it is claimed:
 *
 *   exactly 1 tenant -> ownership IS deterministic. The rows are that
 *                       tenant's real customised data; assign them. The
 *                       platform tier is then left clean, so a SECOND
 *                       tenant onboarding later starts from IOMS
 *                       defaults rather than inheriting tenant 1's
 *                       identity. This fully closes the defect.
 *   0 tenants        -> fresh/unseeded install; the rows are seeded
 *                       defaults and correctly belong to the platform.
 *   2+ tenants       -> ownership is genuinely AMBIGUOUS and this
 *                       migration will NOT guess. The rows stay as
 *                       platform defaults, which preserves today's exact
 *                       behaviour (no data destroyed, no new leak
 *                       introduced) and stops all FUTURE writes from
 *                       cross-contaminating. Each tenant's first save
 *                       creates its own row. A warning is emitted so the
 *                       operator knows to re-enter per-tenant branding.
 *
 * Nothing is deleted and no value is overwritten in any branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        // The old platform-wide unique(key) is the defect itself: it is
        // what made a second tenant's save overwrite the first's row.
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropUnique('company_settings_key_unique');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->unique(['tenant_id', 'key'], 'company_settings_tenant_key_unique');
        });

        $tenantIds = DB::table('tenants')->pluck('id');

        if ($tenantIds->count() === 1) {
            // Ownership is deterministic: there is exactly one tenant, so
            // the existing customised values are unambiguously theirs.
            DB::table('company_settings')->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantIds->first()]);
        } elseif ($tenantIds->count() > 1) {
            // Deliberately NOT guessed. Left on the platform tier so every
            // tenant keeps resolving to today's values until it saves its
            // own -- behaviour-preserving, non-destructive, and future
            // writes are isolated from this point on.
            $rows = DB::table('company_settings')->whereNull('tenant_id')->count();

            if ($rows > 0) {
                $warning = "[IOMS] company_settings: {$rows} pre-existing row(s) kept as PLATFORM defaults because "
                    .$tenantIds->count().' tenants exist and ownership is not recoverable. '
                    .'Each tenant should re-save Settings > Company to claim its own branding.';

                if (app()->runningInConsole()) {
                    fwrite(STDERR, $warning.PHP_EOL);
                }

                logger()->warning($warning);
            }
        }
    }

    public function down(): void
    {
        // Collapsing many tenants' rows back into one unique(key) space
        // cannot be done without choosing whose data survives, so the
        // tenant rows are dropped and only the platform tier is kept.
        // Destructive by nature -- which is exactly why it is explicit.
        DB::table('company_settings')->whereNotNull('tenant_id')->delete();

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropUnique('company_settings_tenant_key_unique');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->unique('key', 'company_settings_key_unique');
        });
    }
};

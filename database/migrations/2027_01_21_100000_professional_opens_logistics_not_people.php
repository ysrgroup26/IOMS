<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRO_NEW = 'Health, Safety & Environment plus Warehouse Logistics: items, stock, goods receipt, stock movement and Material Requests, for an organization running up to two operating units.';

    private const BUS_NEW = 'Health, Safety & Environment and Warehouse Logistics, plus People / HRD and Management reporting, with the company-wide Dashboard across up to four operating units.';

    private const PRO_OLD = 'Health, Safety & Environment plus People and Workforce: employees, competency and certificate expiry, shifts, rosters and leave, for an organization running up to two operating units.';

    private const BUS_OLD = 'Health, Safety & Environment and People, plus Warehouse Logistics and Management reporting, with the company-wide Dashboard across up to four operating units.';

    /**
     * v2.143.0 (ADR 092) -- PROFESSIONAL OPENS WAREHOUSE LOGISTICS, NOT PEOPLE / HRD.
     *
     * The owner's ladder:
     *
     *   Starter       HSE
     *   Professional  HSE + Warehouse Logistics
     *   Business      HSE + Warehouse Logistics + People / HRD + Management
     *
     * Starter and Business already grant exactly that. Only Professional
     * changes, and `config/plans.php` is the place that says so for every
     * NEW grant (self-service activation, operator-created tenant, plan
     * change). This migration brings the tenants who ALREADY subscribe to
     * Professional onto the same scope, because a config change alone never
     * touches existing `tenant_workspaces` / `tenant_modules` rows -- and
     * the owner's instruction is that a customer must not reach a module
     * outside their plan.
     *
     * SURGICAL, NOT A FULL SYNC. For each tenant whose subscription is on
     * the Professional package it removes exactly one grant (the `hr`
     * workspace) and adds exactly what Professional now includes
     * (`logistics`, `material_requests`). Every OTHER grant row is left as
     * it is, so a hand-adjustment a Platform Admin made on the Tenant Grants
     * page for any other workspace survives. A full `sync()` would have
     * silently erased those.
     *
     * The `hr` grant itself is removed even where a Platform Admin added it
     * by hand: the owner decided (2026-11-20) that no Professional customer
     * keeps People / HRD outside the Business plan. A genuine exception is
     * re-granted on the Tenant Grants page after this runs.
     *
     * NO DATA IS TOUCHED. Employees, leave, rosters, certificates and every
     * other People / HRD record stay in their tables. Closing the workspace
     * removes the ROUTE to them, not the rows; a tenant that upgrades to
     * Business sees them again exactly as they were. Subscriptions,
     * agreed prices, invoices and payments are not read or written.
     *
     * Enterprise (retired from sale) and every other package are untouched.
     */
    public function up(): void
    {
        if (! $this->ready()) {
            return;
        }

        $ids = $this->ids();
        if (! $ids) {
            return;
        }

        // The catalogue copy says what the plan now opens (prices untouched).
        DB::table('packages')->where('slug', 'professional')->update(['description' => self::PRO_NEW]);
        DB::table('packages')->where('slug', 'business')->update(['description' => self::BUS_NEW]);

        foreach ($this->professionalTenantIds($ids['package']) as $tenantId) {
            DB::table('tenant_workspaces')
                ->where('tenant_id', $tenantId)
                ->where('workspace_id', $ids['hr'])
                ->delete();

            DB::table('tenant_workspaces')->insertOrIgnore([
                'tenant_id' => $tenantId, 'workspace_id' => $ids['logistics'], 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($ids['material_requests']) {
                DB::table('tenant_modules')->insertOrIgnore([
                    'tenant_id' => $tenantId, 'module_id' => $ids['material_requests'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * ONE-WAY BY DECISION (owner, 2026-11-20, after the Mr. Doc and Aegis
     * pre-release review). A rollback that re-opens People / HRD and closes
     * Logistics would hand a customer a module outside the plan they now
     * pay for, and its row deletes could hit a tenant that upgraded in the
     * meantime. Recovery, if ever needed, is a forward migration or the
     * pre-deploy database backup -- never this method.
     */
    public function down(): void
    {
        // Intentionally empty: see the doc comment above.
    }

    private function ready(): bool
    {
        foreach (['packages', 'subscriptions', 'workspaces', 'modules', 'tenant_workspaces', 'tenant_modules'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{package:int,hr:int,logistics:int,material_requests:?int}|null */
    private function ids(): ?array
    {
        $package = DB::table('packages')->where('slug', 'professional')->value('id');
        $hr = DB::table('workspaces')->where('key', 'hr')->value('id');
        $logistics = DB::table('workspaces')->where('key', 'logistics')->value('id');

        // A fresh database (tests, a new install) has no rows yet; the
        // seeders and provisioning read config/plans.php directly.
        if (! $package || ! $hr || ! $logistics) {
            return null;
        }

        return [
            'package' => (int) $package,
            'hr' => (int) $hr,
            'logistics' => (int) $logistics,
            'material_requests' => ($m = DB::table('modules')->where('key', 'material_requests')->value('id')) ? (int) $m : null,
        ];
    }

    /**
     * Tenants whose CURRENT subscription is on Professional -- "current"
     * meaning exactly what `Tenant::subscription()` means (`latestOfMany`,
     * the highest id). An older Professional row left behind by an upgrade
     * must not take People / HRD away from a tenant that is now on Business.
     *
     * @return list<int>
     */
    private function professionalTenantIds(int $packageId): array
    {
        $latest = DB::table('subscriptions')->selectRaw('MAX(id) as id')->groupBy('tenant_id');

        return DB::table('subscriptions')
            ->joinSub($latest, 'latest', 'latest.id', '=', 'subscriptions.id')
            ->where('subscriptions.package_id', $packageId)
            ->pluck('subscriptions.tenant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
};

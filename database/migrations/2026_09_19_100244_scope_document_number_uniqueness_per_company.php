<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.53.0 -- A PRODUCTION-BREAKING MULTI-TENANT DEFECT.
 *
 * Fourteen document-number columns carried a GLOBAL unique index while
 * their counters became PER-TENANT in v2.41.0. Those two facts cannot
 * both hold: every tenant's counter starts at 1, so every tenant's first
 * permit is PTW-2026-00001, and the global index means only the FIRST
 * tenant in the database can ever have it.
 *
 * The consequence is not cosmetic. The second customer to sign up cannot
 * create their first Permit To Work, Material Request, Purchase Order or
 * Task at all -- the insert fails on a duplicate key. IOMS now sells
 * self-service signup, so customer number two would have hit this on
 * their first day.
 *
 * v2.41.0's own release note stated the opposite ("confirmed no module
 * number column carries a global unique constraint"). That was wrong,
 * and it went unnoticed because every environment had exactly one tenant
 * with real data. It surfaced the moment a second tenant seeded a permit.
 *
 * THE FIX. A document number is unique WITHIN a company, exactly like an
 * invoice number -- two companies both having a PO-2026-00001 is normal
 * and correct. Each index becomes composite on (company_id, number).
 *
 * `goods_receipts` is deliberately NOT changed: it has no `company_id` of
 * its own (it inherits ownership from the purchase order, material
 * request or warehouse it was booked against), so there is no column here
 * to scope by. Giving it one is a schema change with its own backfill and
 * belongs in its own migration rather than being smuggled into this one.
 * Recorded in ROADMAP rather than left silent.
 */
return new class extends Migration
{
    /** table => [number column, existing global index name] */
    private const TARGETS = [
        'assets' => ['asset_code', 'assets_asset_code_unique'],
        'incidents' => ['incident_number', 'incidents_incident_number_unique'],
        'items' => ['item_code', 'items_item_code_unique'],
        'maintenance_requests' => ['request_number', 'maintenance_requests_request_number_unique'],
        'material_requests' => ['request_number', 'material_requests_request_number_unique'],
        'ncrs' => ['ncr_number', 'ncrs_ncr_number_unique'],
        'permits_to_work' => ['ptw_number', 'permits_to_work_ptw_number_unique'],
        'ppe_replacement_requests' => ['request_number', 'ppe_replacement_requests_request_number_unique'],
        'purchase_orders' => ['po_number', 'purchase_orders_po_number_unique'],
        'purchase_requisitions' => ['pr_number', 'purchase_requisitions_pr_number_unique'],
        'rfqs' => ['rfq_number', 'rfqs_rfq_number_unique'],
        'safety_observations' => ['observation_number', 'safety_observations_observation_number_unique'],
        'tasks' => ['task_number', 'tasks_task_number_unique'],
        'work_orders' => ['wo_number', 'work_orders_wo_number_unique'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$column, $oldIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id') || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Uniqueness only ever LOOSENS here, so no existing row can be
            // invalidated -- anything unique globally is unique per company.
            if ($this->indexExists($table, $oldIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($oldIndex));
            }

            $newIndex = $table.'_company_'.$column.'_unique';

            if (! $this->indexExists($table, $newIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique(['company_id', $column], $newIndex));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$column, $oldIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $newIndex = $table.'_company_'.$column.'_unique';

            if ($this->indexExists($table, $newIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($newIndex));
            }

            // Restore the global index ONLY if the data still satisfies it.
            // Once two companies share a number -- which this migration
            // makes legal -- re-adding it would fail, and rolling back must
            // not destroy or block on real records.
            $duplicates = DB::table($table)
                ->select($column)
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $duplicates && ! $this->indexExists($table, $oldIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($column, $oldIndex));
            }
        }
    }

    /**
     * Driver-agnostic on purpose. This began as an `information_schema`
     * query, which is MySQL-only and blew up the entire SQLite test suite
     * -- and a migration that cannot run on the test driver is a migration
     * nobody can test. `Schema::getIndexes()` answers the same question on
     * every connection Laravel supports.
     */
    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};

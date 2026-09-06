<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.41.0 -- per-tenant document numbering.
 *
 * THE DEFECT. `numbering_formats` was given a `tenant_id` in Milestone 3
 * (2026_08_18_100056) once it was noticed that every tenant was sharing
 * one format row. The COUNTER was never given the same treatment, so all
 * tenants continued to draw from a single `numbering_sequences` row per
 * module+period. Recorded in docs/ADR/025 as an open follow-up.
 *
 * Impact: not a confidentiality breach -- no tenant can read another's
 * records through a counter -- but each tenant sees GAPS in its own
 * document numbers wherever another tenant consumed the shared counter,
 * the size of a gap weakly discloses other tenants' document volume, and
 * in an HSE context a permit register with holes reads to an auditor like
 * missing or destroyed records. That is the wrong signal for the exact
 * artifact IOMS exists to make trustworthy.
 *
 * ---------------------------------------------------------------------
 * THE BACKFILL, AND WHY IT IS NOT MAX-PARSING
 *
 * The obvious approach -- parse every module's stored number column, per
 * tenant, to recover each one's true maximum -- is both fragile and
 * unnecessary. Fragile because there are 29 module keys, each with an
 * editable NumberingFormat (prefix, pattern, padding, reset period), so
 * "the sequence part of the string" is not reliably extractable once an
 * admin has customised a pattern. Unnecessary because of this invariant:
 *
 *     Every tenant drew from the SHARED counter, therefore the shared
 *     counter is already >= every tenant's own maximum issued sequence
 *     for that (module_key, period_key).
 *
 * So seeding each tenant's new row with the CURRENT SHARED last_number is
 * deterministic and provably safe:
 *
 *   - tenant_next = shared + 1 > any number that tenant already holds, so
 *     no live document number can ever be re-issued or duplicated;
 *   - nothing is parsed, so no custom pattern can defeat it;
 *   - no counter is reset to 0 -- the high-water mark only moves forward;
 *   - the number each tenant receives immediately after this migration is
 *     exactly the number it would have received without it, so there is no
 *     visible discontinuity at the cutover. From this point on the tenants
 *     diverge and no NEW gaps appear.
 *
 * Cross-tenant duplicate strings (two tenants both eventually issuing
 * PTW-2026-00043) are correct and intended: a document number is unique
 * WITHIN a customer, exactly like an invoice number. Verified before
 * writing this -- no module's number column carries a global unique
 * constraint, so nothing at the database level objects.
 *
 * ---------------------------------------------------------------------
 * WHY `tenant_scope` AND NOT JUST `tenant_id`
 *
 * The same reasoning ADR 025 already settled for `company_scope`: every
 * SQL engine treats each NULL in a unique index as distinct, so a
 * nullable `tenant_id` inside the unique key would NOT prevent duplicate
 * platform rows -- the precise trap that ADR is about. `tenant_scope` is
 * a plain, always-NOT-NULL mirror (0 = platform / no resolved tenant) and
 * is the column the constraint actually indexes. `tenant_id` is kept
 * alongside it purely as the real, cascading foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('numbering_sequences', 'tenant_id')) {
            Schema::table('numbering_sequences', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('tenant_scope')->default(0)->after('tenant_id');
            });
        }

        // Existing rows are the shared platform-scope counters.
        DB::table('numbering_sequences')->whereNull('tenant_id')->update(['tenant_scope' => 0]);

        // Widen the uniqueness key BEFORE inserting per-tenant rows, or the
        // old (module_key, period_key, company_scope) index rejects them.
        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropUnique('numbering_sequences_scope_unique');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unique(
                ['module_key', 'period_key', 'company_scope', 'tenant_scope'],
                'numbering_sequences_tenant_scope_unique'
            );
        });

        $tenantIds = DB::table('tenants')->pluck('id');

        if ($tenantIds->isEmpty()) {
            return;
        }

        $shared = DB::table('numbering_sequences')->where('tenant_scope', 0)->get();

        foreach ($shared as $row) {
            $rows = [];

            foreach ($tenantIds as $tenantId) {
                $exists = DB::table('numbering_sequences')
                    ->where('tenant_scope', $tenantId)
                    ->where('company_scope', $row->company_scope)
                    ->where('module_key', $row->module_key)
                    ->where('period_key', $row->period_key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'tenant_scope' => $tenantId,
                    'company_id' => $row->company_id,
                    'company_scope' => $row->company_scope,
                    'module_key' => $row->module_key,
                    'period_key' => $row->period_key,
                    'last_number' => $row->last_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('numbering_sequences')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // Collapsing per-tenant counters back into one shared counter must
        // not LOWER the high-water mark, or the next number issued after a
        // rollback could duplicate a live document. Carry the maximum
        // forward onto the platform row before dropping the tenant rows.
        $maxima = DB::table('numbering_sequences')
            ->where('tenant_scope', '!=', 0)
            ->select('module_key', 'period_key', 'company_scope', DB::raw('MAX(last_number) as max_number'))
            ->groupBy('module_key', 'period_key', 'company_scope')
            ->get();

        foreach ($maxima as $row) {
            DB::table('numbering_sequences')
                ->where('tenant_scope', 0)
                ->where('company_scope', $row->company_scope)
                ->where('module_key', $row->module_key)
                ->where('period_key', $row->period_key)
                ->where('last_number', '<', $row->max_number)
                ->update(['last_number' => $row->max_number]);
        }

        DB::table('numbering_sequences')->where('tenant_scope', '!=', 0)->delete();

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropUnique('numbering_sequences_tenant_scope_unique');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->unique(['module_key', 'period_key', 'company_scope'], 'numbering_sequences_scope_unique');
        });

        Schema::table('numbering_sequences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('tenant_scope');
        });
    }
};

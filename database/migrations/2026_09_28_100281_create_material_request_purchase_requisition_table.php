<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.69.0 -- CONSOLIDATION WAS IMPOSSIBLE TO RECORD, NOT MERELY MISSING.
 *
 * `purchase_requisitions.source_material_request_id` is a single nullable
 * foreign key, so one Purchase Requisition could source exactly one
 * Material Request. Consolidating demand -- the entire point of holding a
 * request back -- means several requests become ONE purchase, and that
 * relationship had nowhere to live.
 *
 * This is the deeper issue behind all three of this release's
 * requirements, and working around it would have meant either inventing a
 * parallel "consolidation group" entity (a second source of truth for
 * something the PR already is) or writing the extra request ids into a
 * notes field. Both were rejected. The PR *is* the consolidation; it just
 * needed to be able to say so.
 *
 * ONE SOURCE OF TRUTH, NOT TWO. The old column is backfilled into the
 * pivot and then DROPPED in the same migration, so there is never a
 * deployment -- or a code path -- where a PR's demand could be read from
 * two places that might disagree. Every reader moves to the relation.
 *
 * The pivot carries no `company_id` and needs none: both endpoints are
 * company-owned and globally scoped (`BelongsToCompany`), so a row is
 * only reachable by joining through a record the viewer can already see.
 * It is a plain pivot with no model, so TenantIsolationCoverageTest --
 * which walks `app/Models` -- is unaffected by design rather than by
 * omission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_purchase_requisition', function (Blueprint $table) {
            $table->id();

            /*
             * EVERY constraint on this table is named by hand, and it is
             * not stylistic. Laravel derives constraint names from
             * "{table}_{column}_{type}", and this table's conventional
             * pivot name is 37 characters before a column is appended:
             * `material_request_purchase_requisition_material_request_id_foreign`
             * is 65, and MySQL's identifier limit is 64. Verified by the
             * migration failing on exactly that, which is the same trap
             * already recorded in docs/CONVENTIONS.md from v2.51.0.
             */
            $table->foreignId('material_request_id');
            $table->foreignId('purchase_requisition_id');
            $table->timestamps();

            $table->foreign('material_request_id', 'mr_pr_material_request_fk')
                ->references('id')->on('material_requests')->cascadeOnDelete();

            $table->foreign('purchase_requisition_id', 'mr_pr_requisition_fk')
                ->references('id')->on('purchase_requisitions')->cascadeOnDelete();

            // The same demand must not be attached to one purchase twice.
            $table->unique(
                ['material_request_id', 'purchase_requisition_id'],
                'mr_pr_unique'
            );
        });

        // Preserve every existing link before the column goes away.
        DB::table('purchase_requisitions')
            ->whereNotNull('source_material_request_id')
            ->orderBy('id')
            ->chunkById(200, function ($requisitions) {
                $rows = [];

                foreach ($requisitions as $pr) {
                    $rows[] = [
                        'material_request_id' => $pr->source_material_request_id,
                        'purchase_requisition_id' => $pr->id,
                        'created_at' => $pr->created_at ?? now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('material_request_purchase_requisition')->insert($rows);
                }
            });

        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_material_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('source_material_request_id')->nullable()->after('department_id')->constrained('material_requests')->nullOnDelete();
        });

        // A column that holds one value cannot represent a consolidated
        // purchase. Restoring the OLDEST link per PR is the closest
        // lossless-for-the-common-case answer: a PR that was never
        // consolidated had exactly one, and gets exactly it back.
        $links = DB::table('material_request_purchase_requisition')
            ->orderBy('purchase_requisition_id')
            ->orderBy('id')
            ->get()
            ->groupBy('purchase_requisition_id');

        foreach ($links as $purchaseRequisitionId => $rows) {
            DB::table('purchase_requisitions')
                ->where('id', $purchaseRequisitionId)
                ->update(['source_material_request_id' => $rows->first()->material_request_id]);
        }

        Schema::dropIfExists('material_request_purchase_requisition');
    }
};

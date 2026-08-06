<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Task #51). `Milestone` was the one existing numbered-
     * document-shaped module with NO number at all -- everything else
     * (MaterialRequest, Incident, LeaveRequest, GoodsReceipt,
     * PpeReplacementRequest, Task) already had one. `App\Services\NumberGeneratorService`
     * already ships a `milestone` default format (MS-{YEAR}-{00001}), so
     * this is purely a schema catch-up.
     *
     * Deliberately NOT adding `HasWorkflow`/`HasApprovals` to `Milestone`
     * in this same pass -- see docs/ADR/012-milestone-numbering.md for
     * why: unlike MaterialRequest/LeaveRequest (a directional Draft ->
     * Submitted -> Approved lifecycle with distinct action buttons per
     * state), Milestone's `status` is edited via one free-form edit
     * dialog that lets an admin correct it to any of the 4 statuses at
     * will, alongside title/description/target_date, in a single submit
     * -- not a fit for a forward-only transition guard.
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->string('milestone_number')->nullable()->unique()->after('id');
        });

        // Backfill any existing rows (a fresh install/seed has none yet,
        // but this keeps an upgrade of a live install correct too).
        DB::table('milestones')->whereNull('milestone_number')->orderBy('id')->get(['id'])->each(function ($row) {
            DB::table('milestones')->where('id', $row->id)->update([
                'milestone_number' => app(\App\Services\NumberGeneratorService::class)->generate('milestone'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn('milestone_number');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.53.0 -- `project_name` becomes `work_reference`.
 *
 * v2.52.0 introduced the field to hold a permit's work identity when no
 * Project Master exists, and named it `project_name`. That name was the
 * problem: it reads as "the name of the project", so the UI ended up
 * labelled "Nama Pekerjaan / Job" sitting directly under a "Project"
 * selector, and users could not tell which of the two they were supposed
 * to fill in — or wrote compound answers like "Docking MV Sinar Mas /
 * Overhaul Crane #4" to cover both.
 *
 * The four concepts on a permit are distinct and now named as such:
 *
 *   Project           optional Project Master reference
 *                     ("MV Twin Sister 307 Docking Project")
 *   Work Reference    the operational identity of THIS work
 *                     ("Twin Sister 307", "Line 1", "Docking Area 2")
 *   Work Location     where it physically happens
 *                     ("Engine Room", "Main Deck / Port Side")
 *   Work Description  what is actually being done
 *
 * A rename rather than a new column: `project_name` shipped one release
 * ago and carrying both would leave two fields meaning the same thing,
 * which is exactly the confusion being fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('permits_to_work', 'project_name') && ! Schema::hasColumn('permits_to_work', 'work_reference')) {
            Schema::table('permits_to_work', function (Blueprint $table) {
                $table->renameColumn('project_name', 'work_reference');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permits_to_work', 'work_reference') && ! Schema::hasColumn('permits_to_work', 'project_name')) {
            Schema::table('permits_to_work', function (Blueprint $table) {
                $table->renameColumn('work_reference', 'project_name');
            });
        }
    }
};

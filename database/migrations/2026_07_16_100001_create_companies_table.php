<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-Company support. Every Department and Employee will belong to
     * a Company (see the following migrations that add company_id to
     * those tables). Existing single-company data is preserved and
     * backfilled to "GAJ" by the data-migration steps below, not deleted.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. GAJ, Maintenance
            $table->string('code', 20)->unique()->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

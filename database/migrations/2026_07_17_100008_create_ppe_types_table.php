<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PPE Master. Table-driven, Super-Admin-configurable per spec -- NO
     * hardcoded PPE types or replacement intervals anywhere in the app.
     * replacement_interval_months = null means "request-based" equipment
     * (e.g. Harness, Headlamp): no fixed replacement schedule, but every
     * issuance is still recorded in employee_ppe for history.
     */
    public function up(): void
    {
        Schema::create('ppe_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. Safety Helmet, Safety Shoes, Harness
            $table->unsignedSmallInteger('replacement_interval_months')->nullable(); // null = request-based, no fixed schedule
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_types');
    }
};

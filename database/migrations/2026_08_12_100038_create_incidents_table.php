<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HSE's first real module beyond PPE (v1.10.0). Workflow Engine only
     * (reported -> investigating -> closed) -- no Approval Engine, since
     * closing an incident is an HSE operational decision, not something
     * that needs a separate approver the way Material Request/Leave do.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('incident_date');
            $table->string('location')->nullable();
            $table->string('severity'); // minor, moderate, major, critical
            $table->string('category'); // injury, near_miss, property_damage, environmental, other
            $table->string('status')->default('reported');
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};

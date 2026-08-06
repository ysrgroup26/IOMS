<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 2 (Dynamic Module system, Task #42). Moves the module
     * registry from `config/modules.php` (a deploy-time file) into a DB
     * table, so Super Admin can manage the catalog itself (label, icon,
     * order) without a code deploy. NOT tenant-scoped -- like `packages`,
     * this is the platform's own shared catalog of what modules EXIST,
     * completely separate from the pre-existing `enabled_modules`
     * CompanySetting (which controls which of them are currently visible
     * app-wide -- that mechanism is UNCHANGED by this migration, see
     * docs/ADR/008-tenancy-foundation.md and docs/CONVENTIONS.md's
     * caching pitfalls for why it's deliberately left alone).
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->boolean('is_core')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};

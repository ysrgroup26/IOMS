<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 2 (Dynamic Workspace system, Task #43). DB-driven METADATA
     * catalog for the departments/workspaces the sidebar switcher offers
     * -- label, icon, display order, and a coarse active/inactive kill
     * switch, all editable by Super Admin without a code deploy.
     *
     * Deliberately NOT a full replacement of resources/js/lib/workspaces.js.
     * That file's `items` arrays (real routes, `moduleKey` gates,
     * `adminOnly` gates, `disabled` placeholders) stay in code -- a
     * workspace item is fundamentally tied to a real, already-built page;
     * letting this table define arbitrary routes would let an admin
     * configure a broken link, not a working feature (the exact same
     * "visibility only, not a page builder" boundary already drawn for
     * the `modules` table -- see that migration's own doc comment). This
     * table only overrides the label/icon/order/active-state that
     * `resources/js/lib/workspaces.js`'s WORKSPACES array already defines
     * for the same `key`.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('icon'); // lucide-react icon component name, matched client-side
            $table->string('tier'); // 'department' | 'global' -- mirrors WORKSPACES' own `tier`
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};

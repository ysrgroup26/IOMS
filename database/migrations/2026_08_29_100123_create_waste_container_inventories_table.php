<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 6/7/8).
     * Waste Container Inventory -- physical, reusable waste-handling
     * equipment (drums, IBC tanks, jumbo bags) tracked as a total/
     * available/in-use/damaged COUNT, deliberately NOT the same concept
     * as `waste_records` (actual waste material, e.g. "1,200 Liter used
     * oil") or `waste_storage_locations` (a TPS/storage PLACE register).
     * Confirmed via a full audit of every existing Waste*/Asset/Item/
     * Stock model before writing this migration -- none of them already
     * model "N physical containers of type X, with total/available/
     * in_use/damaged counts" (see this pass's own audit notes in
     * docs/MODULES.md's Waste Management section).
     *
     * Shape mirrors this module's own established "master row +
     * quantity tracking" convention (same as `waste_types`/
     * `waste_storage_locations`: company_id/name/code/status/notes), with
     * `available_quantity` deliberately NOT a stored column -- computed
     * live from `total - in_use - damaged`, same "never store what can
     * be derived" convention `Stock::getAvailableQuantityAttribute()`
     * and `WasteRecord`'s own `is_approaching_storage_limit`/
     * `is_storage_overdue` accessors already use throughout this
     * codebase. `storage_location_id` is an OPTIONAL FK back into the
     * existing `waste_storage_locations` table (reused, not duplicated)
     * so a container inventory row can optionally note which TPS it's
     * kept at.
     *
     * No stock-movement/history table introduced here -- the explicit
     * product instruction for this feature was "do not fabricate
     * movement history; allow the user to establish the current stock
     * state," so this table holds the CURRENT counts only, edited
     * directly (matching how `waste_storage_locations`/`waste_types`
     * themselves are edited directly, not through a change-log).
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_container_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('container_type'); // e.g. "Drum Limbah B3", "IBC Tank", "Jumbo Bag" -- free text, tenant-configurable, same convention as waste_storage_locations.container_type
            $table->string('code')->nullable(); // optional container code/number
            $table->string('unit')->default('unit'); // countable unit, e.g. "unit", "pcs"
            $table->unsignedInteger('total_quantity')->default(0);
            $table->unsignedInteger('in_use_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->decimal('capacity', 10, 2)->nullable(); // per-container capacity, e.g. 200
            $table->string('capacity_unit')->nullable(); // e.g. "Liter"
            $table->foreignId('storage_location_id')->nullable()->constrained('waste_storage_locations')->nullOnDelete();
            $table->string('status')->default('active'); // active, under_maintenance, disposed -- overall line status, independent of the in_use/damaged sub-counts
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_container_inventories');
    }
};

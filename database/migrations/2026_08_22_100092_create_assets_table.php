<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1C (Asset Management). `vendor_id`/
     * `purchase_order_id` are both nullable, real Procurement integration
     * points -- an asset CAN be registered directly from an issued PO
     * (see AssetController::createFromPo()), but doesn't have to be (an
     * asset already owned before this module existed can still be
     * registered manually). `responsible_person_id` points at `employees`
     * (the person accountable for the asset day-to-day), not `users` --
     * an asset is typically assigned to a workforce member, most of whom
     * have no system login, same distinction already established
     * elsewhere in this codebase (Employee vs. User).
     */
    public function up(): void
    {
        Schema::createIfMissing('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->date('purchase_date')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location')->nullable();
            $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

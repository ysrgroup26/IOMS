<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 16). Reuses the existing
     * `Vendor` model for licensed waste transporter/disposal vendors --
     * explicit instruction: "Do NOT create WasteVendor as a duplicate
     * vendor master." `Vendor.category` already exists as a free-text
     * classification field, but is not queryable in a structured way for
     * "which vendors handle waste" -- this adds one boolean flag,
     * additive and nullable-default-false, so it never affects any
     * existing vendor row or any existing Procurement query/view. A
     * vendor can be both a goods/services vendor AND a waste vendor --
     * the flag is independent of `type`/`category`, not a replacement.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'is_waste_vendor')) {
                $table->boolean('is_waste_vendor')->default(false)->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'is_waste_vendor')) {
                $table->dropColumn('is_waste_vendor');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.126.0 -- THE SIZE IS PART OF THE ISSUANCE.
 *
 * A company's own PPE register records a size beside the date for every
 * item it hands out -- "42" for boots, "L" for a uniform -- because that
 * is what the storeman needs when the item is replaced. IOMS recorded the
 * date and silently dropped the size, so importing a customer's workbook
 * lost half of each cell's meaning and the owner's instruction is explicit
 * that the two must stay related ("Pertahankan relasi antara jenis APD,
 * tanggal penerbitan/pemenuhan, dan ukuran yang sesuai").
 *
 * A column rather than a line in `remarks`: a size is a FIELD of the
 * issuance, it is read back per item, and parsing it out of free text
 * later is how the relation gets lost a second time.
 *
 * Nullable and not backfilled, because for every record issued before this
 * release the size genuinely is not known. A string rather than a number:
 * sizes are "42", "XL", "M/L" and "no. 9" depending on the equipment, and
 * forcing them into a numeric column would mean inventing a convention the
 * customer does not use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->string('size', 40)->nullable()->after('issued_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};

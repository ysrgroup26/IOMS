<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee Import (v1.6.8). Two changes needed to support what the
     * import spec actually asks for:
     *
     * 1. `department_id` becomes nullable. It was previously a required
     *    foreign key -- meaning "Department (or Unassigned if supported)"
     *    genuinely wasn't supported before this. An imported row with no
     *    department can now still be created (and flagged "Need
     *    Completion"), rather than having to be skipped entirely.
     *
     * 2. `email`, `address`, `emergency_contact_name`,
     *    `emergency_contact_phone` are added -- the spec lists these as
     *    optional import fields, but none of them existed as columns on
     *    `employees` at all before this. All nullable, matching "do not
     *    fail import because optional fields are empty."
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->text('address')->nullable()->after('email');
            $table->string('emergency_contact_name')->nullable()->after('address');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->dropColumn(['email', 'address', 'emergency_contact_name', 'emergency_contact_phone']);
        });
    }
};

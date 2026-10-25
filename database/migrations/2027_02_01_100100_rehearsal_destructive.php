<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('safety_equipment', function (Blueprint $table) { $table->dropColumn('rehearsal_tag'); }); }
    public function down(): void {}
};

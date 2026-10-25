<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('rehearsal_notes', function (Blueprint $table) { $table->id(); $table->string('body'); $table->timestamps(); });
        Schema::table('safety_equipment', function (Blueprint $table) { $table->string('rehearsal_tag')->nullable(); });
    }
    public function down(): void { Schema::dropIfExists('rehearsal_notes'); Schema::table('safety_equipment', fn (Blueprint $t) => $t->dropColumn('rehearsal_tag')); }
};

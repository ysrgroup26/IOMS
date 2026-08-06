<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Numbering Engine). Replaces six near-identical, lock-free
     * `generate*Number()` methods (MaterialRequest, Incident, LeaveRequest,
     * GoodsReceipt, PpeReplacementRequest, TaskService) -- each did a
     * read-then-write `ORDER BY ... DESC LIMIT 1` with no locking, a real
     * race condition under concurrent requests (two users submitting at
     * the same instant could get the same number, then crash on the
     * column's own unique constraint, or worse, silently not crash on a
     * DB without one). This centralizes generation behind
     * `NumberGeneratorService::generate()`, which locks a single counter
     * row per module+period inside a transaction.
     *
     * `numbering_formats`: the CONFIGURATION -- prefix/pattern/padding/
     * reset-period per module, optionally overridden per Company (Task
     * #57 will add the Settings UI for this). `company_id` nullable =
     * tenant-wide default; a specific `company_id` row overrides it for
     * that company only. Deliberately NOT unique-constrained on
     * (company_id, module_key) at the DB level, since MySQL treats
     * multiple NULLs as distinct and wouldn't prevent duplicate global
     * defaults anyway -- `NumberGeneratorService` enforces "at most one"
     * via `firstOrCreate`.
     *
     * `numbering_sequences`: the RUNTIME counter, one row per
     * (company_id, module_key, period_key) -- `company_id` is stored NULL
     * for the default "global" scope (every existing model's actual
     * current behavior: one shared counter across all companies, since
     * none of the six original methods filtered by company at all). This
     * migration does NOT change that scope -- see
     * `docs/ADR/009-numbering-engine.md` for why per-company sequences
     * were deliberately deferred rather than bundled in here (existing
     * `request_number`/`incident_number`/etc. columns have a DB-level
     * `unique()` constraint with no `company_id` in it; splitting
     * sequences per company without first widening those constraints to
     * composite would risk duplicate-number collisions).
     */
    public function up(): void
    {
        Schema::create('numbering_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('prefix');
            $table->string('pattern')->default('{PREFIX}-{YEAR}-{SEQ}');
            $table->unsignedTinyInteger('seq_padding')->default(5);
            $table->string('reset_period')->default('yearly'); // yearly, monthly, never
            $table->timestamps();

            $table->index(['company_id', 'module_key']);
        });

        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('period_key'); // e.g. '2026', '2026-08', or 'ALL' for reset_period=never
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        // A plain `unique(['company_id', 'module_key', 'period_key'])` would
        // NOT actually prevent two "global scope" rows (company_id NULL) for
        // the same module+period -- MySQL treats every NULL as distinct in a
        // unique index, so it would silently allow two independent counters
        // to be created under concurrent load, exactly the race condition
        // this table exists to close. A functional index normalizing NULL
        // to 0 closes that gap (requires MySQL 8.0.13+, which this stack's
        // Laravel 12 target already assumes).
        DB::statement(
            'ALTER TABLE numbering_sequences ADD UNIQUE KEY numbering_sequences_scope_unique (module_key, period_key, (COALESCE(company_id, 0)))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
        Schema::dropIfExists('numbering_formats');
    }
};

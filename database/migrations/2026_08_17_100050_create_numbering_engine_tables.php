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
     *
     * `company_scope` (v1.11 portability fix -- see
     * docs/ADR/025-numbering-sequence-portable-uniqueness.md): a plain,
     * ALWAYS-NOT-NULL mirror of `company_id`, with `0` standing in for
     * "global scope" (`company_id` NULL). `company_id` itself stays
     * nullable + FK, untouched, for whenever per-company sequences are
     * actually built. The original migration enforced uniqueness with a
     * MySQL 8.0.13+ functional index --
     * `(module_key, period_key, (COALESCE(company_id, 0)))` -- which
     * MariaDB 10.x (this stack's shared-hosting production target) does
     * not support at all (different, incompatible computed-column-index
     * syntax), so `ALTER TABLE` failed with a syntax error on every
     * MariaDB deploy. A plain composite unique index on a real column is
     * identical, portable SQL on both engines and closes the exact same
     * gap: every SQL engine treats each NULL in a unique index as
     * distinct from every other NULL, so a naive
     * `unique(['company_id', 'module_key', 'period_key'])` would NOT
     * actually prevent two concurrent "global scope" rows (company_id
     * NULL) for the same module+period -- exactly the race condition
     * this table exists to close, on EITHER engine, functional index or
     * not. Normalizing NULL to a real 0 in an ordinary NOT NULL column
     * closes that gap with nothing but standard SQL.
     *
     * Idempotent / partial-failure-safe (RC1 release audit): the ORIGINAL
     * (pre-portability-fix) version of this migration crashed on its
     * MySQL-only functional index AFTER both `Schema::create()` calls had
     * already succeeded -- Laravel therefore never recorded the migration
     * as run, but `numbering_formats` and `numbering_sequences` were both
     * already sitting in the database. Re-running the fixed migration
     * unconditionally would then fail immediately with "Table
     * numbering_formats already exists." `up()` now checks for each table
     * first and, for `numbering_sequences` specifically, upgrades an
     * already-existing-but-pre-fix table in place (adds `company_scope`,
     * backfills it, adds the unique index) instead of either erroring or
     * silently leaving it in the old, non-concurrency-safe shape. A truly
     * fresh database still gets the exact same end schema via the
     * Schema::create() branch -- this only changes what happens when a
     * table survived an earlier failed deploy attempt.
     */
    public function up(): void
    {
        if (! Schema::hasTable('numbering_formats')) {
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
        }

        if (! Schema::hasTable('numbering_sequences')) {
            Schema::create('numbering_sequences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
                // App-managed mirror of company_id (0 = global scope) -- see
                // this migration's doc comment above. Always set explicitly
                // by NumberGeneratorService, never left to a DB default, so
                // its value is never ambiguous.
                $table->unsignedBigInteger('company_scope');
                $table->string('module_key');
                $table->string('period_key'); // e.g. '2026', '2026-08', or 'ALL' for reset_period=never
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();

                // Plain composite unique index -- standard SQL, identical on
                // MySQL 8+ and MariaDB 10.x. Replaces the original migration's
                // MySQL-only functional index; see this migration's class doc
                // comment and docs/ADR/025 for why company_scope exists.
                $table->unique(['module_key', 'period_key', 'company_scope'], 'numbering_sequences_scope_unique');
            });
        } else {
            // Table survived an earlier failed deploy attempt (see this
            // method's doc comment) -- bring it up to the current schema.
            if (! Schema::hasColumn('numbering_sequences', 'company_scope')) {
                Schema::table('numbering_sequences', function (Blueprint $table) {
                    $table->unsignedBigInteger('company_scope')->default(0)->after('company_id');
                });

                DB::table('numbering_sequences')->update(['company_scope' => DB::raw('COALESCE(company_id, 0)')]);
            }

            if (! Schema::hasIndex('numbering_sequences', 'numbering_sequences_scope_unique')) {
                Schema::table('numbering_sequences', function (Blueprint $table) {
                    $table->unique(['module_key', 'period_key', 'company_scope'], 'numbering_sequences_scope_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
        Schema::dropIfExists('numbering_formats');
    }
};

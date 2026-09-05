<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.1 (HSE Domain Hardening II, Part 7/8). `SafetyEquipment`
     * (Workstream B10) already existed as the operational HSE equipment
     * register -- audited first and reused, NOT duplicated. Two gaps
     * closed:
     *
     * 1. `SafetyEquipment::TYPES` was a hardcoded PHP array (fire_
     *    extinguisher/safety_shower/eyewash_station/emergency_alarm/
     *    spill_kit/other) -- the explicit new requirement is "Super Admin
     *    should be able to add future HSE equipment types" / "do not
     *    hardcode as immutable code values". `hse_equipment_types` mirrors
     *    `hazard_categories`' own established shape exactly
     *    (company_id/name/code/description/is_active/sort_order) --
     *    seeded below with the SAME codes the hardcoded array already
     *    used, plus the newly-requested ones (APAR, HT, Gas Detector,
     *    Blower, TOA), so `safety_equipment.type` (left as a plain string
     *    column, unchanged -- no FK, no data migration needed) keeps
     *    validating against exactly the same existing values for every
     *    already-created row, now sourced from a configurable table
     *    instead of a constant.
     *
     * 2. `safety_equipment` only ever stored ONE
     *    last_inspection_date/next_inspection_due pair -- no history.
     *    `safety_equipment_inspections` is a real child table (mirrors
     *    `gas_test_records`' own "individually meaningful, time-series"
     *    reasoning exactly) so a piece of equipment's full inspection
     *    history is queryable, not overwritten on each re-inspection.
     *
     * v1.11.2 production incident: `php artisan migrate` failed at this
     * migration with MySQL errno 150 ("foreign key constraint is
     * incorrectly formed") on
     * `safety_equipment_inspections_safety_equipment_id_foreign`. Root
     * cause, confirmed against Laravel's own MySQL grammar
     * (vendor/laravel/framework/.../Schema/Grammars/MySqlGrammar.php
     * `compileCreateTable()` vs. the base `compileForeign()`), NOT
     * guessed: Laravel compiles a `CREATE TABLE` and every subsequent
     * `->constrained()` foreign key as SEPARATE statements, in that
     * order -- the CREATE TABLE always succeeds first (all columns,
     * unconstrained), then each `alter table ... add constraint ...
     * foreign key` runs as its own statement afterward. That means the
     * failure on `safety_equipment_id`'s FK left
     * `safety_equipment_inspections` PARTIALLY created in production --
     * the table exists with every column, but with ZERO of its three
     * foreign keys attached (company_id's and inspector_id's FK
     * statements never even ran, since they were queued after the one
     * that failed).
     *
     * That partial state made a naive retry actively dangerous:
     * `Schema::createIfMissing()` is a no-op once `Schema::hasTable()` is
     * true, so simply re-running this file again would silently SKIP
     * re-creating the table and never re-attempt the missing foreign
     * keys -- masking the bug as a "successful" migration while leaving
     * the table permanently unconstrained. Column types were verified
     * (not assumed) to already match: `$table->id()` and
     * `$table->foreignId(...)` both compile to `bigint(20) unsigned`, and
     * `git log --follow` on both this file and
     * `2026_08_20_100074_create_safety_equipment_table.php` shows neither
     * ever used a legacy `increments()`/int definition -- there is no
     * type mismatch in the code that created `safety_equipment.id`.
     *
     * Given a genuine mismatch couldn't be confirmed from the repository
     * alone (this environment has no live database access) and the
     * explicit instruction not to guess or require manual SQL, this
     * migration is now:
     * - Retry-safe: table creation is decoupled from FK creation, and
     *   each foreign key is added only if not already present (checked
     *   via `information_schema`, not assumed) -- safe to re-run any
     *   number of times, whether the table is missing, partially
     *   created, or already fully constrained.
     * - Self-verifying: `safety_equipment`'s actual storage engine is
     *   read from `information_schema.TABLES` at migration time and
     *   converted to InnoDB in place ONLY if it is not already InnoDB
     *   (an engine mismatch -- e.g. a table created while
     *   `config('database.connections.mysql.engine')` was not yet set to
     *   `InnoDB`, or under a MySQL server whose own default differs -- is
     *   the single most common real-world cause of an otherwise-
     *   type-matching FK failing with errno 150). This never touches
     *   `safety_equipment`'s column definitions, primary key, or data --
     *   an engine conversion is a standard, non-destructive, in-place
     *   MySQL operation, not a rebuild.
     */
    public function up(): void
    {
        Schema::createIfMissing('hse_equipment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Self-heal: if `safety_equipment` is not InnoDB, MySQL refuses
        // any foreign key referencing it with exactly the error this
        // migration hit (errno 150). A no-op statement if it's already
        // InnoDB (the normal case) -- only ever changes storage engine,
        // never column types/PK/data.
        $this->ensureInnoDb('safety_equipment');

        // Table creation and FK creation are deliberately decoupled (see
        // the class doc comment above) so a partially-created table from
        // a previous failed attempt can be safely completed on retry
        // without Schema::createIfMissing() skipping the FK work.
        Schema::createIfMissing('safety_equipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safety_equipment_id');
            $table->unsignedBigInteger('company_id');
            $table->date('inspection_date');
            $table->unsignedBigInteger('inspector_id')->nullable();
            $table->string('condition')->default('good'); // good, fair, poor, damaged
            $table->string('result')->default('pass'); // pass, fail, needs_action
            $table->text('findings')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['safety_equipment_id', 'inspection_date']);
        });

        $this->addForeignKeyIfMissing(
            'safety_equipment_inspections',
            'safety_equipment_inspections_safety_equipment_id_foreign',
            fn (Blueprint $table) => $table->foreign('safety_equipment_id')->references('id')->on('safety_equipment')->cascadeOnDelete()
        );
        $this->addForeignKeyIfMissing(
            'safety_equipment_inspections',
            'safety_equipment_inspections_company_id_foreign',
            fn (Blueprint $table) => $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete()
        );
        $this->addForeignKeyIfMissing(
            'safety_equipment_inspections',
            'safety_equipment_inspections_inspector_id_foreign',
            fn (Blueprint $table) => $table->foreign('inspector_id')->references('id')->on('users')->nullOnDelete()
        );

        // Seed every company that already has SafetyEquipment rows with
        // the exact set of type codes those rows already use (so no
        // existing row's `type` value would suddenly fail validation),
        // plus the newly-requested operational equipment categories.
        $companyIds = DB::table('companies')->pluck('id');
        $defaults = [
            ['code' => 'fire_extinguisher', 'name' => 'Fire Extinguisher (APAR)'],
            ['code' => 'safety_shower', 'name' => 'Safety Shower'],
            ['code' => 'eyewash_station', 'name' => 'Eyewash Station'],
            ['code' => 'emergency_alarm', 'name' => 'Emergency Alarm'],
            ['code' => 'spill_kit', 'name' => 'Spill Kit'],
            ['code' => 'handheld_radio', 'name' => 'Handheld Radio (HT)'],
            ['code' => 'gas_detector', 'name' => 'Gas Detector'],
            ['code' => 'blower', 'name' => 'Blower / Ventilator'],
            ['code' => 'public_address', 'name' => 'Public Address (TOA)'],
            ['code' => 'other', 'name' => 'Other'],
        ];
        $now = now();
        foreach ($companyIds as $companyId) {
            $rows = collect($defaults)->map(fn ($d, $i) => [
                'company_id' => $companyId,
                'name' => $d['name'],
                'code' => $d['code'],
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('hse_equipment_types')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_equipment_inspections');
        Schema::dropIfExists('hse_equipment_types');
    }

    /** Converts a table to InnoDB in place if (and only if) it isn't already -- see class doc comment. Column defs/PK/data untouched. */
    private function ensureInnoDb(string $table): void
    {
        // v2.37.0 (Master Audit, P1): storage engines and
        // `information_schema` are MySQL concepts -- querying them threw
        // on any other driver and blocked non-MySQL test databases from
        // provisioning. The MySQL path below is unchanged.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable($table)) {
            return;
        }

        $row = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            [$table]
        );

        if ($row && $row->engine && strtolower($row->engine) !== 'innodb') {
            DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
        }
    }

    /** Adds a named foreign key only if it doesn't already exist -- makes this migration safe to re-run against a partially-created table. */
    private function addForeignKeyIfMissing(string $table, string $constraintName, \Closure $define): void
    {
        // v2.37.0 (Master Audit, P1): `information_schema` is MySQL-only.
        // The "does this FK already exist" guard exists to make this
        // migration re-runnable against a partially-created MySQL table;
        // a freshly-provisioned non-MySQL test database is never in that
        // partial state, so defining the key directly is both correct and
        // the only portable option. MySQL behaviour is unchanged.
        if (DB::getDriverName() !== 'mysql') {
            Schema::table($table, $define);

            return;
        }

        $row = DB::selectOne(
            "select 1 as found from information_schema.table_constraints
             where constraint_schema = database() and table_name = ? and constraint_name = ? and constraint_type = 'FOREIGN KEY'",
            [$table, $constraintName]
        );

        if (! $row) {
            Schema::table($table, $define);
        }
    }
};

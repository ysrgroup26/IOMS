<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * THE MIGRATION BREAKS SQLITE CANNOT SHOW YOU.
 *
 * The suite provisions on SQLite, which is why it needs no MySQL and runs
 * in ninety seconds. The cost is that SQLite silently tolerates things
 * MySQL refuses, so a migration can be green here and impossible there.
 *
 * `->after('column')` is the sharp one. SQLite has no column positioning
 * at all and Laravel drops the clause; MySQL executes it and fails if the
 * anchor does not exist yet. `2026_09_30_120000` anchored a column on
 * `subscriptions.additional_users`, which `2026_11_09_100350` creates two
 * months LATER in migration order — so every existing deployment was fine
 * (the migration had already run) while no new MySQL database could be
 * provisioned at all. Nothing in the suite could see it.
 *
 * This reads the migrations as text, in the order Laravel runs them, and
 * builds up which columns each table has by the time each file executes.
 * An `after()` naming a column that does not exist yet fails here, on
 * SQLite, in milliseconds.
 *
 * Deliberately a STATIC read rather than a live migration run: the point
 * is to catch the fault on the engine that cannot reproduce it.
 */
class MigrationPortabilityTest extends TestCase
{
    /** Column-defining calls on a Blueprint. */
    private const COLUMN_METHODS = 'id|bigIncrements|increments|uuid|ulid|foreignId|foreignUuid|foreignUlid|string|char|text|mediumText|longText|tinyText|integer|tinyInteger|smallInteger|mediumInteger|bigInteger|unsignedInteger|unsignedTinyInteger|unsignedSmallInteger|unsignedMediumInteger|unsignedBigInteger|float|double|decimal|unsignedDecimal|boolean|enum|set|json|jsonb|date|dateTime|dateTimeTz|time|timeTz|timestamp|timestampTz|year|binary|ipAddress|macAddress|geometry|point|morphs|nullableMorphs|rememberToken';

    /** @return list<string> absolute migration paths, in the order Laravel runs them */
    private function migrations(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($files);

        return $files;
    }

    /** PHP comments removed, so prose about a technique is not read as the technique. */
    private function code(string $path): string
    {
        $out = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * THE RULE, STATED NARROWLY ON PURPOSE.
     *
     * An `after('x')` fails only when `x` is created by a migration that
     * runs LATER than the one anchoring on it. That is exactly the break
     * MySQL reports and SQLite cannot, and nothing else.
     *
     * An anchor this parser never sees created at all is SKIPPED rather
     * than reported: columns arrive through `id()`, `morphs()`,
     * package migrations and raw SQL, and a text scan that guessed at
     * those would cry wolf until somebody deleted it. A test that is
     * silent about what it cannot see is worth more than one that is
     * loud about everything.
     */
    public function test_no_after_anchor_names_a_column_created_by_a_later_migration(): void
    {
        $files = $this->migrations();

        /** @var array<string, int> "table.column" => index of the file that first creates it */
        $createdAt = [];
        /** @var list<array{file: int, table: string, column: string}> */
        $anchors = [];

        foreach ($files as $index => $file) {
            $code = $this->code($file);

            preg_match_all(
                '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"].*?(?=Schema::|\z)/s',
                $code,
                $blocks,
                PREG_SET_ORDER
            );

            foreach ($blocks as [$block, $table]) {
                preg_match_all(
                    '/\$table->(?:'.self::COLUMN_METHODS.')\(\s*[\'"]([a-z0-9_]+)[\'"]/',
                    $block,
                    $added
                );

                foreach ($added[1] as $column) {
                    $createdAt["{$table}.{$column}"] ??= $index;
                }

                preg_match_all('/->after\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/', $block, $found);

                foreach ($found[1] as $column) {
                    $anchors[] = ['file' => $index, 'table' => $table, 'column' => $column];
                }
            }
        }

        $problems = [];

        foreach ($anchors as $anchor) {
            $key = "{$anchor['table']}.{$anchor['column']}";
            $createdIn = $createdAt[$key] ?? null;

            if ($createdIn !== null && $createdIn > $anchor['file']) {
                $problems[] = sprintf(
                    '%s anchors on `%s`, which is only created later by %s.',
                    basename($files[$anchor['file']]),
                    $key,
                    basename($files[$createdIn])
                );
            }
        }

        $this->assertSame(
            [],
            $problems,
            "An `after()` anchor must already exist when its migration runs.\n"
            ."SQLite ignores column positioning, so this breaks ONLY on MySQL, and only on a fresh\n"
            ."database -- meaning existing deployments look fine while no new one can be provisioned:\n\n  "
            .implode("\n  ", $problems)."\n"
        );
    }

    /**
     * A second, cheaper guard on the same theme: raw MySQL-only SQL has
     * to be behind a driver check, or the suite cannot provision at all.
     * Six migrations had to be made portable before `phpunit.xml` could
     * exist (v2.37.0); this keeps a seventh from arriving unnoticed.
     */
    public function test_mysql_only_statements_are_guarded_by_a_driver_check(): void
    {
        $problems = [];

        foreach ($this->migrations() as $file) {
            $source = $this->code($file);

            $usesMysqlOnlySql = (bool) preg_match(
                '/(MODIFY COLUMN|information_schema|SHOW FULL COLUMNS|ALGORITHM=|ENGINE=InnoDB)/i',
                $source
            );

            if (! $usesMysqlOnlySql) {
                continue;
            }

            $guarded = str_contains($source, 'getDriverName()')
                || str_contains($source, "driver() === 'mysql'")
                || str_contains($source, 'isMySql');

            if (! $guarded) {
                $problems[] = basename($file);
            }
        }

        $this->assertSame([], $problems, 'MySQL-only SQL must be guarded by a driver check: '.implode(', ', $problems));
    }
}

<?php

/**
 * migration-policy.php -- may these NEW migrations run without a person? (ADR 076)
 *
 * Installed root-owned at /usr/local/lib/ioms-ops/migration-policy.php and run by
 * ioms-ops AS THE APP USER, with the migration sources on stdin. It never reads
 * the repository itself and never executes the code it judges: it tokenizes it.
 *
 * Input (stdin): blocks of
 *     ==== <status> <path>
 *     <file contents>
 * where <status> is git's name-status letter (A added, M modified, D deleted, R renamed).
 *
 * Output: one line per file, "additive <path>" or "review <path>: <reason>".
 * Exit 0 when every file is additive, 1 when any needs review, 2 on bad input.
 *
 * ADDITIVE means the up() method can only ADD schema that old code ignores:
 *   - Schema::create(...)
 *   - Schema::table(...) adding a column that is nullable or has a default, an
 *     index, or a foreign key on such a column
 *   - Schema::hasTable / hasColumn guards
 * Everything else is REVIEW, conservatively: dropping, renaming or changing
 * anything; raw SQL or any DB:: call; writing data; using a model; calling a
 * helper ($this->...) whose body this file does not inspect; a NOT NULL column
 * without a default on an existing table; and any migration that is modified,
 * deleted or renamed rather than added. When in doubt, a person decides.
 */
const COLUMN_TYPES = [
    'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz', 'decimal',
    'double', 'enum', 'float', 'foreignId', 'foreignIdFor', 'foreignUlid', 'foreignUuid', 'geometry', 'increments',
    'integer', 'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumInteger', 'mediumText', 'morphs',
    'nullableMorphs', 'smallInteger', 'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'tinyInteger',
    'tinyText', 'unsignedBigInteger', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
    'unsignedTinyInteger', 'ulid', 'uuid', 'year', 'set',
];
// Column helpers that are safe on an existing table without a default (they are nullable by construction).
const SAFE_WITHOUT_DEFAULT = ['nullableMorphs', 'nullableTimestamps', 'nullableUlidMorphs', 'nullableUuidMorphs', 'softDeletes', 'softDeletesTz', 'timestamps', 'timestampsTz', 'rememberToken'];
const FORBIDDEN_CALLS = '/^(drop|rename|change|truncate|delete|update|insert|upsert|statement|unprepared|raw|disableForeignKeyConstraints|enableForeignKeyConstraints|withoutForeignKeyConstraints)/i';
// createIfMissing is the project's own macro (AppServiceProvider): hasTable() then create().
const ALLOWED_SCHEMA_STATICS = ['create', 'createIfMissing', 'table', 'hasTable', 'hasColumn', 'hasColumns', 'hasIndex', 'getColumnListing'];

function judge(string $src): ?string
{
    $tokens = token_get_all($src);
    $body = upBody($tokens);
    if ($body === null) {
        return 'no up() method found';
    }

    $n = count($body);
    for ($i = 0; $i < $n; $i++) {
        $t = $body[$i];
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$this') {
            return 'calls a helper ($this->...) whose effect is not inspected';
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = ltrim($t[1], '\\');
            $next = nextSignificant($body, $i);
            $isStatic = $next !== null && is_array($body[$next]) && $body[$next][0] === T_DOUBLE_COLON;
            if ($isStatic) {
                $member = nextSignificant($body, $next);
                $method = $member !== null && is_array($body[$member]) ? $body[$member][1] : '';
                $after = $member !== null ? nextSignificant($body, $member) : null;
                $isCall = $after !== null && $body[$after] === '(';
                // Reading a constant (self::LABELS) is not an action; only a call can be.
                if (! $isCall) {
                    continue;
                }
                // Asking which database this is changes nothing.
                if ($name === 'DB' && $method === 'getDriverName') {
                    continue;
                }
                $base = substr($name, strrpos('\\'.$name, '\\'));
                if ($base === 'Schema') {
                    if (! in_array($method, ALLOWED_SCHEMA_STATICS, true)) {
                        return "Schema::{$method}() is not additive";
                    }
                } else {
                    return "uses {$base}::{$method}() (only Schema:: is allowed)";
                }
            }
        }
        if (is_array($t) && $t[0] === T_OBJECT_OPERATOR) {
            $m = nextSignificant($body, $i);
            $method = $m !== null && is_array($body[$m]) ? $body[$m][1] : '';
            if (preg_match(FORBIDDEN_CALLS, $method)) {
                return "->{$method}() is not additive";
            }
        }
        if (is_array($t) && in_array($t[0], [T_NEW], true)) {
            return 'instantiates a class inside up()';
        }
    }

    return notNullOnExistingTable($body);
}

/** Inside Schema::table(...), every added column must be nullable or have a default. */
function notNullOnExistingTable(array $body): ?string
{
    $n = count($body);
    for ($i = 0; $i < $n; $i++) {
        if (! (is_array($body[$i]) && $body[$i][0] === T_STRING && $body[$i][1] === 'table')) {
            continue;
        }
        $prev = prevSignificant($body, $i);
        if ($prev === null || ! is_array($body[$prev]) || $body[$prev][0] !== T_DOUBLE_COLON) {
            continue;
        }
        $open = nextSignificant($body, $i);
        if ($open === null || $body[$open] !== '(') {
            continue;
        }
        $close = matching($body, $open, '(', ')');
        $statement = [];
        for ($j = $open + 1; $j < $close; $j++) {
            if ($body[$j] === ';') {
                if ($reason = judgeColumnStatement($statement)) {
                    return $reason;
                }
                $statement = [];
            } else {
                $statement[] = $body[$j];
            }
        }
        // The last statement of an arrow function (fn ($t) => $t->string('x')) has no ';'.
        if ($reason = judgeColumnStatement($statement)) {
            return $reason;
        }
        $i = $close;
    }

    return null;
}

function judgeColumnStatement(array $tokens): ?string
{
    $calls = [];
    $n = count($tokens);
    for ($k = 0; $k < $n; $k++) {
        if (is_array($tokens[$k]) && $tokens[$k][0] === T_OBJECT_OPERATOR) {
            $m = nextSignificant($tokens, $k);
            if ($m !== null && is_array($tokens[$m])) {
                $calls[] = $tokens[$m][1];
            }
        }
    }
    if ($calls === []) {
        return null;
    }
    $first = $calls[0];
    if (in_array($first, SAFE_WITHOUT_DEFAULT, true) || ! in_array($first, COLUMN_TYPES, true)) {
        return null; // an index, a foreign key, a timestamps() pair, ...
    }
    foreach (['nullable', 'default', 'useCurrent', 'storedAs', 'virtualAs'] as $ok) {
        if (in_array($ok, $calls, true)) {
            return null;
        }
    }

    return "adds a NOT NULL column without a default to an existing table ({$first})";
}

function upBody(array $tokens): ?array
{
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            $name = nextSignificant($tokens, $i);
            if ($name !== null && is_array($tokens[$name]) && strtolower($tokens[$name][1]) === 'up') {
                for ($j = $name; $j < $n; $j++) {
                    if ($tokens[$j] === '{') {
                        $end = matching($tokens, $j, '{', '}');

                        return array_slice($tokens, $j + 1, $end - $j - 1);
                    }
                }
            }
        }
    }

    return null;
}

function matching(array $tokens, int $open, string $o, string $c): int
{
    $depth = 0;
    $n = count($tokens);
    for ($i = $open; $i < $n; $i++) {
        $v = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        // Curly-brace openers inside strings come through as T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES.
        if ($v === $o || ($o === '{' && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($v === $c) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $n - 1;
}

function nextSignificant(array $t, int $i): ?int
{
    for ($j = $i + 1, $n = count($t); $j < $n; $j++) {
        if (! (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            return $j;
        }
    }

    return null;
}

function prevSignificant(array $t, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (! (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
            return $j;
        }
    }

    return null;
}

// ------------------------------------------------------------------ main
$input = stream_get_contents(STDIN);
if ($input === false || ! preg_match('/^==== /m', $input)) {
    fwrite(STDERR, "no migration blocks on stdin\n");
    exit(2);
}
$blocks = preg_split('/^==== (\S+) (\S+)\R/m', $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
$review = false;
for ($i = 0; $i + 1 < count($blocks); $i += 3) {
    [$status, $path] = [$blocks[$i], $blocks[$i + 1]];
    $src = $blocks[$i + 2] ?? '';
    if (! preg_match('/^(A|M|D|R\d*)$/', $status)) {
        fwrite(STDERR, "bad status {$status}\n");
        exit(2);
    }
    $reason = match (true) {
        $status !== 'A' => 'an existing migration was '.(['M' => 'modified', 'D' => 'deleted'][$status] ?? 'renamed'),
        default => judge($src),
    };
    if ($reason === null) {
        echo "additive {$path}\n";
    } else {
        echo "review {$path}: {$reason}\n";
        $review = true;
    }
}
exit($review ? 1 : 0);

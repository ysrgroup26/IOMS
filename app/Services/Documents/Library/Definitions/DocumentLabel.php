<?php

namespace App\Services\Documents\Library\Definitions;

use App\Support\NameFormatter;

/**
 * v2.112.0 -- a stored token, written the way a reader expects.
 *
 * Every definition had its own `ucwords(str_replace('_', ' ', ...))`,
 * which is three chances to spell the same enum two ways across the ten
 * documents the library publishes. A document a client reads must not
 * show the database's spelling (CONVENTIONS.md, v2.110.0), and it must
 * not show it differently on two reports either.
 *
 * `null` stays `null` all the way through, because the renderer prints an
 * absent value as an EMPTY cell: "no recorded status" and "status: -" are
 * different claims.
 */
final class DocumentLabel
{
    public static function humanize(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        /*
         * v2.112.0 -- through NameFormatter, not `ucwords`, so the
         * acronyms this product already knows about survive. `pkwtt`
         * title-cased is "Pkwtt", which is not a word and not the name of
         * a contract; `hse_officer` is "Hse Officer". One acronym list
         * (config/naming.php), used by the screen and by the document.
         */
        return NameFormatter::title(str_replace('_', ' ', trim($token)));
    }

    /** Uppercase for a verdict column, where a result reads as a stamp. */
    public static function verdict(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        return mb_strtoupper(str_replace('_', ' ', trim($token)));
    }

    /**
     * The period a date filter describes, stated so a filtered register
     * can never be mistaken for a complete one.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function period(array $filters, string $fromKey = 'from', string $toKey = 'to'): ?string
    {
        $from = $filters[$fromKey] ?? null;
        $to = $filters[$toKey] ?? null;

        if (! $from && ! $to) {
            return null;
        }

        return trim(($from ?: '...').' s/d '.($to ?: '...'));
    }
}

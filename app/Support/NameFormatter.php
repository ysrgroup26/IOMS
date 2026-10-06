<?php

namespace App\Support;

/**
 * v2.105.0 -- ONE DEFINITION OF HOW A NAME IS COMPARED AND HOW IT IS WRITTEN.
 *
 * A spreadsheet column is where "Mechanic", "mechanic", " MECHANIC " and
 * "mechanic  senior" all come from, usually in the same file. Two separate
 * questions have to be answered about them, and conflating the two is what
 * produces duplicate master data:
 *
 *   IS THIS THE SAME NAME?   `key()` -- trimmed, inner whitespace
 *                            collapsed, case folded. Everything that
 *                            detects, matches or de-duplicates compares on
 *                            this and nothing else.
 *
 *   HOW IS IT WRITTEN DOWN?  `department()` and `title()` -- the stored,
 *                            displayed form.
 *
 * WHAT IS DELIBERATELY NOT MERGED. `key()` collapses only case and spacing.
 * "Mechanic" and "Mechanic Vessel" are different names and stay two
 * departments, because they are two departments; nothing here does
 * stemming, prefix matching or similarity. The only place similarity is
 * considered at all is `MasterDataDetector`'s typo SUGGESTION, which
 * proposes and never merges.
 *
 * THE TWO WRITTEN FORMS, and why they differ:
 *
 *   Department -> MECHANIC, MECHANIC VESSEL
 *       A department reads as a label on an organisation chart. Upper case
 *       also makes the acronym question disappear: HSE is already HSE.
 *
 *   Position and person -> Mechanic Senior, Budi Santoso, HSE Officer
 *       These are read as names in a sentence, in a table and on a printed
 *       permit, where upper case shouts. Acronyms are preserved from
 *       `config/naming.php`, so "hse officer" becomes "HSE Officer" rather
 *       than "Hse Officer".
 *
 * Every stage uses these: detection, matching, creation and storage. That
 * is the whole point -- when the four disagreed, one department became
 * three (see docs/CONVENTIONS.md).
 */
class NameFormatter
{
    /**
     * The stored base form: trimmed, with runs of inner whitespace
     * collapsed to one space. Non-breaking spaces count as whitespace,
     * because a spreadsheet pasted from a web page is full of them.
     */
    public static function collapse(?string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{200B}"], ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * What two names are compared by. Case and spacing are not meaning;
     * everything else is.
     */
    public static function key(?string $value): string
    {
        return mb_strtolower(self::collapse($value));
    }

    /** A department, as stored and displayed: MECHANIC VESSEL. */
    public static function department(?string $value): string
    {
        return mb_strtoupper(self::collapse($value));
    }

    /**
     * A position or a person, as stored and displayed: Mechanic Senior,
     * HSE Officer, Budi Santoso.
     */
    public static function title(?string $value): string
    {
        $collapsed = self::collapse($value);

        if ($collapsed === '') {
            return '';
        }

        $acronyms = self::acronyms();

        return implode(' ', array_map(
            fn (string $word) => self::titleWord($word, $acronyms),
            explode(' ', $collapsed)
        ));
    }

    /**
     * Hyphens and apostrophes join two words rather than ending one, so
     * each side is capitalised: "abdul-rahman" -> "Abdul-Rahman",
     * "o'brien" -> "O'Brien". Handled before the acronym check on the
     * whole word, so "non-hse" still yields "Non-HSE".
     *
     * @param  array<int, string>  $acronyms  upper-cased, for comparison
     */
    private static function titleWord(string $word, array $acronyms): string
    {
        if ($word === '') {
            return $word;
        }

        foreach (['-', "'", '/'] as $separator) {
            if (str_contains($word, $separator)) {
                return implode($separator, array_map(
                    fn (string $part) => self::titleWord($part, $acronyms),
                    explode($separator, $word)
                ));
            }
        }

        if (in_array(mb_strtoupper($word), $acronyms, true)) {
            return mb_strtoupper($word);
        }

        return mb_strtoupper(mb_substr($word, 0, 1)).mb_strtolower(mb_substr($word, 1));
    }

    /** @return array<int, string> */
    private static function acronyms(): array
    {
        return array_map(
            fn (string $acronym) => mb_strtoupper($acronym),
            config('naming.acronyms', [])
        );
    }
}

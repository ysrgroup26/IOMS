<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * A DATE OUT OF A SPREADSHEET CELL, OR NOTHING AT ALL.
 *
 * The one rule this class exists to enforce: a value this code cannot
 * READ becomes `null`. It never becomes a date nobody typed.
 *
 * What it replaces was `date('Y-m-d', strtotime($cell))`, which broke that
 * rule in two directions:
 *
 *  - `strtotime()` returns `false` on anything it cannot parse, `date()`
 *    casts `false` to the integer 0, and 0 is the Unix epoch. Every
 *    unreadable cell therefore became 1 JANUARY 1970 -- a real-looking
 *    value, in a date column, that no human ever entered. It threw no
 *    exception, so the import's own error handling never knew.
 *
 *  - `strtotime()` resolves a slashed date month-first (US), so the
 *    id-ID Excel default `15/01/2026` was unreadable (there is no month
 *    15) and `01/02/2026` -- 1 February here -- silently became 2 January.
 *
 * DAY COMES FIRST. For every separator-based format (`/`, `-`, `.`) the
 * leading number is the DAY, because IOMS is an Indonesian product and
 * that is what its customers' spreadsheets contain. The single exception
 * is a value that opens with a four-digit year (`2026-01-15`), which is
 * unambiguous, is what IOMS's own template prints, and is what the
 * database stores.
 *
 * STRICT, NOT FORGIVING. `DateTimeImmutable::createFromFormat()` happily
 * rolls 31 February over into 3 March. Every candidate parse is therefore
 * reformatted and compared back against its own components, so an
 * impossible date is refused rather than quietly moved.
 */
final class SpreadsheetDate
{
    /**
     * Formats are tried in this order. Four-digit-year-first comes first
     * so an ISO value is never mistaken for a day-first one; everything
     * after it is day-first by design.
     *
     * @var list<string>
     */
    private const FORMATS = [
        // Unambiguous: the year leads.
        'Y-m-d', 'Y/m/d', 'Y.m.d',
        // Day first, four-digit year.
        'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y', 'j.n.Y',
        // Day first, two-digit year ("15/01/26").
        'd/m/y', 'd-m-y', 'd.m.y', 'j/n/y', 'j-n-y', 'j.n.y',
        // Textual month, both orders ("15 Jan 2026", "Jan 15 2026").
        'd M Y', 'j M Y', 'd F Y', 'j F Y', 'M j Y', 'F j Y',
    ];

    /**
     * Indonesian month names, mapped to the English ones PHP's date
     * parser knows. Abbreviations follow what Indonesian spreadsheets
     * actually write, including "Agt" and "Okt".
     *
     * Longest-first on replacement, so "Januari" is never consumed as
     * "Jan" with a stray "uari" left behind.
     *
     * @var array<string, string>
     */
    private const INDONESIAN_MONTHS = [
        'januari' => 'January', 'februari' => 'February', 'pebruari' => 'February',
        'maret' => 'March', 'april' => 'April', 'mei' => 'May', 'juni' => 'June',
        'juli' => 'July', 'agustus' => 'August', 'september' => 'September',
        'oktober' => 'October', 'november' => 'November', 'nopember' => 'November',
        'desember' => 'December',
        'jan' => 'January', 'feb' => 'February', 'peb' => 'February', 'mar' => 'March',
        'apr' => 'April', 'jun' => 'June', 'jul' => 'July', 'agt' => 'August',
        'ags' => 'August', 'agu' => 'August', 'sep' => 'September', 'sept' => 'September',
        'okt' => 'October', 'nov' => 'November', 'des' => 'December',
    ];

    /**
     * The cell's date as `Y-m-d`, or null when it carries no date this
     * code can read with certainty.
     */
    public static function parse(mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        if (is_float($raw) || is_int($raw)) {
            return self::fromExcelSerial((float) $raw);
        }

        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        /*
         * A serial written as text. Bounded deliberately: 1 is 1900-01-01
         * and 2958465 is 9999-12-31, so anything outside that is not a
         * date Excel could have produced -- most likely an employee number
         * that landed in the wrong column, which must not become a date.
         */
        if (is_numeric($value)) {
            return self::fromExcelSerial((float) $value);
        }

        // A date that arrived with a time on it ("15/01/2026 00:00:00").
        // The time is not part of a join date, so it is dropped before
        // the formats below are tried.
        $value = (string) preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp]\.?[Mm]\.?)?$/', '', $value);

        // "15 Januari 2026" -> "15 January 2026". Done before parsing
        // because PHP's parser knows only English month names.
        $normalised = self::translateMonthNames($value);

        foreach (self::FORMATS as $format) {
            $parsed = self::tryFormat($normalised, $format);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Strict single-format parse. `createFromFormat()` alone is not
     * enough: it rolls impossible dates over (31 February becomes
     * 3 March) and tolerates trailing junk. The parsed result is
     * therefore reformatted and compared against the input, so only an
     * exact round trip is accepted.
     */
    private static function tryFormat(string $value, string $format): ?string
    {
        // '!' resets every unspecified field to zero, so a format without
        // a time component cannot inherit the current hour and a
        // format without a year cannot inherit the current one.
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

        if ($date === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        // The round trip. A rolled-over date formats back to something
        // other than what was read, which is how 31/02 is caught.
        if ($date->format($format) !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * The lowest serial this code will accept: 1920-01-01.
     *
     * Excel itself goes down to serial 1 (1900-01-01), but a join date in
     * an employee register does not. The floor is what separates a date
     * from a number that merely looks like one -- a cell holding `2026`
     * (a year somebody typed on its own) is serial 2026, which is
     * 18 July 1905. Read as a serial it produces a confident, absurd
     * date; refused, it reads as what it is: not a date this code can
     * resolve. Nobody in a live workforce register started work before
     * 1920, so nothing real is lost by drawing the line here.
     */
    private const MIN_SERIAL = 7306;

    /** 9999-12-31, the highest date Excel can represent. */
    private const MAX_SERIAL = 2958465;

    /**
     * An Excel serial, bounded to the range a real join date can occupy.
     * Out-of-range values are not dates and are refused rather than
     * wrapped into one.
     */
    private static function fromExcelSerial(float $serial): ?string
    {
        if ($serial < self::MIN_SERIAL || $serial > self::MAX_SERIAL) {
            return null;
        }

        try {
            return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function translateMonthNames(string $value): string
    {
        $months = self::INDONESIAN_MONTHS;

        // Longest name first, so "januari" is matched before "jan".
        uksort($months, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($months as $indonesian => $english) {
            $replaced = preg_replace('/\b'.preg_quote($indonesian, '/').'\b/iu', $english, $value);

            if ($replaced !== null && $replaced !== $value) {
                return $replaced;
            }
        }

        return $value;
    }
}

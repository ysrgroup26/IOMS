<?php

namespace Tests\Unit;

use App\Support\SpreadsheetDate;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The date rules, stated once, at the level they are decided.
 *
 * This is a pure function over a cell value, so it is tested without a
 * database or an HTTP request -- `tests/Unit` existed as a declared suite
 * with nothing in it until this file.
 */
class SpreadsheetDateTest extends TestCase
{
    /** @return list<array{0: mixed, 1: string|null, 2: string}> */
    public static function cells(): array
    {
        return [
            // --- unambiguous: the year leads -----------------------------
            ['2026-01-15', '2026-01-15', 'ISO, what the IOMS template prints'],
            ['2026/01/15', '2026-01-15', 'ISO with slashes'],

            // --- day first ----------------------------------------------
            ['15/01/2026', '2026-01-15', 'the id-ID Excel default'],
            ['01/02/2026', '2026-02-01', '1 February, not 2 January'],
            ['15-01-2026', '2026-01-15', 'dashes'],
            ['15.01.2026', '2026-01-15', 'dots'],
            ['1/2/2026', '2026-02-01', 'single-digit day and month'],
            ['15/01/26', '2026-01-15', 'two-digit year'],

            // --- textual months -----------------------------------------
            ['15 Jan 2026', '2026-01-15', 'English abbreviation'],
            ['15 January 2026', '2026-01-15', 'English full'],
            ['15 Januari 2026', '2026-01-15', 'Indonesian full'],
            ['15 Des 2026', '2026-12-15', 'Indonesian abbreviation'],
            ['15 Agustus 2026', '2026-08-15', 'Indonesian August'],
            ['15 Agt 2026', '2026-08-15', 'the abbreviation spreadsheets actually write'],
            ['15 Okt 2026', '2026-10-15', 'Indonesian October'],
            ['15 Mei 2026', '2026-05-15', 'Indonesian May'],

            // --- a date that arrived with a time on it -------------------
            ['15/01/2026 00:00:00', '2026-01-15', 'time component dropped'],
            ['2026-01-15 08:30', '2026-01-15', 'ISO with a time'],

            // --- nothing at all ------------------------------------------
            [null, null, 'an empty cell'],
            ['', null, 'an empty string'],
            ['   ', null, 'whitespace only'],

            // --- unreadable: NULL, never the epoch -----------------------
            ['belum tahu', null, 'Indonesian for "not known yet"'],
            ['-', null, 'a placeholder dash'],
            ['n/a', null, 'a placeholder'],
            ['31/02/2026', null, '31 February must not roll into March'],
            ['32/01/2026', null, 'there is no 32nd'],
            ['15/13/2026', null, 'there is no 13th month'],
            ['2026-02-30', null, 'an impossible ISO date'],
            ['EMP-0001', null, 'an employee number in the wrong column'],
            ['15/01', null, 'no year at all'],
            ['2026', null, 'a bare year is not a date'],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_cell_is_read_or_refused(mixed $cell, ?string $expected, string $because): void
    {
        $this->assertSame($expected, SpreadsheetDate::parse($cell), $because);
    }

    public function test_an_excel_serial_is_read_as_its_date(): void
    {
        $this->assertSame('2026-01-15', SpreadsheetDate::parse(ExcelDate::stringToExcel('2026-01-15')));
    }

    public function test_a_serial_written_as_text_is_still_a_date(): void
    {
        $this->assertSame('2026-01-15', SpreadsheetDate::parse((string) ExcelDate::stringToExcel('2026-01-15')));
    }

    public function test_a_number_outside_excels_own_date_range_is_not_a_date(): void
    {
        $this->assertNull(SpreadsheetDate::parse(0), 'Serial 0 is Excel\'s own null date.');
        $this->assertNull(SpreadsheetDate::parse(-5));
        $this->assertNull(SpreadsheetDate::parse(99999999), 'Past 9999-12-31 is not a date.');
        $this->assertNull(SpreadsheetDate::parse(2026), 'A bare year read as a serial would be 1905.');
    }

    public function test_a_date_object_passes_straight_through(): void
    {
        $this->assertSame('2026-01-15', SpreadsheetDate::parse(new DateTimeImmutable('2026-01-15 13:45:00')));
    }

    /**
     * The guarantee the old expression broke. Whatever is handed to this
     * parser, it never answers with the Unix epoch unless the cell
     * genuinely says so.
     */
    public function test_no_unreadable_value_ever_produces_the_epoch(): void
    {
        foreach (['belum tahu', '', '-', 'n/a', 'EMP-1', '15/13/2026', 'xx/xx/xxxx', 'TBD', '0'] as $cell) {
            $this->assertNotSame('1970-01-01', SpreadsheetDate::parse($cell), "[{$cell}] must not become the epoch.");
        }
    }

    /**
     * The serial floor is 1920-01-01. A date written in full is read on
     * its own terms and is not subject to it -- only a bare NUMBER has to
     * clear the floor to be believed as a serial.
     */
    public function test_an_old_date_written_in_full_is_still_read(): void
    {
        $this->assertSame('1915-03-04', SpreadsheetDate::parse('04/03/1915'));
    }

    public function test_the_epoch_itself_is_still_readable_when_actually_written(): void
    {
        $this->assertSame('1970-01-01', SpreadsheetDate::parse('1970-01-01'));
    }
}

# ADR 050 — A date out of a spreadsheet is read day-first, or it is nothing

- **Status:** Accepted
- **Date:** 2026-10-11 (v2.108.0)
- **Supersedes:** nothing. This is the first time the rule has been written down.

## Context

`EmployeesImport` read a join date with one expression:

```php
$joinDate = is_numeric($raw)
    ? Date::excelToDateTimeObject($raw)->format('Y-m-d')
    : date('Y-m-d', strtotime((string) $raw));
```

It had no test of any kind — `join_date` appeared nowhere in `tests/`. Two things were wrong with
it, and both put **wrong data into an employee record rather than refusing it**.

### 1. An unreadable cell became 1 January 1970

`strtotime()` returns `false` for anything it cannot parse. `date()` casts `false` to the integer
`0`. `0` is the Unix epoch. So every unreadable cell — `belum tahu`, `-`, `n/a`, a stray employee
number in the wrong column — became a **join date of 1 January 1970**.

Nothing threw, so the per-row `catch` the importer gained in v2.105.0 never saw it. The row imported
and looked complete. A fabricated date is worse than an empty one: it reads as a fact, it is
nobody's real start date, and it feeds service-length and contract reporting.

This was reachable **through IOMS's own Excel export**, which writes an empty join date as `-`.
Exporting one operating unit and importing it into another — a real migration move — turned every
blank date into 1970.

### 2. A slashed date was read American-first

`strtotime()` resolves `01/02/2026` as 2 January. IOMS is an Indonesian product, its own template
prints `2026-01-15`, and an Excel installed in `id-ID` writes `15/01/2026` by default. So:

- `15/01/2026` was month 15, which does not exist → `false` → 1970 again.
- `01/02/2026`, which is **1 February** to every customer here, was silently stored as **2 January**.

The second is the dangerous half. It is a plausible date, in the right column, off by a month. There
is nothing for anyone to notice.

## Decision

All spreadsheet date reading goes through **one** documented parser, `App\Support\SpreadsheetDate`.

1. **Day comes first.** For every separator-based format (`/`, `-`, `.`) the leading number is the
   DAY. The single exception is a value that opens with a four-digit year (`2026-01-15`), which is
   unambiguous, is what the IOMS template prints, and is what the database stores.
2. **Unreadable means `null`.** Never the epoch, never a guess. The value is refused.
3. **An unreadable date does not cost the row.** A join date was never a critical field, so the row
   imports and the customer gets a **warning** naming the row and the offending value. Silently
   dropping the date would be its own kind of lie — hence `EmployeesImport::$warnings`, which is
   deliberately separate from `$skipped` so a successful import never looks like a partial one.
4. **Strict, not forgiving.** `createFromFormat()` rolls 31 February into 3 March. Every candidate
   parse is reformatted and compared back against its input, so an impossible date is refused.
5. **Indonesian month names are read** (`15 Agustus 2026`, `15 Agt 2026`, `15 Okt 2026`), because
   customers' own files contain them.
6. **A bare number must clear a floor of 1920-01-01 to be believed as an Excel serial.** A cell
   holding `2026` is serial 2026, which is 18 July 1905 — a confident, absurd date. Nobody in a
   live workforce register started work before 1920, so the floor costs nothing real and removes
   the year-typed-as-a-number trap.

## Consequences

- One rule, one place, stated in `tests/Unit/SpreadsheetDateTest.php` as a table of cells and
  expected answers. That file is also the first content of a `tests/Unit` suite that had been
  declared in `phpunit.xml` and empty since v2.37.0.
- `d/m/Y` is now **unambiguously** day-first. A customer who genuinely means `03/04/2026` as
  3 April gets 3 April; one who meant 4 March gets a wrong date. That trade is deliberate and
  correct for this product's market, and the template teaches the ISO form that has no ambiguity
  at all.
- Any future importer (Vendor, Contractor, PPE Master — all anticipated by `EmployeesImport`'s own
  doc comment) must use `SpreadsheetDate::parse()` rather than `strtotime()`. The expression this
  ADR removes is the kind that reads as obviously fine in review.
- The parser is pure, so it needs no database and no HTTP request to test, and the rules can be
  argued about in one 40-line table instead of through an import run.

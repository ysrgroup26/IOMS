<?php

namespace Tests\Feature;

use App\Services\ImportHeaderMatcher;
use Tests\TestCase;

/**
 * v2.102.0 -- SMART IMPORT, AND WHAT IT REFUSES TO DO.
 *
 * The official template is the golden path. This covers the other file:
 * the workforce list a customer already keeps, in their own words, usually
 * Indonesian, whose headings are not ours.
 *
 * Four stages, most certain first, each seeing only what the stage above
 * could not decide: the heading IS the label, the heading is a name we
 * have seen before, the heading is close enough to be a typo, or -- last
 * -- the column's own VALUES say what it is.
 *
 * The important assertions here are the negative ones. A wrong column
 * silently mapped is worse than an unmapped one: the first corrupts
 * records, the second only asks a question. So this pins that the matcher
 * leaves a heading unmatched rather than attaching it to the nearest
 * field, and that it never infers a field whose data has no distinctive
 * shape.
 *
 * Nothing here involves a model. Every rule is a lookup or a measurement,
 * which is what makes an import deterministic.
 */
class SmartImportHeaderMatchingTest extends TestCase
{
    private function matcher(): ImportHeaderMatcher
    {
        return app(ImportHeaderMatcher::class);
    }

    /** @param list<string> $headings */
    private function suggest(array $headings, array $sampleRows = []): array
    {
        return $this->matcher()->suggest($headings, $sampleRows, 'employees');
    }

    /* ================================================== exact */

    public function test_the_official_template_maps_every_field_exactly(): void
    {
        $headings = array_values(config('mapping_fields.employees.import'));

        $result = $this->suggest($headings);

        $this->assertSame([], $result['missing_fields'], 'The template we publish must map completely.');
        $this->assertSame([], $result['unmatched_headings']);

        foreach ($result['mapping'] as $field => $match) {
            $this->assertSame('exact', $match['matched_by'], "{$field} should match exactly.");
        }
    }

    public function test_capitalisation_spacing_and_punctuation_are_not_meaning(): void
    {
        $result = $this->suggest(['  EMPLOYEE_ID  ', 'full name', 'E-Mail']);

        $this->assertSame('  EMPLOYEE_ID  ', $result['mapping']['employee_id']['heading']);
        $this->assertSame('full name', $result['mapping']['full_name']['heading']);
        $this->assertSame('E-Mail', $result['mapping']['email']['heading']);
    }

    /* ================================================== alias */

    public function test_an_indonesian_workforce_list_is_recognised(): void
    {
        $result = $this->suggest([
            'NIK', 'Nama Lengkap', 'Departemen', 'Jabatan', 'Tanggal Masuk', 'No HP', 'Alamat',
        ]);

        $expected = [
            'employee_id' => 'NIK',
            'full_name' => 'Nama Lengkap',
            'department' => 'Departemen',
            'position' => 'Jabatan',
            'join_date' => 'Tanggal Masuk',
            'phone' => 'No HP',
            'address' => 'Alamat',
        ];

        foreach ($expected as $field => $heading) {
            $this->assertArrayHasKey($field, $result['mapping'], "{$field} was not recognised.");
            $this->assertSame($heading, $result['mapping'][$field]['heading']);
            $this->assertSame('alias', $result['mapping'][$field]['matched_by']);
        }

        $this->assertSame([], $result['unmatched_headings']);
    }

    /* ================================================== fuzzy */

    public function test_a_typed_heading_with_a_typo_still_matches(): void
    {
        $result = $this->suggest(['Depatment', 'Nama Lengkep']);

        $this->assertSame('Depatment', $result['mapping']['department']['heading']);
        $this->assertSame('fuzzy', $result['mapping']['department']['matched_by']);

        $this->assertSame('Nama Lengkep', $result['mapping']['full_name']['heading']);
        $this->assertSame('fuzzy', $result['mapping']['full_name']['matched_by']);
    }

    /**
     * Tolerance is relative to length, so a short heading is not matched on
     * a coincidence. "Email" and "Phone" are both five letters apart from
     * nothing in particular and must not be confused.
     */
    public function test_a_short_unrelated_heading_is_not_fuzzily_claimed(): void
    {
        $result = $this->suggest(['Email']);

        $this->assertArrayHasKey('email', $result['mapping']);
        $this->assertArrayNotHasKey('phone', $result['mapping']);
    }

    /* ================================================= sample */

    /**
     * The heading says nothing we recognise, so the column's values are the
     * only evidence left.
     */
    public function test_an_unfamiliar_heading_is_read_from_its_values(): void
    {
        $result = $this->suggest(
            ['Kolom A', 'Kolom B', 'Kolom C'],
            [
                ['Kolom A' => 'budi@example.com', 'Kolom B' => '081234567890', 'Kolom C' => '2026-01-15'],
                ['Kolom A' => 'siti@example.com', 'Kolom B' => '081298765432', 'Kolom C' => '2026-02-01'],
                ['Kolom A' => 'agus@example.com', 'Kolom B' => '081211112222', 'Kolom C' => '2026-03-20'],
            ]
        );

        $this->assertSame('Kolom A', $result['mapping']['email']['heading']);
        $this->assertSame('sample', $result['mapping']['email']['matched_by']);

        $this->assertSame('Kolom B', $result['mapping']['phone']['heading']);
        $this->assertSame('Kolom C', $result['mapping']['join_date']['heading']);
    }

    /**
     * THE REFUSAL THAT MATTERS. "A short piece of text" describes most
     * columns in a workforce file, so there is no sample rule for a name or
     * a department. Guessing between them would invent an answer, and a
     * wrong column mapped silently corrupts records.
     */
    public function test_a_column_of_plain_text_is_never_guessed_into_a_name_field(): void
    {
        $result = $this->suggest(
            ['Kolom X'],
            [
                ['Kolom X' => 'Sesuatu'],
                ['Kolom X' => 'Lainnya'],
                ['Kolom X' => 'Entah'],
            ]
        );

        $this->assertSame([], $result['mapping'], 'Nothing distinguishes this column, so nothing may be claimed.');
        $this->assertSame(['Kolom X'], $result['unmatched_headings']);
        $this->assertContains('full_name', $result['missing_fields']);
    }

    /** An employee id that happens to be numeric is not a date. */
    public function test_a_numeric_identifier_column_is_not_read_as_a_date(): void
    {
        $result = $this->suggest(
            ['Kode'],
            [['Kode' => '45000'], ['Kode' => '45001'], ['Kode' => '45002']]
        );

        $this->assertArrayNotHasKey('join_date', $result['mapping']);
    }

    /* ============================================== structure */

    /** One heading feeds one field; a stronger match is never overruled. */
    public function test_a_heading_is_claimed_once_and_by_the_strongest_stage(): void
    {
        $result = $this->suggest(['Full Name', 'Nama Lengkap']);

        $this->assertSame('Full Name', $result['mapping']['full_name']['heading']);
        $this->assertSame('exact', $result['mapping']['full_name']['matched_by']);

        // The alias is left over rather than attached to some other field.
        $this->assertSame(['Nama Lengkap'], $result['unmatched_headings']);
    }

    public function test_it_reports_what_it_could_not_place(): void
    {
        $result = $this->suggest(['Nama Lengkap', 'Golongan Darah']);

        $this->assertArrayHasKey('full_name', $result['mapping']);
        $this->assertSame(['Golongan Darah'], $result['unmatched_headings']);
        $this->assertContains('employee_id', $result['missing_fields']);
    }

    /** Every alias points at a field the module's import actually has. */
    public function test_every_configured_alias_names_a_real_field(): void
    {
        $fields = array_keys(config('mapping_fields.employees.import'));

        foreach (array_keys(config('import_aliases.employees')) as $field) {
            $this->assertContains(
                $field,
                $fields,
                "Alias list names \"{$field}\", which is not an employees import field."
            );
        }
    }

    public function test_no_alias_is_claimed_by_two_fields(): void
    {
        $seen = [];

        foreach (config('import_aliases.employees') as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = ImportHeaderMatcher::normalise($alias);

                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    sprintf('"%s" is claimed by both %s and %s.', $alias, $seen[$key] ?? '?', $field)
                );

                $seen[$key] = $field;
            }
        }
    }
}

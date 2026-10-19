<?php

namespace App\Services\Documents\Import;

use App\Services\MasterDataDetector;

/**
 * v2.126.0 -- WHAT A COLUMN IN SOMEBODY ELSE'S WORKBOOK MIGHT MEAN.
 *
 * A customer's PPE register is not the IOMS template and never will be. It
 * says "Sepatu Safety" where we say "Safety Shoes", "Baju Kerja" where we
 * say "Work Uniform", "Ukuran" where we say size, "No. Induk" where we say
 * Employee ID -- and the owner's instruction is explicit that they must
 * not be asked to rewrite the file to use our words.
 *
 * THIS CLASS IS A DICTIONARY, NOT A DECISION. It turns a heading into a
 * CANONICAL CONCEPT and nothing more. Whether that concept exists as a PPE
 * type is answered by the company's own catalogue, in
 * `PpeImportInterpreter`, and a concept the catalogue has no row for is
 * REPORTED rather than created -- the rule ADR 059 states as "an import
 * matches master data and never invents it". There is no path here by
 * which a synonym becomes a PPE type.
 *
 * AND IT NEVER DECIDES ALONE. Every match carries a confidence, and
 * anything short of an exact catalogue hit is offered to the person for
 * confirmation. The owner's brief says the example lists are "bukan daftar
 * lengkap atau izin untuk melakukan pemetaan tanpa validasi", and that is
 * the design: a dictionary that proposes, a human who disposes.
 *
 * WORK UNIFORM IS NOT COVERALL. They are separate entries with no shared
 * synonym, deliberately and explicitly. A coverall is worn over work
 * clothing and takes the wear; a uniform is replaced less often. Folding
 * "Baju Kerja" into "Coverall" because both satisfy the same completeness
 * group would issue the wrong garment and record it as the right one.
 */
class PpeColumnVocabulary
{
    /**
     * Source term => the catalogue name it proposes.
     *
     * Lower-case, punctuation-free, matched through
     * `MasterDataDetector::comparisonKey()` so spacing and case never
     * matter. Not exhaustive and not meant to be: an unknown term is a
     * question for the person, which is a better outcome than a longer
     * list that is confidently wrong.
     */
    public const PPE_SYNONYMS = [
        // Safety Shoes
        'safety shoes' => 'Safety Shoes',
        'safety shoe' => 'Safety Shoes',
        'sepatu safety' => 'Safety Shoes',
        'sepatu keselamatan' => 'Safety Shoes',
        'sepatu kerja' => 'Safety Shoes',
        'safety boots' => 'Safety Shoes',
        'sepatu boot' => 'Safety Shoes',
        // The bare noun, so "Ukuran Sepatu" resolves once its qualifier
        // is stripped. Unambiguous: nothing else in the catalogue is a shoe.
        'sepatu' => 'Safety Shoes',
        'shoes' => 'Safety Shoes',

        // Safety Helmet
        'safety helmet' => 'Safety Helmet',
        'helm safety' => 'Safety Helmet',
        'helm keselamatan' => 'Safety Helmet',
        'helm kerja' => 'Safety Helmet',
        'helmet' => 'Safety Helmet',
        'helm' => 'Safety Helmet',
        'topi' => 'Safety Helmet',

        // Work Uniform -- NOT a coverall.
        'work uniform' => 'Work Uniform',
        'baju kerja' => 'Work Uniform',
        'seragam kerja' => 'Work Uniform',
        'seragam' => 'Work Uniform',
        'uniform' => 'Work Uniform',
        'pakaian kerja' => 'Work Uniform',

        // Coverall -- NOT a uniform.
        'coverall' => 'Coverall',
        'coverall suit' => 'Coverall',
        'wearpack' => 'Coverall',
        'wear pack' => 'Coverall',
        'baju coverall' => 'Coverall',

        // Safety Glasses
        'safety glasses' => 'Safety Glasses',
        'safety glass' => 'Safety Glasses',
        'kacamata safety' => 'Safety Glasses',
        'kacamata keselamatan' => 'Safety Glasses',
        'kacamata pelindung' => 'Safety Glasses',
        'goggles' => 'Safety Glasses',

        // Harness
        'harness' => 'Harness',
        'full body harness' => 'Harness',
        'body harness' => 'Harness',
        'sabuk pengaman' => 'Harness',

        // Headlamp
        'headlamp' => 'Headlamp',
        'head lamp' => 'Headlamp',
        'senter kepala' => 'Headlamp',
        'lampu kepala' => 'Headlamp',
    ];

    /** Headings that name WHO the row is about. */
    public const IDENTITY_SYNONYMS = [
        'employee_id' => [
            'employee id', 'employeeid', 'emp id', 'id karyawan', 'no induk', 'no induk karyawan',
            'nomor induk', 'nomor induk karyawan', 'nik karyawan', 'nip', 'badge', 'badge no',
            'no badge', 'npk', 'kode karyawan',
        ],
        'employee_name' => [
            'employee name', 'name', 'nama', 'nama karyawan', 'nama pekerja', 'nama lengkap',
            'full name', 'pekerja', 'karyawan',
        ],
        'nik' => [
            'nik', 'nik ktp', 'ktp', 'no ktp', 'nomor ktp', 'national id',
        ],
        'department' => [
            'department', 'departemen', 'dept', 'bagian', 'divisi', 'division', 'unit kerja', 'seksi',
        ],
    ];

    /**
     * Words that mark a column as a SIZE rather than a date.
     *
     * Deliberately NOT including "no" or "nomor": they are the first word
     * of "No. Induk" far more often than they are a shoe size, and a size
     * word that swallows the Employee ID column is worse than a size
     * column the person has to assign.
     */
    public const SIZE_TERMS = [
        'size', 'ukuran', 'uk', 'sz',
    ];

    /**
     * Terms that name a GARMENT without saying which one.
     *
     * "Baju" is a work uniform in one company's register and a coverall in
     * another's, and the owner's instruction is explicit that the two must
     * not be conflated. So the bare word proposes NOTHING and the person
     * is asked -- which is the correct answer to a genuinely ambiguous
     * heading, and the one case where guessing would quietly issue the
     * wrong garment and record it as the right one.
     */
    public const AMBIGUOUS_TERMS = [
        'baju', 'pakaian', 'wear', 'garment', 'apd', 'ppe',
    ];

    /** Words that mark a column as a DATE. */
    public const DATE_TERMS = [
        'tanggal', 'tgl', 'date', 'issued', 'pemenuhan', 'penerimaan', 'terima', 'diterima',
        'pemberian', 'serah', 'penyerahan', 'realisasi', 'expired', 'kadaluarsa',
    ];

    /**
     * The comparison key, with PUNCTUATION removed.
     *
     * `MasterDataDetector::comparisonKey()` lower-cases and collapses
     * whitespace but keeps punctuation, so "No. Induk" normalises to
     * "no. induk" and missed the dictionary entirely -- a real company
     * register's Employee ID column read as "not imported", seen on the
     * first screening of one. The shared helper is deliberately NOT
     * changed: a dozen other callers compare master-data names through it
     * and a quieter normalisation there would change what they match.
     * Punctuation only matters for the abbreviations headings use
     * ("No.", "Tgl.", "Uk."), which is this class's problem.
     */
    private static function key(string $heading): string
    {
        $key = MasterDataDetector::comparisonKey($heading);
        $key = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $key) ?? $key;

        return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
    }

    /**
     * The canonical PPE name a heading proposes, or null.
     *
     * Matched on the WHOLE normalised heading first, then on the heading
     * with its size/date words stripped -- "Ukuran Sepatu Safety" and
     * "Tanggal Sepatu Safety" both propose Safety Shoes, which is the
     * entire reason a wide register works at all.
     */
    public static function ppeConceptFor(string $heading): ?string
    {
        $key = self::key($heading);

        if (isset(self::PPE_SYNONYMS[$key])) {
            return self::PPE_SYNONYMS[$key];
        }

        $stripped = self::withoutQualifiers($key);

        if ($stripped !== '' && isset(self::PPE_SYNONYMS[$stripped])) {
            return self::PPE_SYNONYMS[$stripped];
        }

        // A heading that reduces to a bare garment word names no garment.
        // Returning null sends it to the person, which is the point.
        if (in_array($stripped, self::AMBIGUOUS_TERMS, true) || in_array($key, self::AMBIGUOUS_TERMS, true)) {
            return null;
        }

        /*
         * Last resort: the heading CONTAINS a known term. Deliberately
         * last and deliberately longest-first, so "baju kerja" is not
         * decided by the word "baju" appearing inside "baju coverall".
         * A containment hit is never treated as certain -- the caller
         * marks it for review.
         */
        $terms = array_keys(self::PPE_SYNONYMS);
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term) {
            if (str_contains($key, $term)) {
                return self::PPE_SYNONYMS[$term];
            }
        }

        return null;
    }

    /** Which identity field a heading names, or null. */
    public static function identityFieldFor(string $heading): ?string
    {
        $key = self::key($heading);

        foreach (self::IDENTITY_SYNONYMS as $field => $terms) {
            if (in_array($key, $terms, true)) {
                return $field;
            }
        }

        // `employee_id` before `nik` on a containment hit: a column called
        // "NIK Karyawan" in an HR register is usually the badge number,
        // and the two are distinguished by exact match above when the file
        // carries both.
        foreach (self::IDENTITY_SYNONYMS as $field => $terms) {
            foreach ($terms as $term) {
                if (mb_strlen($term) >= 4 && str_contains($key, $term)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** Does this heading say it holds a size? */
    public static function mentionsSize(string $heading): bool
    {
        return self::mentionsAny($heading, self::SIZE_TERMS);
    }

    /** Does this heading say it holds a date? */
    public static function mentionsDate(string $heading): bool
    {
        return self::mentionsAny($heading, self::DATE_TERMS);
    }

    private static function mentionsAny(string $heading, array $terms): bool
    {
        $words = preg_split('/\s+/', self::key($heading), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terms as $term) {
            // Whole words only. "no" is a size word and also the first
            // syllable of nothing useful, so a substring test here would
            // classify half the file as sizes.
            if (in_array($term, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /** The heading with its size/date qualifiers removed. */
    private static function withoutQualifiers(string $normalisedKey): string
    {
        $words = preg_split('/\s+/', $normalisedKey, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $drop = array_merge(self::SIZE_TERMS, self::DATE_TERMS);

        return trim(implode(' ', array_filter($words, fn ($w) => ! in_array($w, $drop, true))));
    }
}

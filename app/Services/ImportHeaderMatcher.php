<?php

namespace App\Services;

/**
 * v2.102.0 -- SMART IMPORT: MATCHING A CUSTOMER'S OWN COLUMN HEADINGS.
 *
 * The golden path is the official template, and it stays the golden path.
 * This is for the file a customer already has: their own workforce list,
 * in their own words, usually Indonesian, whose headings do not match
 * ours. Retyping it into our column names is exactly the work that does
 * not get done, so the import stalls before it starts.
 *
 * FOUR STAGES, MOST CERTAIN FIRST. Each one only sees the headings the
 * stage above it could not decide, so a confident answer is never
 * overruled by a speculative one:
 *
 *   exact   the heading already IS the field's label ("Full Name")
 *   alias   a name we have seen before ("Nama Lengkap"), from
 *           config/import_aliases.php
 *   fuzzy   close enough to a label or alias to be a typo or a variant
 *           ("Nama Lengkep", "Depatment"), by Levenshtein distance scaled
 *           to the length of the word, so a short heading is not matched
 *           on a coincidence
 *   sample  nothing in the heading decided it, so the column's OWN VALUES
 *           are read: an address shaped like an email is an email, a
 *           column of dates is a date
 *
 * DELIBERATELY NOT AN LLM. Every rule here is a lookup or a measurement:
 * auditable, instant, free, offline, and identical on every run. Adding a
 * model would make this sound clever and make a customer's workforce
 * import non-deterministic, which is the opposite of what an import needs.
 *
 * IT SUGGESTS; IT DOES NOT DECIDE. Every result carries how it was reached
 * and a confidence, the UI shows the mapping before anything is written,
 * and a heading it cannot place is reported as unmatched rather than
 * attached to the nearest field. A wrong column silently mapped is worse
 * than an unmapped one, because the first corrupts records and the second
 * only asks a question.
 */
class ImportHeaderMatcher
{
    /**
     * Maximum edit distance, as a fraction of the shorter string's length.
     * 0.25 lets "Depatment" reach "Department" (1/9) and "Nama Lengkep"
     * reach "Nama Lengkap" (1/11), while keeping "Email" away from
     * "Phone". Relative rather than absolute because a fixed distance of 2
     * is forgiving on a long heading and reckless on a short one.
     */
    private const FUZZY_TOLERANCE = 0.25;

    /** A sample rule must describe most of the column, not one lucky cell. */
    private const SAMPLE_CONFIDENCE = 0.7;

    /**
     * @param  list<string>  $headings  the file's heading row, verbatim
     * @param  list<array<string, mixed>>  $sampleRows  a handful of data rows, keyed by heading
     * @return array{
     *     mapping: array<string, array{heading: string, matched_by: string, confidence: float}>,
     *     unmatched_headings: list<string>,
     *     missing_fields: list<string>
     * }
     */
    public function suggest(array $headings, array $sampleRows, string $moduleKey): array
    {
        $fields = config("mapping_fields.{$moduleKey}.import", []);
        $aliases = config("import_aliases.{$moduleKey}", []);

        $remaining = [];
        foreach ($headings as $heading) {
            if (self::normalise($heading) !== '') {
                $remaining[] = $heading;
            }
        }

        $mapping = [];

        foreach (['exact', 'alias', 'fuzzy', 'sample'] as $stage) {
            foreach ($fields as $field => $label) {
                if (isset($mapping[$field])) {
                    continue;
                }

                $match = match ($stage) {
                    'exact' => $this->matchExact($remaining, $label),
                    'alias' => $this->matchAlias($remaining, $aliases[$field] ?? []),
                    'fuzzy' => $this->matchFuzzy($remaining, [$label, ...($aliases[$field] ?? [])]),
                    'sample' => $this->matchSample($remaining, $sampleRows, $field),
                };

                if ($match === null) {
                    continue;
                }

                $mapping[$field] = [
                    'heading' => $match['heading'],
                    'matched_by' => $stage,
                    'confidence' => $match['confidence'],
                ];

                // A heading belongs to one field. Removing it here is what
                // stops a later, weaker stage from claiming a column an
                // earlier one already explained.
                $remaining = array_values(array_filter(
                    $remaining,
                    fn (string $heading) => $heading !== $match['heading']
                ));
            }
        }

        return [
            'mapping' => $mapping,
            'unmatched_headings' => array_values($remaining),
            'missing_fields' => array_values(array_diff(array_keys($fields), array_keys($mapping))),
        ];
    }

    /**
     * Case, spacing and punctuation are not meaning. "No. HP", "no_hp" and
     * "NO HP" are one heading.
     */
    public static function normalise(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /** @param list<string> $headings */
    private function matchExact(array $headings, string $label): ?array
    {
        foreach ($headings as $heading) {
            if (self::normalise($heading) === self::normalise($label)) {
                return ['heading' => $heading, 'confidence' => 1.0];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<string>  $aliases
     */
    private function matchAlias(array $headings, array $aliases): ?array
    {
        $normalised = array_map([self::class, 'normalise'], $aliases);

        foreach ($headings as $heading) {
            if (in_array(self::normalise($heading), $normalised, true)) {
                return ['heading' => $heading, 'confidence' => 0.95];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<string>  $candidates
     */
    private function matchFuzzy(array $headings, array $candidates): ?array
    {
        $best = null;

        foreach ($headings as $heading) {
            $normalisedHeading = self::normalise($heading);

            if ($normalisedHeading === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $normalisedCandidate = self::normalise($candidate);

                if ($normalisedCandidate === '') {
                    continue;
                }

                $distance = levenshtein($normalisedHeading, $normalisedCandidate);
                $shortest = min(mb_strlen($normalisedHeading), mb_strlen($normalisedCandidate));

                if ($shortest === 0 || $distance > $shortest * self::FUZZY_TOLERANCE) {
                    continue;
                }

                $confidence = 0.9 - ($distance / max($shortest, 1)) * 0.5;

                if ($best === null || $confidence > $best['confidence']) {
                    $best = ['heading' => $heading, 'confidence' => round($confidence, 2)];
                }
            }
        }

        return $best;
    }

    /**
     * The last resort, and the only stage that reads DATA rather than a
     * heading. Used when a column is called something we have never seen
     * ("Kontak 1"), which is precisely when the values are the only
     * evidence left.
     *
     * Only fields with an unmistakable shape are inferred. There is no
     * rule for `full_name` or `department`, because "a short piece of
     * text" describes most columns in the file and guessing between them
     * would be inventing an answer.
     *
     * @param  list<string>  $headings
     * @param  list<array<string, mixed>>  $sampleRows
     */
    private function matchSample(array $headings, array $sampleRows, string $field): ?array
    {
        $test = match ($field) {
            'email' => fn (string $value) => (bool) filter_var($value, FILTER_VALIDATE_EMAIL),
            'phone' => fn (string $value) => (bool) preg_match('/^[+()\d][\d\s().+-]{6,}$/', $value),
            'join_date' => fn (string $value) => $this->looksLikeDate($value),
            default => null,
        };

        if ($test === null) {
            return null;
        }

        foreach ($headings as $heading) {
            $values = [];

            foreach ($sampleRows as $row) {
                $value = trim((string) ($row[$heading] ?? ''));

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            if ($values === []) {
                continue;
            }

            $matches = count(array_filter($values, $test));
            $ratio = $matches / count($values);

            if ($ratio >= self::SAMPLE_CONFIDENCE) {
                return ['heading' => $heading, 'confidence' => round($ratio * 0.8, 2)];
            }
        }

        return null;
    }

    /**
     * Excel hands a date cell over as a serial number, and a text cell as
     * whatever was typed. Both count, but a bare integer does not: an
     * employee id of 45000 is a number, not a date, so only serials inside
     * a plausible range are accepted and only alongside a real separator
     * elsewhere in the column.
     */
    private function looksLikeDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $value)) {
            return true;
        }

        if (preg_match('#^\d{1,2}[/-]\d{1,2}[/-]\d{2,4}$#', $value)) {
            return true;
        }

        return (bool) preg_match('/^\d{1,2}\s+\p{L}{3,}\s+\d{4}$/u', $value);
    }
}

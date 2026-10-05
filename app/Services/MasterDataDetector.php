<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Position;

/**
 * Smart Master Data Detection (v1.6.10). Deliberately its own service,
 * not baked into `EmployeesImport` -- the whole point of "future modules
 * should reuse Smart Master Data Detection" is that Department Import,
 * Project Import, PPE Master Import, Vendor Import, and Contractor
 * Import will all need the exact same shape of "which of these names
 * already exist, which are genuinely new, and which are probably a typo
 * of something that already exists" check, just against different
 * master-data tables.
 */
class MasterDataDetector
{
    /**
     * Names within this Levenshtein distance of an existing name are
     * flagged as a likely typo rather than silently treated as new --
     * e.g. "Warehose" (distance 1 from "Warehouse") gets suggested, not
     * auto-created as a distinct department. Distance alone, not a
     * percentage, since names in these tables tend to be very similar
     * lengths where an absolute cutoff behaves more predictably than a
     * relative one.
     */
    private const TYPO_DISTANCE_THRESHOLD = 2;

    /**
     * v2.101.0 -- THE ONE DEFINITION OF "THE SAME NAME".
     *
     * A spreadsheet column is exactly where " HSE " comes from, and three
     * places had to agree about whether that is the HSE department:
     * this detector, `EmployeesImport`'s lookup, and the controller that
     * creates whatever the detector called new. They did not. The detector
     * lowercased without trimming, so " HSE " was NEW; the controller then
     * created a second department whose name begins with a space.
     *
     * `normalise()` is what gets STORED and shown -- trimmed, with runs of
     * inner whitespace collapsed, so "HSE  Officer" and "HSE Officer" are
     * one job title. `comparisonKey()` is what gets COMPARED, and is
     * additionally case-folded.
     *
     * Deliberately not a database collation: `where('name', $name)` is
     * case-insensitive under MySQL's default collation and case-SENSITIVE
     * under the SQLite the suite runs on, so the old behaviour was a
     * property of the connection rather than of the code. Comparing on a
     * key computed in PHP is the same answer everywhere.
     */
    public static function normalise(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    }

    public static function comparisonKey(?string $name): string
    {
        return mb_strtolower(self::normalise($name));
    }

    /**
     * @param  array<string>  $names  Distinct department names encountered while scanning the file.
     * @return array{existing: array<string>, new: array<string>, suggestions: array<string,string>}
     */
    public function detectDepartments(array $names, int $companyId): array
    {
        $existingNames = Department::where('company_id', $companyId)->pluck('name')->all();

        return $this->classify($names, $existingNames);
    }

    /**
     * Positions are scoped by company only here (not department) --
     * matching how Employee Import itself resolves a position: by name
     * within the company, with department being a separate, independent
     * optional match. A position name existing under a different
     * department in the same company still counts as "existing," not
     * "new," since the concept (the job title) already exists there.
     *
     * @param  array<string>  $names
     * @return array{existing: array<string>, new: array<string>, suggestions: array<string,string>}
     */
    public function detectPositions(array $names, int $companyId): array
    {
        $existingNames = Position::where('company_id', $companyId)->pluck('name')->all();

        return $this->classify($names, $existingNames);
    }

    /**
     * @param  array<string>  $names
     * @param  array<string>  $existingNames
     * @return array{existing: array<string>, new: array<string>, suggestions: array<string,string>}
     */
    private function classify(array $names, array $existingNames): array
    {
        $existing = [];
        $new = [];
        $suggestions = [];

        // v2.101.0: compared on the normalised key, so "HSE", "hse",
        // "Hse" and " HSE " are one department rather than four, and
        // de-duplicated on that same key so a file that spells one name
        // three ways offers it once.
        $existingKeys = array_map([self::class, 'comparisonKey'], $existingNames);

        $seen = [];

        foreach ($names as $rawName) {
            $name = self::normalise($rawName);
            $key = self::comparisonKey($rawName);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (in_array($key, $existingKeys, true)) {
                $existing[] = $name;

                continue;
            }

            $suggestion = $this->findSimilar($name, $existingNames);

            if ($suggestion) {
                $suggestions[$name] = $suggestion;

                continue;
            }

            $new[] = $name;
        }

        return ['existing' => $existing, 'new' => $new, 'suggestions' => $suggestions];
    }

    /**
     * Simple Levenshtein-based similarity check. PHP's built-in
     * levenshtein() is used directly rather than a custom
     * implementation -- it's already the standard, well-tested tool for
     * exactly this, and reimplementing it would be duplicated logic for
     * no benefit.
     */
    private function findSimilar(string $name, array $existingNames): ?string
    {
        foreach ($existingNames as $existing) {
            if (levenshtein(self::comparisonKey($name), self::comparisonKey($existing)) <= self::TYPO_DISTANCE_THRESHOLD) {
                return $existing;
            }
        }

        return null;
    }
}

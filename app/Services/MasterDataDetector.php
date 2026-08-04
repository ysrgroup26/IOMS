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

        // Case-insensitive exact match first (an Excel row typed
        // "hse" should count as the existing "HSE", not a new department).
        $existingLower = array_map('mb_strtolower', $existingNames);

        foreach (array_unique(array_filter($names)) as $name) {
            $nameLower = mb_strtolower($name);

            if (in_array($nameLower, $existingLower, true)) {
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
            if (levenshtein(mb_strtolower($name), mb_strtolower($existing)) <= self::TYPO_DISTANCE_THRESHOLD) {
                return $existing;
            }
        }

        return null;
    }
}

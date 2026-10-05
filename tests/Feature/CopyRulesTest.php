<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * v2.100.0 -- TWO TYPOGRAPHIC RULES, PINNED THE SAME WAY THE LANGUAGE
 * HIERARCHY IS.
 *
 * NO EM DASH IN USER-FACING TEXT. It was being used for two different
 * jobs: as a "no value" placeholder in table cells, and as a clause break
 * in prose. Both are now written without it -- a hyphen for the
 * placeholder, and a comma, colon or full stop for the prose, chosen per
 * sentence rather than swapped mechanically.
 *
 * NO STORED IDENTIFIER IN USER-FACING TEXT. `under_maintenance`,
 * `in_progress` and `super_admin` are column values, not words. StatusBadge
 * has always turned them into words; the activity feeds and the asset
 * history did not, and printed the underscores. `humanizeToken` is now the
 * single definition of what readable means for them.
 *
 * Scanned from source, like `LanguageHierarchyTest`, because the rule
 * lives in the markup and a comment asking the next person to remember is
 * exactly what failed before.
 */
class CopyRulesTest extends TestCase
{
    /** @return list<string> */
    private function sourceFiles(string $directory, array $extensions): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * Strips the comment forms each language uses, so the rule is enforced
     * on what a customer reads and not on what a developer wrote to the
     * next developer. The codebase's own doc comments use em dashes
     * heavily and deliberately.
     */
    private function withoutComments(string $source, bool $isJsx): string
    {
        // /* ... */ and {/* ... */}
        $source = preg_replace('#\{?/\*.*?\*/\}?#s', '', $source) ?? $source;

        // Line comments: // for JS, // and # for PHP.
        $lines = preg_split('/\R/', $source) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            if (! $isJsx && str_starts_with($trimmed, '#')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    public function test_no_user_facing_text_uses_an_em_dash(): void
    {
        $offenders = [];

        $targets = [
            [resource_path('js'), ['jsx'], true],
            [resource_path('views'), ['php'], false],
        ];

        foreach ($targets as [$directory, $extensions, $isJsx]) {
            foreach ($this->sourceFiles($directory, $extensions) as $path) {
                $source = file_get_contents($path);

                if (! str_contains($source, '—')) {
                    continue;
                }

                // Blade comments too, which the generic stripper misses.
                $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

                if (str_contains($this->withoutComments($source, $isJsx), '—')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An em dash reached user-facing text. Use a hyphen for a 'no value' placeholder, ".
            "or a comma, colon or full stop in prose:\n".implode("\n", $offenders)
        );
    }

    /**
     * The activity feeds and the asset history render stored identifiers,
     * so they must put them through the shared helper rather than printing
     * the column value.
     */
    public function test_activity_feeds_humanise_the_stored_action(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles(resource_path('js'), ['jsx']) as $path) {
            $source = $this->withoutComments(file_get_contents($path), true);

            if (preg_match('/<p>\{a\.action\}<\/p>/', $source)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ActivityLog.action is a stored identifier and can be `in_progress` or ".
            "`under_review`. Render it with humanizeToken():\n".implode("\n", $offenders)
        );
    }

    /** One definition of "readable", shared rather than re-implemented. */
    public function test_the_humanise_helper_is_shared_not_duplicated(): void
    {
        $this->assertFileExists(resource_path('js/lib/humanizeToken.js'));

        $badge = file_get_contents(resource_path('js/Components/shared/StatusBadge.jsx'));

        $this->assertStringContainsString('humanizeToken', $badge);
        $this->assertStringNotContainsString(
            'function humanize(',
            $badge,
            'StatusBadge must use the shared helper rather than keep its own copy.'
        );
    }
}

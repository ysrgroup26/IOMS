<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * v2.101.0 -- A MENU ITEM AND THE PAGE IT OPENS MUST BE THE SAME THING.
 *
 * Admin Space deep-links six Settings tabs as first-class destinations.
 * That is deliberate -- same route, same controller, same authorization,
 * collected where an administrator looks for them -- and it means the same
 * destination is named in two files that nothing kept in agreement.
 *
 * Two had drifted:
 *
 *   sidebar "Module Management"  ->  tab "Module Visibility"
 *   sidebar "Users & Access"     ->  tab "Users"
 *
 * The first is the one a customer asked about, reasonably reading two
 * names as two features. "Management" also over-promised: WHICH modules a
 * tenant has is decided by the plan, and `updateModules()` can only toggle
 * keys already granted -- its own doc comment calls the boundary
 * "visibility/labeling only".
 *
 * Resolved toward the accurate name in each case: the sidebar took
 * "Module Visibility", and the tab took "Users & Access" because it holds
 * User Management AND Field & PTW Access.
 *
 * Scanned from source, like `LanguageHierarchyTest` and `CopyRulesTest`,
 * because the rule lives across two files and a comment asking the next
 * person to keep them in step is what failed here.
 */
class AdminSpaceNavigationTest extends TestCase
{
    /**
     * Every Admin Space row that opens a Settings tab, as
     * [tab value => sidebar label].
     *
     * @return array<string, string>
     */
    private function adminSpaceTabRows(): array
    {
        $source = file_get_contents(resource_path('js/lib/workspaces.js'));

        preg_match_all(
            "/\{\s*name:\s*'([^']+)',\s*href:\s*'settings\.index',\s*queryParams:\s*\{\s*tab:\s*'([^']+)'\s*\}/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $rows = [];

        foreach ($matches as $match) {
            $rows[$match[2]] = $match[1];
        }

        return $rows;
    }

    /**
     * Every Settings tab trigger, as [tab value => label].
     *
     * @return array<string, string>
     */
    private function settingsTabLabels(): array
    {
        $source = file_get_contents(resource_path('js/Pages/Settings/Index.jsx'));

        preg_match_all(
            '/<Tabs\.Trigger value="([a-z-]+)"[^>]*>(.*?)<\/Tabs\.Trigger>/s',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $labels = [];

        foreach ($matches as $match) {
            $labels[$match[1]] = html_entity_decode(trim($match[2]));
        }

        return $labels;
    }

    /** The deep links exist at all, so a regex that silently matched nothing would fail. */
    public function test_admin_space_deep_links_into_settings_tabs(): void
    {
        $rows = $this->adminSpaceTabRows();

        $this->assertNotEmpty($rows, 'Admin Space is expected to deep-link Settings tabs.');
        $this->assertArrayHasKey('modules', $rows, 'The module surface is reached from Admin Space.');
        $this->assertArrayHasKey('users', $rows);
    }

    /** THE DEFECT: one destination, two names. */
    public function test_every_admin_space_row_is_named_like_the_tab_it_opens(): void
    {
        $rows = $this->adminSpaceTabRows();
        $labels = $this->settingsTabLabels();

        $this->assertNotEmpty($labels, 'The Settings tab strip is expected to have triggers.');

        $mismatches = [];

        foreach ($rows as $tab => $sidebarLabel) {
            $this->assertArrayHasKey(
                $tab,
                $labels,
                "Admin Space links to Settings tab \"{$tab}\", which has no trigger."
            );

            if ($labels[$tab] !== $sidebarLabel) {
                $mismatches[] = "tab \"{$tab}\": sidebar says \"{$sidebarLabel}\", tab says \"{$labels[$tab]}\"";
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            "A menu item must read as the page it opens:\n".implode("\n", $mismatches)
        );
    }

    /**
     * The specific over-promise, named so it cannot come back under its old
     * label. Which modules a tenant HAS is a plan decision, not a toggle.
     */
    public function test_the_module_surface_does_not_promise_management(): void
    {
        $source = file_get_contents(resource_path('js/lib/workspaces.js'));

        $this->assertStringNotContainsString(
            "name: 'Module Management'",
            $source,
            'This surface governs visibility, not which modules the plan grants.'
        );
    }
}

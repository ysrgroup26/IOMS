<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * v2.71.0 -- SIDEBAR NAVIGATION BEHAVIOUR.
 *
 * THE SAME CAVEAT AS ApplicationShellTest, restated so nobody reads more
 * into these than they say. This repository has no JavaScript test runner
 * and none was added; the sidebar is React that never runs server-side,
 * so an HTTP test cannot see a single row of it. These are CONTRACT TESTS
 * over the shipped source. They pin the properties that were fixed, so an
 * edit that quietly reverts one fails here rather than in production.
 *
 * The behaviour itself was verified in a browser -- scrolling the rail to
 * the bottom, clicking the last item, and confirming the offset survives
 * the navigation -- and that is recorded in the release notes and in
 * [[Verification Status]]. A green test here is not a claim that it was
 * clicked.
 */
class NavigationBehaviourTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    private function layout(): string
    {
        return $this->source('resources/js/Layouts/AuthenticatedLayout.jsx');
    }

    /* ================================================================
     * 1. SCROLL POSITION SURVIVES NAVIGATION
     * ================================================================ */

    /**
     * The root cause, pinned. Every page wraps its own layout and nothing
     * uses Inertia's persistent-layout pattern, so the rail is remounted
     * on every navigation and its scroll container starts at zero. If that
     * ever changes -- if the app adopts `Page.layout` -- the scroll memory
     * becomes unnecessary and this test should be revisited rather than
     * silently left in place.
     */
    public function test_the_layout_is_still_remounted_per_page(): void
    {
        // Scanned in PHP rather than shelled out to grep: this suite runs on
        // Windows under Laragon as well as in CI, and a `shell_exec` that
        // cannot find grep returns null, which would make this assertion
        // pass by seeing nothing at all. That is the exact vacuous-coverage
        // failure documented in CONVENTIONS.md.
        $pages = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/js/Pages'), \FilesystemIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $persistent = [];

        foreach ($pages as $file) {
            if (strtolower($file->getExtension()) !== 'jsx') {
                continue;
            }

            $scanned++;

            if (preg_match('/^\s*\w+\.layout\s*=/m', file_get_contents($file->getPathname()))) {
                $persistent[] = $file->getFilename();
            }
        }

        $this->assertGreaterThan(100, $scanned, 'Precondition: the page scan must actually be reading files.');

        $this->assertSame(
            [],
            $persistent,
            'A page now uses Inertia persistent layouts. The sidebar scroll memory exists because the layout '
            .'remounts on every navigation; revisit it rather than keeping both mechanisms.'
        );
    }

    /** The scrollable container is the one the memory is attached to. */
    public function test_the_nav_scroll_container_carries_the_memory_ref(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('useScrollMemory(navScrollRef', $layout);
        $this->assertMatchesRegularExpression(
            '/<nav ref=\{navScrollRef\}[^>]*overflow-y-auto/',
            $layout,
            'The scroll memory must be attached to the element that actually scrolls.'
        );
    }

    /**
     * Restoring after paint produces a visible jump from the top to the
     * remembered offset on every navigation, which is more distracting
     * than the bug. `useLayoutEffect` runs before the browser paints.
     */
    public function test_the_offset_is_restored_before_paint(): void
    {
        $memory = $this->source('resources/js/lib/navigationMemory.js');

        $this->assertStringContainsString('useLayoutEffect', $memory);
        $this->assertStringContainsString('el.scrollTop = Math.min(', $memory, 'A restored offset must be clamped to the current list length.');
    }

    /**
     * v2.71.0 -- the offset must be persisted by work that runs whether or
     * not the page paints.
     *
     * The first implementation throttled the write through
     * `requestAnimationFrame`, which reads as ordinary scroll-handler
     * hygiene and silently disabled the whole feature in any tab that is
     * not painting -- rAF callbacks do not run there, so nothing was ever
     * stored. Caught in browser verification, pinned here.
     */
    public function test_the_offset_is_not_persisted_inside_an_animation_frame(): void
    {
        $memory = $this->source('resources/js/lib/navigationMemory.js');

        $write = substr($memory, strpos($memory, 'const onScroll'));

        $this->assertStringNotContainsString(
            'requestAnimationFrame',
            $write,
            'Persistence must not depend on the page painting -- a background tab would never store the offset.'
        );
    }

    /** Session-scoped, and never allowed to throw the shell down. */
    public function test_navigation_memory_degrades_safely(): void
    {
        $memory = $this->source('resources/js/lib/navigationMemory.js');

        $this->assertStringContainsString('window.sessionStorage', $memory);
        // `window.` qualified, so the word appearing in this file's own
        // reasoning prose does not fail the assertion about its code.
        $this->assertStringNotContainsString('window.localStorage', $memory, 'Navigation context belongs to the tab, not to the browser profile.');
        // Safari in private mode throws on access; an unguarded read here
        // would take the whole layout with it.
        $this->assertSame(2, substr_count($memory, 'try {'), 'Every storage access must be guarded.');
    }

    /** A different department renders a different list, so it gets its own memory. */
    public function test_the_memory_is_keyed_per_workspace(): void
    {
        $this->assertStringContainsString(
            "useScrollMemory(navScrollRef, activeWorkspace?.key ?? 'global'",
            $this->layout()
        );
    }

    /* ================================================================
     * 2. A ROUTE OWNED BY SEVERAL DEPARTMENTS
     * ================================================================ */

    /**
     * Man-Hour and Material Request appear in two departments' menus on
     * purpose. Collapsing that to one owner threw the user into whichever
     * workspace happened to be declared last in the file.
     */
    public function test_a_shared_route_records_every_owning_workspace(): void
    {
        $workspaces = $this->source('resources/js/lib/workspaces.js');

        $this->assertStringContainsString('PREFIX_TO_WORKSPACES', $workspaces);
        $this->assertStringContainsString('if (!owners.includes(workspaceKey)) owners.push(workspaceKey);', $workspaces);
        $this->assertStringContainsString(
            'return preferredKey && owners.includes(preferredKey) ? preferredKey : owners[0];',
            $workspaces,
            'A shared route must resolve in favour of the department the user is already in.'
        );
    }

    /** Material Request is declared in both departments that legitimately use it. */
    public function test_material_request_appears_in_both_owning_departments(): void
    {
        $workspaces = $this->source('resources/js/lib/workspaces.js');

        $this->assertSame(
            2,
            substr_count($workspaces, "href: 'material-requests.index'"),
            'Material Request should be reachable from HSE (which raises requests) and from Logistics / PPIC (which fulfils them).'
        );
    }

    /* ================================================================
     * 3. HIERARCHY: TWO LEVELS, NOT THREE SIZES
     * ================================================================ */

    /**
     * The inversion itself. A group header rendered at 11px uppercase in
     * navy-400 while its own children rendered at 13px -- the parent was
     * smaller than, and no brighter than, the things inside it.
     */
    public function test_a_group_header_is_not_smaller_than_its_children(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString(
            "text-[11px] font-medium text-navy-400 transition-all duration-150 hover:bg-white/[0.06]",
            $layout,
            'The group header must not render smaller and dimmer than the items it contains.'
        );

        $this->assertStringNotContainsString(
            '<span className="flex-1 text-left uppercase tracking-wide">{item.name}</span>',
            $layout,
            'A collapsible group is a control, not a caption -- it should not be uppercased into a label.'
        );
    }

    /** Both level-1 shapes -- plain link and group toggle -- share one type size. */
    public function test_every_top_level_row_shares_one_size(): void
    {
        $layout = $this->layout();

        $this->assertMatchesRegularExpression(
            '/onClick=\{\(\) => toggleMenu\(item\.name\)\}[\s\S]{0,400}?text-\[13px\] font-medium/',
            $layout,
            'A group header is a level-1 row and must use the level-1 type size.'
        );
    }

    /** The chevron's meaning reaches assistive technology, not only the eye. */
    public function test_the_group_toggle_reports_its_state(): void
    {
        $this->assertStringContainsString('aria-expanded={isExpanded}', $this->layout());
    }

    /** Entry items are separated by a rule, not by inventing a third type size. */
    public function test_entry_items_are_separated_structurally(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('function entryBoundary(items)', $layout);
        $this->assertStringContainsString('const dividerAfter = index === navEntryBoundary;', $layout);
    }

    /* ================================================================
     * 4. MASTER VS OPERATIONAL
     * ================================================================ */

    /** The product-wide cue lives on the shared header, so it scales past one page. */
    public function test_the_page_kind_cue_is_a_shared_concern(): void
    {
        $header = $this->source('resources/js/Components/shared/PageHeader.jsx');

        $this->assertStringContainsString('const KIND_CHIP = {', $header);

        foreach (['master', 'monitoring', 'administration'] as $kind) {
            $this->assertStringContainsString("{$kind}: {", $header);
        }

        $this->assertStringNotContainsString(
            'operational: {',
            $header,
            'Operational is the unlabelled default -- badging the majority of the product would make the badge furniture.'
        );
    }

    /**
     * Red is already spent on destructive-and-wrong throughout IOMS. Using
     * it for "this is configuration" would teach two meanings for one
     * colour and make a real error harder to notice.
     */
    public function test_the_kind_chip_does_not_borrow_the_danger_colour(): void
    {
        $header = $this->source('resources/js/Components/shared/PageHeader.jsx');

        // Only inspect the chip table, not the file's reasoning prose.
        $chips = substr($header, strpos($header, 'const KIND_CHIP = {'));
        $chips = substr($chips, 0, strpos($chips, '};'));

        foreach (['red-', 'danger', 'rose-'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $chips);
        }
    }

    /** HSE Control declares which of its tabs hold real units and which hold definitions. */
    public function test_the_hse_control_tabs_declare_their_kind(): void
    {
        $master = $this->source('resources/js/Pages/Hse/Master.jsx');

        $this->assertStringContainsString("kind: 'operational'", $master);
        $this->assertStringContainsString("kind: 'master'", $master);
        $this->assertStringContainsString('kind={active?.kind}', $master, 'The chip must follow the open tab -- this page genuinely contains both kinds.');
    }
}

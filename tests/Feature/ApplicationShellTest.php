<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * v2.67.0 -- THE APPLICATION SHELL.
 *
 * A NOTE ON WHAT THESE TESTS ARE, in the same spirit as
 * FormExperienceSystemTest. This repository has no JavaScript test runner
 * and none was added, and the shell is React that never executes
 * server-side -- an Inertia response is a JSON prop bag, so an HTTP test
 * cannot see a single element of the header or the rail. These are
 * therefore CONTRACT TESTS over the shipped source: they pin the
 * properties that were measured and fixed, so a later edit that quietly
 * reintroduces one fails here instead of in production.
 *
 * What was measured in a browser, against the real compiled stylesheet,
 * is recorded in the release notes: the header overflowed the page by
 * 265px at 640px, 137px at 768px and 89px at 1024px, and now overflows by
 * zero at every width from 320px to 1920px.
 */
class ApplicationShellTest extends TestCase
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
     * 1. THE OVERFLOW, AND THE RULE THAT PREVENTS THE NEXT ONE
     * ================================================================ */

    /**
     * The defect itself. A hard `w-[380px]` on a flex item with no
     * `min-w-0` cannot shrink, so from 640px to ~1140px the header was
     * simply wider than the page it sat in.
     */
    public function test_the_global_search_field_can_shrink(): void
    {
        $search = $this->source('resources/js/Components/shared/GlobalSearch.jsx');

        $this->assertStringNotContainsString(
            'h-[34px] w-[380px]',
            $search,
            'The search input is back to a hard width, which is what made every authenticated page scroll sideways.'
        );

        $this->assertStringContainsString('w-[380px] min-w-0', $search, '380px must be a basis it may shrink FROM, not a floor.');
        $this->assertStringContainsString('h-[34px] w-full', $search, 'The input must fill whatever width its wrapper settles on.');
    }

    /**
     * Why the overflow went unnoticed for three releases: flexbox's
     * default `shrink: 1` let EVERY control give way a little, so the
     * damage read as "cramped" rather than "137px too wide". Exactly one
     * item may now flex, which is what makes the result predictable.
     */
    public function test_every_fixed_header_control_refuses_to_shrink(): void
    {
        $layout = $this->layout();

        // The header runs from `<header aria-label="Application"` to the
        // end of the TopBar component.
        $start = strpos($layout, '<header');
        $end = strpos($layout, '</header>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $header = substr($layout, $start, $end - $start);

        foreach ([
            'Menu className',            // hamburger
            'CalendarDays className',     // Calendar
        ] as $marker) {
            $this->assertStringContainsString($marker, $header, "The header no longer contains {$marker}.");
        }

        // Controls written inline in the header element.
        foreach ([
            'shrink-0 rounded-md p-1 text-navy-300' => 'hamburger',
            'hidden h-8 shrink-0 items-center' => 'Dashboard link',
            'flex h-8 shrink-0 items-center' => 'Calendar link',
            'hidden shrink-0 text-xs text-navy-300' => 'clock',
            'max-w-[180px] shrink-0' => 'profile menu',
        ] as $needle => $control) {
            $this->assertStringContainsString($needle, $header, "The {$control} may shrink again.");
        }

        // Work Center and Notifications are rendered into the header but
        // declared as their own components further down the file, so they
        // are checked against the whole module rather than the slice.
        foreach ([
            'relative shrink-0 rounded-md p-2 text-graphite-400' => 'Work Center',
            'relative shrink-0 rounded-md p-2 text-navy-300' => 'Notifications',
        ] as $needle => $control) {
            $this->assertStringContainsString($needle, $layout, "The {$control} trigger may shrink again.");
        }
    }

    /**
     * The department label is the only unbounded string in the header --
     * a customer names their own departments -- so it must be allowed to
     * ellipse rather than force the bar wider.
     */
    public function test_the_department_label_truncates_rather_than_pushing(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('flex h-8 min-w-0 items-center gap-1.5 rounded-md border', $layout);
        $this->assertStringContainsString("<span className=\"truncate\">{activeWorkspace?.label ?? 'Department'}</span>", $layout);
    }

    /**
     * Dashboard was hidden below `sm` because MobileBottomNav duplicates
     * it -- correct reasoning, wrong breakpoint. The bottom nav renders to
     * `lg`, so the duplicate ran from 640px to 1023px, which is precisely
     * the band with no room to spare.
     */
    public function test_the_dashboard_link_hides_wherever_the_bottom_nav_duplicates_it(): void
    {
        $this->assertStringContainsString('hover:text-white lg:flex', $this->layout());

        $this->assertStringContainsString(
            'lg:hidden',
            $this->source('resources/js/Components/shared/MobileBottomNav.jsx'),
            'The bottom nav breakpoint moved; the Dashboard link above must move with it.'
        );
    }

    /** Below lg the field becomes an icon, because a field squeezed to 79px is not a field. */
    public function test_the_search_has_a_compact_presentation_where_there_is_no_room(): void
    {
        $search = $this->source('resources/js/Components/shared/GlobalSearch.jsx');

        $this->assertStringContainsString('relative hidden shrink-0 sm:block lg:hidden', $search, 'No compact (icon) presentation.');
        $this->assertStringContainsString('relative hidden w-[380px] min-w-0 lg:block', $search, 'No inline presentation.');
        $this->assertStringContainsString('aria-expanded={expanded}', $search);

        // Ctrl+K has to reach whichever of the two is actually rendered.
        $this->assertStringContainsString('offsetParent !== null', $search, 'Ctrl+K must find the field that is on screen.');
    }

    /* ================================================================
     * 2. THE DRAWER IS A DIALOG
     * ================================================================ */

    /**
     * It looked modal -- scrim, close button -- and behaved like ordinary
     * page content. All three of a dialog's obligations were missing.
     */
    public function test_the_focus_trap_delivers_all_three_dialog_behaviours(): void
    {
        $trap = $this->source('resources/js/lib/useFocusTrap.js');

        $this->assertStringContainsString('document.activeElement', $trap, 'ENTER: the opener must be captured.');
        $this->assertStringContainsString(".focus()", $trap);
        $this->assertStringContainsString("'Escape'", $trap, 'STAY: Escape must close it.');
        $this->assertStringContainsString("'Tab'", $trap, 'STAY: Tab must cycle inside it.');
        $this->assertStringContainsString('shiftKey', $trap, 'Shift+Tab must wrap backwards.');
        $this->assertStringContainsString('document.contains(opener)', $trap, 'RETURN: never focus a detached node.');
        $this->assertStringContainsString('container.contains(document.activeElement)', $trap, 'Focus that has already escaped must be recovered.');
    }

    /** The rail is a landmark on a desktop and a dialog on a phone. */
    public function test_the_rail_takes_dialog_semantics_only_while_it_is_modal(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString("useMediaQuery('(min-width: 1024px)')", $layout);
        $this->assertStringContainsString('const drawerOpen = sidebarOpen && ! isDesktop;', $layout);
        $this->assertStringContainsString("{...(drawerOpen ? { role: 'dialog', 'aria-modal': true } : {})}", $layout);
        $this->assertStringContainsString('useFocusTrap(drawerOpen', $layout);
    }

    /**
     * A closed drawer is moved off-screen with a TRANSFORM, so it is still
     * rendered and still in the tab order -- roughly twenty invisible
     * links between the header and the page on a phone.
     */
    public function test_a_closed_drawer_is_removed_from_the_tab_order(): void
    {
        $this->assertStringContainsString("el.setAttribute('inert', '')", $this->layout());
    }

    /**
     * The close control was a `<span onClick>` INSIDE the About button.
     * Interactive content nested in a button is invalid, and the practical
     * cost was that Close could not be reached from a keyboard at all.
     */
    public function test_the_drawer_close_control_is_a_real_button(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString(
            'onClick={(e) => { e.stopPropagation(); setSidebarOpen(false); }}',
            $layout,
            'The close control is nested inside the About button again.'
        );
        $this->assertStringContainsString('aria-label="Close navigation"', $layout);
    }

    /* ================================================================
     * 3. THE SHELL CAN BE OPERATED WITHOUT SIGHT OR A MOUSE
     * ================================================================ */

    /**
     * Without it, reaching page content means tabbing the whole rail and
     * header on EVERY navigation.
     */
    public function test_there_is_a_skip_link_and_something_for_it_to_reach(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('href="#main-content"', $layout);
        $this->assertStringContainsString('Skip to main content', $layout);
        $this->assertStringContainsString('sr-only focus:not-sr-only', $layout, 'It must be reachable but not permanently visible.');
        $this->assertStringContainsString('id="main-content" tabIndex={-1}', $layout, 'The target must accept focus, not only scroll.');
    }

    /**
     * `title` is a tooltip: unreliable to assistive technology and
     * invisible on touch. These were icon-only buttons announcing
     * themselves as "button".
     */
    public function test_every_icon_only_control_has_an_accessible_name(): void
    {
        $layout = $this->layout();

        foreach ([
            'aria-label="Open navigation"',
            'aria-label="Close navigation"',
            'aria-label="Switch department"',
        ] as $name) {
            $this->assertStringContainsString($name, $layout, "Missing accessible name: {$name}");
        }

        // These two carry a count, so they are built rather than literal.
        $this->assertStringContainsString('aria-label={total > 0', $layout, 'Work Center has no accessible name.');
        $this->assertStringContainsString('aria-label={badgeCount > 0', $layout, 'Notifications has no accessible name.');
        $this->assertStringContainsString('aria-label={`Account menu for', $layout, 'The profile menu has no accessible name.');

        $this->assertStringContainsString(
            'aria-label="Search IOMS"',
            $this->source('resources/js/Components/shared/GlobalSearch.jsx')
        );
    }

    /** Three navigation landmarks on one page; each needs to be distinguishable. */
    public function test_the_landmarks_are_named(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('aria-label="Application"', $layout, 'The header landmark is unnamed.');
        $this->assertStringContainsString('aria-label="Main navigation"', $layout, 'The rail is unnamed.');
        $this->assertStringContainsString('<nav aria-label="Workspace"', $layout, 'The workspace nav is unnamed.');
        $this->assertStringContainsString(
            'aria-label="Primary"',
            $this->source('resources/js/Components/shared/MobileBottomNav.jsx')
        );
    }

    /**
     * Active state was carried by weight, colour and an indicator bar --
     * all of it visual. v2.29.0 fixed "easy to miss at a glance" for
     * sighted users and left the same gap for everyone else.
     */
    public function test_the_current_page_is_reported_and_not_only_drawn(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString("aria-current={active ? 'page' : undefined}", $layout);
        $this->assertStringContainsString("aria-current={childActive ? 'page' : undefined}", $layout);
        $this->assertStringContainsString(
            "aria-current={active ? 'page' : undefined}",
            $this->source('resources/js/Components/shared/MobileBottomNav.jsx')
        );
    }

    /**
     * The scrim is decoration; announcing it as content puts an unlabelled
     * element between the header and the dialog.
     */
    public function test_the_scrim_is_hidden_from_assistive_technology(): void
    {
        $this->assertStringContainsString(
            "className=\"fixed inset-0 z-40 bg-graphite-900/30 lg:hidden\"\n                    onClick={() => setSidebarOpen(false)}\n                    aria-hidden=\"true\"",
            $this->layout()
        );
    }

    /* ================================================================
     * 4. NOTHING ELSE MOVED
     * ================================================================ */

    /**
     * This release touched only presentation. If it had also changed who
     * can see which navigation items, that would be a far more serious
     * change wearing a UX label -- the sidebar is built from
     * `getGlobalNavItems`/`getSelectableDepartments`, which are gated by
     * role and enabled modules server-side.
     */
    public function test_navigation_visibility_is_still_decided_the_same_way(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('getSelectableDepartments(auth?.user, enabledModules, workspaceCatalog)', $layout);
        $this->assertStringContainsString('getGlobalNavItems(auth?.user, enabledModules, workspaceCatalog)', $layout);
        $this->assertStringContainsString('isDepartmentUser', $layout);
    }

    /** The probe scaffolding used to measure this release must not have shipped. */
    public function test_no_measurement_scaffolding_was_left_in_the_repository(): void
    {
        foreach ([
            'public/_probe',
            'public/_trapprobe',
            'resources/js/_probe',
            'vite.probe.config.mjs',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "Measurement scaffolding was committed: {$path}");
        }
    }
}

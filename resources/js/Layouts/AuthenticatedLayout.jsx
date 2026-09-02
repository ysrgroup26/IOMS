import { Link, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    LogOut, Menu, X,
    Bell, User as UserIcon, ChevronDown, Sun, Moon, ChevronRight,
    ClipboardCheck, CheckSquare, HardHat, Inbox, Lock, LayoutDashboard, CalendarDays,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClock } from '@/lib/useClock';
import { useTheme, DARK_MODE_ENABLED } from '@/lib/useTheme';
import { getSelectableDepartments, getGlobalNavItems, getWorkspaceKeyForRoute, isDepartmentWorkspaceKey } from '@/lib/workspaces';
import AboutDialog from '@/Components/shared/AboutDialog';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import GlobalSearch from '@/Components/shared/GlobalSearch';
import MobileBottomNav from '@/Components/shared/MobileBottomNav';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent,
    DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator,
} from '@/Components/ui/dropdown-menu';

const EXPANDED_MENUS_KEY = 'ioms-sidebar-expanded';

/**
 * Whether a real (non-disabled) nav item matches the current URL --
 * shared by the sidebar's own highlighting and the breadcrumb's "is there
 * a multi-level match" check. Query-param-aware (v1.9.0): several
 * Administration items point at the same `settings.index` route with
 * different `?tab=`, so a bare path-prefix match would highlight
 * whichever of them appears first regardless of which tab is actually
 * open. An item with no `queryParams` (e.g. plain "Settings") is only
 * considered active when the URL carries no `tab` param at all --
 * otherwise it would out-rank the tab-specific items that follow it.
 */
function isItemActive(item, currentUrl) {
    if (!item.href || item.disabled) return false;
    const [path, query] = currentUrl.split('?');
    if (!path.startsWith('/' + item.href.split('.')[0])) return false;
    const currentTab = new URLSearchParams(query || '').get('tab');
    return item.queryParams?.tab ? item.queryParams.tab === currentTab : !currentTab;
}

/**
 * Exact pathname match by default -- several PPE children (dashboard/
 * employees/master/index) all start with the same `/ppe` segment, so a
 * naive prefix check would match all of them at once. The one exception:
 * "Employee PPE" should also stay active on an individual employee's PPE
 * profile page (a sub-page one level deeper).
 */
function isChildRouteActive(child, currentUrl) {
    try {
        const childPath = new URL(route(child.href)).pathname;
        return currentUrl === childPath
            || (child.href === 'ppe.employees' && currentUrl.startsWith(childPath + '/'));
    } catch {
        return currentUrl.startsWith('/' + child.href.split('.')[0]);
    }
}

/**
 * First real, department-OWNED item -- the switch-to target when
 * explicitly picking a department from the selector. Skips both disabled
 * placeholders and the repeated "Dashboard" link back to the Global
 * Dashboard (v1.10.2): picking a department should land you inside it
 * (its Overview), not immediately bounce you back out to the page you
 * were just trying to leave.
 */
function firstRealItem(workspace) {
    return workspace?.items.find((item) => item.href && !item.disabled && !item.global) ?? null;
}

export default function AuthenticatedLayout({ children }) {
    const { auth, company, version, modules, workspace_catalog: workspaceCatalog } = usePage().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [expandedMenus, setExpandedMenus] = useState(() => {
        if (typeof window === 'undefined') return [];
        try {
            return JSON.parse(window.localStorage.getItem(EXPANDED_MENUS_KEY)) || [];
        } catch {
            return [];
        }
    });

    function toggleMenu(name) {
        setExpandedMenus((prev) => {
            const next = prev.includes(name) ? prev.filter((n) => n !== name) : [...prev, name];
            window.localStorage.setItem(EXPANDED_MENUS_KEY, JSON.stringify(next));
            return next;
        });
    }
    const [aboutOpen, setAboutOpen] = useState(false);
    const currentUrl = usePage().url;

    const enabledModules = modules?.enabled ?? [];

    // Department User mechanism (v1.10.2) -- see User::isDepartmentUser()'s
    // own doc comment. false for every existing account today; true only
    // for a user an admin has deliberately assigned to one department.
    const isDepartmentUser = !!auth?.user?.is_department_user;

    // Administrators see every visible department; a Department User's
    // list collapses to just their one assigned department (or empty, if
    // their department_key doesn't match a real one -- a data-entry
    // mistake, not something to crash on).
    const selectableDepartments = getSelectableDepartments(auth?.user, enabledModules, workspaceCatalog);

    // Active department is DERIVED from the current route, never
    // persisted client-side state (v1.10.2) -- "no department currently
    // active" is a legitimate, first-class state (the sidebar shows
    // Global navigation then), not an edge case to paper over with a
    // remembered last choice. This matches the same "route is the only
    // source of truth" principle the rest of this navigation model
    // already follows.
    const routeWorkspaceKey = getWorkspaceKeyForRoute(route().current());
    const routeIsDepartment = isDepartmentWorkspaceKey(routeWorkspaceKey);

    const activeWorkspace = isDepartmentUser
        ? selectableDepartments[0]
        : (routeIsDepartment ? selectableDepartments.find((w) => w.key === routeWorkspaceKey) : undefined);

    // Department Users always see only their own department's sidebar, on
    // every page -- including the Global Dashboard itself -- never the
    // Administrator's "Global navigation" (Reports + Administration)
    // fallback, per "only their assigned Department should be available."
    // Administrators see the active department's items, or Global
    // navigation whenever no department is currently active.
    const visibleNav = isDepartmentUser
        ? (selectableDepartments[0]?.items ?? [])
        : (activeWorkspace?.items ?? getGlobalNavItems(auth?.user, enabledModules, workspaceCatalog));

    const activeNavItem = visibleNav.find((item) => isItemActive(item, currentUrl));
    // Only populated for a parent item that actually has children AND one
    // of them matches the current URL -- the one case the breadcrumb below
    // is allowed to render for (see Breadcrumb's own doc comment).
    const activeChild = activeNavItem?.children?.find((child) => isChildRouteActive(child, currentUrl)) ?? null;

    function switchWorkspace(workspace) {
        setSidebarOpen(false);
        const target = firstRealItem(workspace);
        if (target) {
            router.visit(route(target.href, target.queryParams));
        }
    }

    return (
        // v1.11.12 (Final Visual Design System pass): root was bg-white --
        // the spec explicitly distinguishes "Page Background: #F8FAFC"
        // from "Card Background: #FFFFFF", and every card already sits on
        // a white Card component (ui/card.jsx) -- with the page ALSO
        // white, cards had no background contrast to read against at all,
        // just borders/shadow doing all the separation work. graphite-50
        // is an exact hex match for #F8FAFC. Sidebar/TopBar keep their own
        // explicit bg-white below (the spec's own "Sidebar/Top Navigation
        // background: #FFFFFF") so they read as distinct raised surfaces
        // against this canvas, not the other way around.
        //
        // v2.29.0 (Authenticated UI Visual Transformation, Part 18:
        // "background/surfaces"). Flat graphite-50 was one plain neutral
        // everywhere -- no depth between "background," "workspace," and
        // "primary information." A barely-there blue tint (brand-50 at
        // very low opacity, fixed so it doesn't scroll with content) now
        // sits behind the workspace, establishing the BACKGROUND layer
        // the directive asks for without tinting the whole app blue --
        // white Cards and the white Sidebar/TopBar still read as the
        // brighter, primary surfaces on top of it.
        <div className="relative min-h-screen bg-graphite-50 dark:bg-slate-950">
            {/* v2.35.0 (Visual System + IA Refinement, Part 16 --
                "too white" was the single most-repeated production
                finding). The v2.29.0 tint only touched the top-left
                corner (`from-brand-50/50 via-transparent to-transparent`)
                -- most of a tall, scrolled page was still flat
                graphite-50, one step from plain white. A second layer
                now washes the BOTTOM of the viewport too (a soft
                slate/blue, not a repeat of the same top-left blue), so
                the workspace reads as a tinted canvas at any scroll
                position, not just at the very top. Still fixed
                (doesn't scroll, doesn't repaint), still low-opacity, still
                sits entirely behind white Cards/Sidebar/TopBar -- this is
                the BACKGROUND layer, not a new card treatment. */}
            <div className="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-br from-brand-50/50 via-transparent to-transparent dark:from-blue-950/20" aria-hidden="true" />
            <div className="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-tl from-slate-100/70 via-transparent to-transparent dark:from-slate-900/40" aria-hidden="true" />
            {/* Mobile overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 z-40 bg-graphite-900/30 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar -- navigation only; account actions (profile, logout)
                now live in the topbar's profile menu, matching enterprise
                SaaS convention (Linear/Notion/Vercel keep the sidebar
                focused purely on navigation). v1.6.2: width set to exactly
                240px per spec -- a narrower rail reads more confident with
                the logo now the dominant visual element. */}
            <aside
                className={cn(
                    // v1.11.12 (Final Visual Design System pass): width
                    // spec restates 240px -- the stale comment above (from
                    // v1.6.2) already claimed this value, but the actual
                    // class had drifted to 220px at some point without the
                    // comment being corrected. Fixed to match both the
                    // comment and the current spec.
                    'fixed inset-y-0 left-0 z-50 flex w-[240px] flex-col transform border-r border-graphite-200 bg-white transition-transform duration-200 ease-in-out dark:border-slate-800 dark:bg-slate-950 lg:translate-x-0',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                )}
            >
                {/* Logo/title: clickable to open the About dialog.
                    v1.6.7 Beta final balancing: the wordmark box (was
                    96px) has substantial internal vertical padding around
                    the visible "icms" ink -- the same asset property
                    already measured and compensated for horizontally
                    (see the note on the negative margin below). A smaller
                    box means proportionally less empty space to traverse
                    before the eye reaches actual content, which is what
                    "wordmark sits too low" / "block too tall" actually
                    described. Reduced to 72px and tightened container
                    padding on top of that, rather than only adjusting
                    padding around an unnecessarily large box. */}
                <button
                    onClick={() => setAboutOpen(true)}
                    className="flex shrink-0 items-start gap-2 border-b border-graphite-100 px-4 pb-1.5 pt-3 text-left transition-colors hover:bg-graphite-50/80 dark:border-slate-800 dark:hover:bg-slate-900/60"
                    title="About Integrated Operations Management System"
                >
                    <div className="min-w-0 flex-1 overflow-visible">
                        <BrandWordmark className="h-[72px] w-auto max-w-full -ml-[22px] object-contain" />
                        <p className="mt-0.5 text-[10px] font-medium uppercase leading-snug tracking-wide text-gray-500 dark:text-slate-500">{company?.subtitle || 'Industrial Operations Platform'}</p>
                    </div>
                    <span
                        className="ml-auto rounded p-1 text-graphite-300 hover:text-graphite-500 lg:hidden"
                        onClick={(e) => { e.stopPropagation(); setSidebarOpen(false); }}
                    >
                        <X className="h-5 w-5" />
                    </span>
                </button>

                <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 pb-3 pt-1.5">
                    {visibleNav.map((item) => {
                        const Icon = item.icon;

                        // Disabled (v1.9.0): a real department this platform
                        // is heading toward, but no route/controller/page
                        // exists for it yet -- rendered as a non-interactive,
                        // visibly muted row rather than a Link, so it's
                        // honest about not being clickable rather than
                        // linking to a fake/empty page.
                        if (item.disabled) {
                            return (
                                <div
                                    key={item.name}
                                    className="flex h-9 cursor-not-allowed items-center gap-2.5 rounded-[10px] px-3 text-[13px] font-medium text-graphite-300 dark:text-slate-600"
                                    title={`${item.name} -- coming soon`}
                                >
                                    <Icon className="h-4 w-4 shrink-0 text-graphite-200 dark:text-slate-700" />
                                    <span className="flex-1 truncate">{item.name}</span>
                                    <Lock className="h-3 w-3 shrink-0" />
                                </div>
                            );
                        }

                        const hasChildren = item.children?.length > 0;
                        // v1.11.7 (HSE Navigation Finalization): a group
                        // whose active state came only from localStorage
                        // toggle history meant landing directly on a
                        // grouped page (bookmark, reload, first visit)
                        // rendered its own active child inside a COLLAPSED
                        // group -- the breadcrumb still showed it (see
                        // `activeChild` above) but the sidebar itself gave
                        // no visible indication of where you were. Also
                        // auto-expanding whenever the active route is
                        // inside the group closes that gap without
                        // touching persisted state -- purely additive, and
                        // a no-op for every workspace that (still) has no
                        // `children` groups.
                        const containsActiveChild = hasChildren && item.children.some((child) => isChildRouteActive(child, currentUrl));
                        const isExpanded = expandedMenus.includes(item.name) || containsActiveChild;
                        const active = !hasChildren && isItemActive(item, currentUrl);

                        if (hasChildren) {
                            return (
                                <div key={item.name}>
                                    {/* v1.11.13 (reference-screenshot pass): the spec/reference
                                        draws a real distinction between "Sidebar Group Label"
                                        (11px/500) and "Sidebar Menu"/"Nested item" (both 13px/500)
                                        -- three separate categories, not one flat size. v1.11.11
                                        had collapsed group header and child down to the same 12px,
                                        which undid that distinction. Restored here: this group
                                        TOGGLE button is the "group label" (11px, muted, acts as an
                                        organizational label first and a button second); children
                                        below go back up to 13px as "nested item," matching plain
                                        leaf items' own "main menu" size exactly -- differentiated
                                        from their parent by being SMALLER-LABELED-parent/LARGER-
                                        item, not by the reverse. */}
                                    <button
                                        type="button"
                                        onClick={() => toggleMenu(item.name)}
                                        className="flex h-9 w-full items-center gap-2.5 rounded-[10px] px-3 text-[11px] font-medium text-graphite-500 transition-all duration-150 hover:bg-graphite-50 hover:text-graphite-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100"
                                    >
                                        <Icon className="h-4 w-4 shrink-0 text-graphite-400 dark:text-slate-500" />
                                        <span className="flex-1 text-left uppercase tracking-wide">{item.name}</span>
                                        <ChevronDown className={cn('h-3.5 w-3.5 shrink-0 text-graphite-400 transition-transform duration-200 dark:text-slate-500', isExpanded && 'rotate-180')} />
                                    </button>
                                    {/* CSS-grid expand/collapse -- same
                                        technique used for KPI Input's
                                        Selected Employees panel: animates
                                        to natural height with no JS
                                        measurement needed. */}
                                    {/* v1.11.13: nested item row height set to the spec's exact
                                        34px (h-[34px]); indentation ml-4/pl-2.5 kept (already
                                        within the 20-24px target measured from the row's own
                                        left edge). */}
                                    <div className={cn('grid transition-[grid-template-rows] duration-200 ease-in-out', isExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]')}>
                                        <div className="overflow-hidden">
                                            <div className="ml-4 mt-0.5 space-y-0.5 border-l border-graphite-100 pl-2.5 dark:border-slate-800">
                                                {item.children.map((child) => {
                                                    const childActive = isChildRouteActive(child, currentUrl);
                                                    const ChildIcon = child.icon;
                                                    return (
                                                        <Link
                                                            key={child.name}
                                                            href={route(child.href)}
                                                            // v2.16.0 (Global Mobile UX Hardening, Part 9):
                                                            // previously relied entirely on this whole layout
                                                            // remounting on navigation (each page wraps its own
                                                            // <AuthenticatedLayout>, resetting sidebarOpen's
                                                            // useState) to close the mobile drawer -- worked
                                                            // today, but fragile (breaks silently if this ever
                                                            // moves to Inertia's persistent-layout pattern).
                                                            // Explicit now, defensively.
                                                            onClick={() => setSidebarOpen(false)}
                                                            className={cn(
                                                                'flex h-[34px] items-center gap-2 rounded-lg px-2.5 text-[13px] leading-tight transition-colors duration-150',
                                                                childActive ? 'font-semibold text-brand-600 dark:text-brand-400' : 'font-normal text-graphite-500 hover:text-graphite-800 dark:text-slate-500 dark:hover:text-slate-200'
                                                            )}
                                                        >
                                                            {ChildIcon && <ChildIcon className="h-3.5 w-3.5 shrink-0" />}
                                                            <span className="truncate">{child.name}</span>
                                                        </Link>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        }

                        return (
                            <Link
                                key={item.name}
                                href={route(item.href, item.queryParams)}
                                onClick={() => setSidebarOpen(false)}
                                className={cn(
                                    // v1.11.13: bumped text-xs(12px) -> text-[13px], matching
                                    // this pass's "Main menu text: 13px/500" exactly.
                                    'relative flex h-9 items-center gap-2.5 rounded-[10px] px-3 text-[13px] font-medium transition-all duration-150',
                                    // v1.11.12: active text was text-brand-700 (#1D4ED8) --
                                    // spec's exact "Active text: #2563EB" is brand-600, one
                                    // shade lighter. Active background (bg-brand-50 = #EFF6FF)
                                    // and the active indicator bar (bg-brand-600, a few lines
                                    // below) already matched exactly.
                                    active
                                        ? 'bg-brand-50 font-semibold text-brand-600 dark:bg-brand-950/40 dark:text-brand-400'
                                        : 'text-graphite-600 hover:bg-graphite-50 hover:text-graphite-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100'
                                )}
                            >
                                {active && (
                                    <span className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand-600" />
                                )}
                                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-brand-600 dark:text-brand-400' : 'text-graphite-400 dark:text-slate-500')} />
                                {item.name}
                            </Link>
                        );
                    })}
                </nav>

                {/* App-info footer: small, always up to date via the
                    centralized version config -- never hardcoded. User
                    identity/role lives only in the topbar profile menu now
                    (v1.5.1 -- avoids showing the same account info twice). */}
                <div className="shrink-0 border-t border-graphite-100 px-4 py-2 text-center dark:border-slate-800">
                    <p className="text-[10px] text-graphite-400 dark:text-slate-500">&copy; {version?.copyright_year} IOMS Enterprise</p>
                    <p className="text-[10px] text-graphite-400 dark:text-slate-500">All Rights Reserved.</p>
                    <p className="mt-1 text-[10px] text-graphite-400 dark:text-slate-500">Designed &amp; Developed by {version?.company}</p>
                </div>
            </aside>

            <AboutDialog open={aboutOpen} onOpenChange={setAboutOpen} />

            {/* Main content */}
            {/* Must match the sidebar's own w-[240px] above -- a fixed sidebar needs an equal content offset or it overlaps. */}
            <div className="lg:pl-[240px]">
                <TopBar
                    onOpenSidebar={() => setSidebarOpen(true)}
                    isDepartmentUser={isDepartmentUser}
                    departments={selectableDepartments}
                    activeWorkspace={activeWorkspace}
                    onSwitchWorkspace={switchWorkspace}
                />

                <Breadcrumb activeItem={activeNavItem} activeChild={activeChild} />

                <FlashMessages />

                {/* v2.22.0 (Complete Product UI/UX Transformation, Part
                    9): bottom padding on mobile only, so the fixed
                    MobileBottomNav below never covers the last bit of
                    page content -- unchanged at lg: and up, where the
                    bottom nav doesn't render at all. */}
                <main className="p-5 pb-24 lg:p-8 lg:pb-8">{children}</main>
            </div>

            <MobileBottomNav visibleNav={visibleNav} currentUrl={currentUrl} onOpenMore={() => setSidebarOpen(true)} />
        </div>
    );
}

/**
 * Top Navigation Bar (v1.10.2). Order: Dashboard, Department Selector,
 * Global Search, Notifications, Profile -- Work Center stays too (it
 * predates this restructure and nothing asked for its removal), between
 * Search and Notifications. Dashboard is a permanently pinned link, not
 * part of the selector -- it's the Global Dashboard, not a department.
 * The Department Selector itself only renders for Administrators
 * (`!isDepartmentUser`); a Department User has exactly one department and
 * no way to switch, so there is nothing to render, not a disabled or
 * single-option version of the same control.
 */
function TopBar({ onOpenSidebar, isDepartmentUser, departments, activeWorkspace, onSwitchWorkspace }) {
    const { auth } = usePage().props;
    const now = useClock();
    const { theme, toggleTheme } = useTheme();

    return (
        <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-graphite-200 bg-white/80 px-5 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-950/80">
            <button className="lg:hidden" onClick={onOpenSidebar}>
                <Menu className="h-5 w-5 text-graphite-500" />
            </button>

            {/* Dashboard: the Global Dashboard, NOT a Department -- always
                pinned and visible for both Administrator and Department
                User experiences, reachable independent of whatever
                department (if any) is currently active. */}
            <Link
                href={route('dashboard')}
                className="flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-graphite-600 transition-colors hover:bg-graphite-50 dark:text-slate-300 dark:hover:bg-slate-800"
            >
                <LayoutDashboard className="h-3.5 w-3.5 shrink-0" />
                <span className="hidden sm:inline">Dashboard</span>
            </Link>

            {/* Calendar (v1.11.0): same "pinned, not a department" reasoning
                as Dashboard above -- it aggregates events across several
                departments by design (see CalendarController's own doc
                comment), so it isn't owned by any single one of them. */}
            <Link
                href={route('calendar.index')}
                className="flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-graphite-600 transition-colors hover:bg-graphite-50 dark:text-slate-300 dark:hover:bg-slate-800"
            >
                <CalendarDays className="h-3.5 w-3.5 shrink-0" />
                <span className="hidden sm:inline">Calendar</span>
            </Link>

            {/* Department Selector -- a single dropdown at every breakpoint,
                Departments only (Reports/Administration moved to the
                sidebar's Global navigation state, see workspaces.js).
                Administrators only. */}
            {!isDepartmentUser && (
                <DropdownMenu>
                    <DropdownMenuTrigger className="flex h-8 items-center gap-1.5 rounded-md border border-graphite-200 px-3 text-xs font-medium text-graphite-700 outline-none transition-colors hover:bg-graphite-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        {activeWorkspace && <activeWorkspace.icon className="h-3.5 w-3.5 shrink-0 text-graphite-400" />}
                        {activeWorkspace?.label ?? 'Department'}
                        <ChevronDown className="h-3.5 w-3.5 text-graphite-400" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="max-h-[70vh] overflow-y-auto">
                        <DropdownMenuLabel className="text-[10px] uppercase tracking-wide text-graphite-400">Departments</DropdownMenuLabel>
                        {departments.map((workspace) => (
                            <DropdownMenuItem key={workspace.key} onSelect={() => onSwitchWorkspace(workspace)}>
                                <workspace.icon className="h-4 w-4 text-graphite-400" /> {workspace.label}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            <div className="flex-1" />

            {/* Current Date/Time -- collapsed to a single compact line
                (v1.9.0) to reduce topbar clutter; still live via useClock(). */}
            <div className="hidden text-xs text-graphite-400 dark:text-slate-500 xl:block">
                {now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })} · {now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}
            </div>

            <div className="hidden h-5 w-px bg-graphite-200 xl:block" />

            {/* Global Search (v1.6.3) -- real search across Employees and Projects. */}
            <GlobalSearch />

            {DARK_MODE_ENABLED && (
                <button
                    onClick={toggleTheme}
                    className="rounded-md p-2 text-graphite-400 transition-colors hover:bg-graphite-100 hover:text-graphite-600"
                    title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                >
                    {theme === 'dark' ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
                </button>
            )}

            <WorkCenterMenu />
            <NotificationsMenu />

            {/* Profile menu: identity + About + Logout */}
            <DropdownMenu>
                <DropdownMenuTrigger className="flex items-center gap-2 rounded-lg py-1 pl-1 pr-2 outline-none transition-colors hover:bg-graphite-100 dark:hover:bg-slate-800">
                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-graphite-100 text-xs font-semibold text-graphite-600 dark:bg-slate-800 dark:text-slate-300">
                        {auth?.user?.name?.charAt(0)}
                    </div>
                    <span className="hidden flex-col items-start sm:flex">
                        <span className="text-sm font-medium leading-tight text-graphite-700 dark:text-slate-300">{auth?.user?.name?.split(' ')[0]}</span>
                        {/* Milestone 3 (UAT #1/#3/#7 -- identity clarity): the
                            role is now visible in the header itself, not just
                            inside this dropdown once opened -- "Administrator"
                            vs "HSE" vs "Manager" etc. should never require a
                            click to discover. */}
                        <span className="text-[10px] font-medium leading-tight text-brand-600 dark:text-brand-400">{auth?.user?.role_label}</span>
                    </span>
                    <ChevronDown className="hidden h-3.5 w-3.5 text-graphite-400 dark:text-slate-500 sm:block" />
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuLabel>{auth?.user?.name}</DropdownMenuLabel>
                    <p className="px-2.5 pb-1.5 text-xs text-graphite-400 dark:text-slate-500">{auth?.user?.role_label}</p>
                    <DropdownMenuSeparator />
                    {auth?.user?.is_admin && !isDepartmentUser && (
                        <DropdownMenuItem asChild>
                            <Link href={route('settings.index')} className="flex items-center gap-2">
                                <UserIcon className="h-4 w-4 text-graphite-400" /> Settings
                            </Link>
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={() => router.post(route('logout'))} className="text-red-600 focus:bg-red-50 focus:text-red-700">
                        <LogOut className="h-4 w-4" /> Log Out
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>
    );
}

/**
 * Work Center (v1.8.0, badge scope narrowed in v1.9.0). NOT a Department
 * -- a global, cross-cutting action center for work explicitly assigned
 * to the current user: pending Approvals and open Tasks. PPE Alerts moved
 * to the separate Notifications bell below (v1.9.0) -- Alerts are
 * system-detected conditions, not work assigned to a person, and the
 * split reads more honestly once both exist side by side.
 */
function WorkCenterMenu() {
    const { work_center: workCenter } = usePage().props;
    const approvalsCount = workCenter?.approvals_count ?? 0;
    const tasksCount = workCenter?.tasks_count ?? 0;
    const total = approvalsCount + tasksCount;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="relative rounded-md p-2 text-graphite-400 outline-none transition-colors hover:bg-graphite-100 hover:text-graphite-600 dark:hover:bg-slate-800" title="Work Center">
                <ClipboardCheck className="h-[18px] w-[18px]" />
                {total > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[9px] font-bold text-white">
                        {total > 99 ? '99+' : total}
                    </span>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>Work Center</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <WorkCenterRow icon={ClipboardCheck} label="Approvals" count={approvalsCount} />
                <WorkCenterRow icon={CheckSquare} label="Tasks" count={tasksCount} />
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={route('work-center.index')} className="justify-center text-center font-medium text-brand-700">
                        View Work Center
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function WorkCenterRow({ icon: Icon, label, count }) {
    return (
        <div className="flex items-center justify-between gap-3 px-2 py-1.5 text-sm">
            <span className="flex items-center gap-2 text-graphite-600 dark:text-slate-300">
                <Icon className="h-4 w-4 text-graphite-400" /> {label}
            </span>
            {count > 0 ? (
                <span className="rounded-full bg-graphite-100 px-1.5 py-0.5 text-[11px] font-semibold text-graphite-600 dark:bg-slate-800 dark:text-slate-300">{count}</span>
            ) : (
                <span className="text-xs text-graphite-300 dark:text-slate-600">
                    <Inbox className="h-3.5 w-3.5" />
                </span>
            )}
        </div>
    );
}

const NOTIFICATION_CATEGORY_DOT = {
    approval: 'bg-brand-500',
    reminder: 'bg-amber-500',
    warning: 'bg-red-500',
    success: 'bg-emerald-500',
    information: 'bg-graphite-400',
};

function relativeTime(isoString) {
    const diffMs = Date.now() - new Date(isoString).getTime();
    const minutes = Math.round(diffMs / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.round(hours / 24)}d ago`;
}

/**
 * Notification Center (v1.9.0 PPE-only badge; became genuinely real,
 * per-user, workflow-fired notifications in Milestone 3 -- see
 * App\Services\NotificationService, fired from
 * App\Concerns\HasWorkflow::transitionTo() and App\Services\ApprovalEngine).
 * PPE alerts (a live computed count, not a persisted row) stay pinned as
 * a quick link at the top rather than becoming fake Notification rows.
 * Deliberately separate from Work Center: this is "something happened or
 * needs looking at," Work Center is "something needs YOU to act."
 */
function NotificationsMenu() {
    const { notifications } = usePage().props;
    const ppeAlertCount = notifications?.ppe_alert_count ?? 0;
    const unreadCount = notifications?.unread_count ?? 0;
    const items = notifications?.items ?? [];
    const badgeCount = unreadCount + ppeAlertCount;

    function markRead(notification) {
        if (!notification.read_at) {
            router.put(route('notifications.read', notification.id), {}, { preserveScroll: true, preserveState: true });
        }
        if (notification.url) {
            router.visit(notification.url);
        }
    }

    function markAllRead(e) {
        e.preventDefault();
        router.put(route('notifications.read-all'), {}, { preserveScroll: true, preserveState: true });
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="relative rounded-md p-2 text-graphite-400 outline-none transition-colors hover:bg-graphite-100 hover:text-graphite-600 dark:hover:bg-slate-800"
                title={badgeCount > 0 ? `${badgeCount} notification(s)` : 'No notifications'}
            >
                <Bell className="h-[18px] w-[18px]" />
                {badgeCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-amber-500 px-1 text-[9px] font-bold text-white">
                        {badgeCount > 99 ? '99+' : badgeCount}
                    </span>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 max-h-[70vh] overflow-y-auto">
                <div className="flex items-center justify-between px-2 py-1.5">
                    <DropdownMenuLabel className="p-0 text-[10px] uppercase tracking-wide text-graphite-400">Notifications</DropdownMenuLabel>
                    {unreadCount > 0 && (
                        <button onClick={markAllRead} className="text-[11px] font-medium text-brand-600 hover:underline">
                            Mark all read
                        </button>
                    )}
                </div>
                <DropdownMenuSeparator />
                {ppeAlertCount > 0 && (
                    <DropdownMenuItem asChild>
                        <Link href={route('ppe.dashboard')} className="flex items-center gap-2">
                            <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                            <span className="text-sm">{ppeAlertCount} PPE item(s) need attention</span>
                        </Link>
                    </DropdownMenuItem>
                )}
                {items.length === 0 && ppeAlertCount === 0 && (
                    <div className="px-2 py-6 text-center text-sm text-graphite-400">No notifications</div>
                )}
                {items.map((item) => (
                    <DropdownMenuItem key={item.id} onSelect={() => markRead(item)} className="flex items-start gap-2">
                        <span className={cn('mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full', item.read_at ? 'bg-transparent' : (NOTIFICATION_CATEGORY_DOT[item.category] ?? 'bg-graphite-400'))} />
                        <div className="min-w-0 flex-1">
                            <p className={cn('truncate text-sm', item.read_at ? 'text-graphite-500' : 'font-medium text-graphite-800')}>{item.title}</p>
                            {item.body && <p className="truncate text-xs text-graphite-400">{item.body}</p>}
                            <p className="text-[10px] text-graphite-400">{relativeTime(item.created_at)}</p>
                        </div>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Deliberately NOT rendered for ordinary module-landing pages -- the
 * Department switcher already shows which department you're in, and the
 * page's own <h1> (PageHeader) already shows the page name, so a
 * "Department / Page Name" line there would just repeat both in smaller,
 * quieter text next to itself. It earns its place only when there's
 * genuinely a level the switcher + page title don't already cover: a
 * parent item's specific active child (e.g. a PPE sub-page). See the
 * Navigation Architecture ADR's "Breadcrumbs" section for the reasoning.
 */
function Breadcrumb({ activeItem, activeChild }) {
    if (!activeItem?.children?.length || !activeChild) return null;

    return (
        <div className="flex items-center gap-1.5 px-5 pt-3 text-xs text-graphite-400 dark:text-slate-500 lg:px-8">
            <span>{activeItem.name}</span>
            <ChevronRight className="h-3 w-3 shrink-0" />
            <span className="font-medium text-graphite-600 dark:text-slate-300">{activeChild.name}</span>
        </div>
    );
}

function FlashMessages() {
    const { flash } = usePage().props;
    const [dismissed, setDismissed] = useState(false);

    if (dismissed || (!flash?.success && !flash?.error)) return null;

    return (
        <div className="px-5 pt-4 lg:px-8">
            <div
                className={cn(
                    'flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm animate-fade-in',
                    flash.success ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'
                )}
            >
                <span>{flash.success || flash.error}</span>
                <button onClick={() => setDismissed(true)} className="ml-4 opacity-60 hover:opacity-100">
                    <X className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}

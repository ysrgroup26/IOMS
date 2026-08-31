import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Generic module top navigation (v1.6.7) -- extracted from the
 * PPE-specific version so it's actually reusable, not just
 * reusable-in-theory. A future module (Medical, Training, License,
 * Asset, Competency, Attendance) uses this directly:
 *
 *   <ModuleTabNav tabs={[
 *       { name: 'Dashboard', href: 'medical.dashboard' },
 *       { name: 'Employee Medical', href: 'medical.employees' },
 *   ]} />
 *
 * -- no copy-pasting or rewriting the component itself, only the tab
 * list differs per module. `PpeTabNav` (kept for backward compatibility
 * with the four existing PPE pages that already import it) is now a
 * thin wrapper around this with the PPE tabs hardcoded.
 *
 * v2.16.0 (Global Mobile UX Hardening pass). Root cause of PPE
 * Management's mobile screenshot regression (horizontal tab overflow
 * dragging the whole PAGE sideways): this row was `flex gap-1` with no
 * wrap and no scroll containment of its own -- with PPE's 6 tabs
 * ("Replacement Requests" alone is a long label), the row simply grew
 * past the viewport and `<main>` has no `overflow-x-hidden`, so the
 * entire page scrolled sideways with it. Every module using this
 * component (currently only PPE, but this fixes it for any future
 * module too) inherited the same bug for free.
 *
 * Fixed the way `PermitsToWork/MyIndex.jsx`'s existing chip-row already
 * does it correctly elsewhere in this codebase: the SCROLL belongs to
 * this component's own wrapper (`overflow-x-auto`), never the page.
 * Each tab gets `shrink-0 whitespace-nowrap` so a label wraps onto two
 * lines instead of squashing -- the row scrolls before any single tab's
 * text would compress. `-mx-1 px-1` widens the scrollable hit area
 * slightly past the tabs' own padding without changing the visual
 * alignment against the page's own left edge.
 */
export default function ModuleTabNav({ tabs }) {
    const { url } = usePage();

    return (
        <div className="mb-4 -mx-1 flex gap-1 overflow-x-auto border-b border-graphite-200 px-1 dark:border-slate-800">
            {tabs.map((tab) => {
                let active = false;
                try {
                    active = url.split('?')[0] === new URL(route(tab.href)).pathname;
                } catch {
                    active = false;
                }
                return (
                    <Link
                        key={tab.href}
                        href={route(tab.href)}
                        className={cn(
                            'flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-1.5 text-xs font-medium transition-colors',
                            active
                                ? 'border-brand-600 text-brand-700 dark:text-brand-400'
                                : 'border-transparent text-graphite-500 hover:border-graphite-300 hover:text-graphite-800 dark:text-slate-400 dark:hover:text-slate-200'
                        )}
                    >
                        {tab.name}
                        {/* v2.5.0 (Field HSE Experience pass, Part 11): optional
                            small pill so a config/admin-only tab (e.g. PPE
                            Master) reads visibly different in KIND from the
                            daily-operations tabs next to it, not just by RBAC
                            hiding its buttons once you're already there. */}
                        {tab.badge && (
                            <span className="rounded-full bg-graphite-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                                {tab.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
        </div>
    );
}

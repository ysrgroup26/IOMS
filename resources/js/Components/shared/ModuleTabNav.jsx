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
/**
 * v2.29.0 (Authenticated UI Visual Transformation, Part 8: "tabs must
 * feel alive"). Two real changes on top of the v2.16.0 overflow fix:
 *
 * 1. The active tab now gets a tinted surface (`bg-brand-50` pill), not
 *    just an underline -- reads as a genuine selected STATE, matching
 *    the sidebar's own active-item treatment, not merely "which link am
 *    I hovering."
 * 2. An optional `tab.meta` string renders as a small line under the
 *    tab's name (e.g. "147 total", "3 due") -- ONLY when a caller
 *    actually passes it, sourced from real page data already in that
 *    page's own props (see `PpeTabNav`'s wiring). No tab invents a
 *    count it wasn't given.
 */
export default function ModuleTabNav({ tabs }) {
    const { url } = usePage();

    return (
        <div className="chrome mb-4 flex w-fit max-w-full gap-1 overflow-x-auto rounded-2xl p-1 shadow-card">
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
                            'group flex shrink-0 flex-col items-start justify-center whitespace-nowrap rounded-xl px-3 py-1.5 outline-none transition-[background-color,box-shadow] duration-base ease-out-soft focus-visible:ring-4 focus-visible:ring-brand-500/15',
                            active
                                ? 'bg-white shadow-[0_1px_2px_0_rgba(30,64,120,0.08),0_6px_16px_-10px_rgba(36,112,221,0.45)] ring-1 ring-brand-100'
                                : 'hover:bg-white/60'
                        )}
                    >
                        <span className={cn(
                            'flex items-center gap-1.5 text-[13px] transition-colors',
                            active ? 'font-bold text-brand-700' : 'font-medium text-slate-500 group-hover:text-navy-900'
                        )}>
                            {tab.name}
                            {/* v2.5.0 (Field HSE Experience pass, Part 11): optional
                                small pill so a config/admin-only tab (e.g. PPE
                                Master) reads visibly different in KIND from the
                                daily-operations tabs next to it, not just by RBAC
                                hiding its buttons once you're already there. */}
                            {tab.badge && (
                                <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-slate-500">
                                    {tab.badge}
                                </span>
                            )}
                        </span>
                        {tab.meta && (
                            <span className={cn('text-[10px] leading-tight', active ? 'text-brand-500' : 'text-slate-400')}>
                                {tab.meta}
                            </span>
                        )}
                    </Link>
                );
            })}
        </div>
    );
}

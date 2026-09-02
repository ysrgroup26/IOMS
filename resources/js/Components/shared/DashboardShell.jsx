/**
 * v1.11.3 (Global Dashboard/Overview UX Rework, Part 7). A thin layout
 * wrapper so every dashboard/overview page shares the exact same
 * top-level structure (a header + a consistent vertical rhythm between
 * sections) without forcing identical content -- each department's
 * stat/module/calendar/activity content still varies, only the shell
 * around it is now literally the same component everywhere instead of
 * each page hand-rolling its own header + spacing.
 *
 * v2.36.0 (Visual System 2.0, Part 13: Department Overview hierarchy --
 * "WHAT IS THIS DOMAIN?"). This is THE highest-leverage fix in this
 * pass: every department Overview (HSE, Warehouse, Logistics,
 * Procurement, Project Management, Assets, Maintenance, Quality Control)
 * already composes its own page through this one shared shell -- it was
 * plain `PageHeader` (identical white-on-white weight to every other
 * page in the app), which is exactly why an Overview page could be
 * mistaken for "just another CRUD page" rather than a domain workspace.
 * Replaced with a genuine "domain summary surface": a soft steel/blue
 * gradient band (deliberately lighter/cooler than the main Dashboard's
 * deep-navy hero in Dashboard/Index.jsx -- Overview is a domain surface,
 * Dashboard is the strongest surface in the app, and the two should read
 * as related but NOT equal in visual weight, matching the
 * Dashboard-vs-Overview distinction this pass's directive insists on
 * preserving). Same title/subtitle/actions contract as before -- no
 * caller needs to change, every existing `<DashboardShell title=... />`
 * call gets this for free.
 *
 * Deliberately still dumb: no data-fetching, no department-specific
 * logic. Callers compose their own StatCard rows / ModuleCard grids /
 * DepartmentCalendarWidget / ActivityList inside it.
 *
 * Usage:
 *   <DashboardShell title="HSE Dashboard" subtitle="Operational HSE overview.">
 *       <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">...stat cards...</div>
 *       ...
 *   </DashboardShell>
 */
export default function DashboardShell({ title, subtitle, actions, children }) {
    return (
        <>
            <div className="mb-4 flex flex-col items-start gap-3 rounded-xl bg-gradient-to-br from-steel-50 via-brand-50/40 to-white px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
                <div className="min-w-0">
                    <h1 className="text-[19px] font-semibold leading-tight tracking-tight text-navy-900 dark:text-slate-50">{title}</h1>
                    {subtitle && <p className="mt-0.5 text-[13px] leading-snug text-graphite-600 dark:text-slate-400">{subtitle}</p>}
                </div>
                {actions && <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">{actions}</div>}
            </div>
            <div className="space-y-4">{children}</div>
        </>
    );
}

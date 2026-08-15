/**
 * Shared Page Header (v1.6.5 foundation, tightened v1.11.3 -- Global
 * Dashboard/Overview UX Rework Part 4). The title+subtitle(+actions)
 * pattern had three different type scales in simultaneous use across the
 * app (this component's own former `text-2xl`, PPE/Platform/Rosters'
 * hand-rolled `text-lg`, Main Dashboard's hand-rolled `text-base` hero) --
 * converged onto one `text-lg` scale here, and this pass is the one that
 * actually adopts it everywhere rather than deferring again (see
 * StatCard's own doc comment for the same "deferred adoption never
 * happens" lesson, now written up in docs/CONVENTIONS.md).
 *
 * Usage:
 *   <PageHeader title="Employees" subtitle="Manage your workforce">
 *       <Button>Add Employee</Button>
 *   </PageHeader>
 */
export default function PageHeader({ title, subtitle, children }) {
    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">{title}</h1>
                {subtitle && <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

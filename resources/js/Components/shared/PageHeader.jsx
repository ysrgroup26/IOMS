/**
 * Shared Page Header (v1.6.5 foundation, tightened v1.11.3, re-tuned
 * v1.11.9 -- Enterprise UI/UX Refinement Part 2). `text-lg` (18px) had
 * converged three competing scales onto one value, but v1.11.9's own
 * typography audit set the actual target for a page TITLE at ~24-28px --
 * `text-lg` was undersized relative to the rest of the density work this
 * pass does elsewhere (larger KPI numbers, tables), so the title ended up
 * reading smaller than a KPI number on the same screen, backwards from
 * the intended hierarchy. Bumped to `text-2xl` (24px); subtitle stays
 * small (`text-xs`, 12px -- already inside this pass's own "secondary
 * text 12-13px" guideline, no change needed there).
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
                <h1 className="text-2xl font-bold tracking-tight text-graphite-900 dark:text-slate-50">{title}</h1>
                {subtitle && <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

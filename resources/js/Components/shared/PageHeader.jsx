/**
 * Shared Page Header (v1.6.5 foundation). The title+subtitle(+actions)
 * pattern at the top of Dashboard, Employees, Projects, PPE, etc. has
 * been hand-rolled slightly differently on every page. This is the
 * canonical version for any *new* page going forward -- existing pages
 * were deliberately left as-is (each already works; swapping them over
 * is a separate, explicit redesign task, not bundled into this
 * foundation pass).
 *
 * Usage:
 *   <PageHeader title="Employees" subtitle="Manage your workforce">
 *       <Button>Add Employee</Button>
 *   </PageHeader>
 */
export default function PageHeader({ title, subtitle, children }) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 className="text-2xl font-bold tracking-tight text-graphite-900 dark:text-slate-50">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-graphite-500 dark:text-slate-400">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

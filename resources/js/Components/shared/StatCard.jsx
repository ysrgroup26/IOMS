import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Shared Statistic Card (v1.6.5 foundation, tightened v1.11.3 -- Global
 * Dashboard/Overview UX Rework Part 4). Dashboard's `PrimaryCard`, Home's
 * `StatCard`, and PPE Dashboard's `StatCard` were three separate
 * implementations of essentially the same icon+value+label card; this is
 * now the ONE version, adopted by every dashboard page in the same pass
 * that tightened it (see docs/CONVENTIONS.md -- the earlier "separate
 * follow-up" note here is exactly the kind of deferred adoption that
 * never happened, so this time the adoption isn't deferred).
 *
 * Sized to match `ModuleCard`'s own scale exactly (p-3.5, h-9/h-4 icon)
 * so KPI cards read as visually secondary to the page, not larger than
 * the module-shortcut grid beneath them.
 *
 * Usage:
 *   <StatCard icon={Users} value="128" label="Employees" href={route('employees.index')} />
 *   <StatCard icon={AlertTriangle} value="3" label="PPE Alerts" accent="amber" />
 *
 * v1.11.8 (Enterprise UI/UX Refinement, Part 8/23): `green`/`purple`
 * added alongside the existing `red`/`amber` -- the full semantic
 * palette the design system now documents (blue=primary/general,
 * green=healthy/active/completed, amber=warning/pending, red=critical/
 * overdue, purple=assets/administration/planning, neutral=inactive).
 * Colors communicate meaning, chosen per-metric by the caller -- this
 * component doesn't decide meaning for anyone, only renders it.
 */
export default function StatCard({ icon: Icon, value, label, href, accent }) {
    const accentClasses = {
        red: 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-400',
        amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400',
        green: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400',
        purple: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400',
        neutral: 'bg-graphite-100 text-graphite-500 dark:bg-slate-800 dark:text-slate-400',
    };
    const iconClass = accentClasses[accent] || 'bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400';

    // v1.11.9 (Enterprise UI/UX Refinement Part 2): value bumped from
    // text-base (16px) to text-xl (20px) -- the typography audit's own
    // target range for a KPI number is 20-26px, and 16px read as
    // undersized/flat next to the rest of this pass's density work.
    // Card footprint (p-3.5, h-9 icon) is UNCHANGED -- the number gets
    // more visual weight without the tile itself getting bigger.
    const content = (
        <Card className={cn('h-full rounded-2xl bg-white/85 backdrop-blur-sm transition-all duration-200 dark:bg-slate-900/85', href && 'hover:-translate-y-0.5 hover:shadow-card-hover')}>
            <CardContent className="flex items-center gap-3 p-3.5">
                <div className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', iconClass)}>
                    <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0">
                    <p className="truncate text-xl font-bold leading-tight text-graphite-900 dark:text-slate-50">{value}</p>
                    <p className="truncate text-[11px] font-medium uppercase tracking-wide text-graphite-400 dark:text-slate-500">{label}</p>
                </div>
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
